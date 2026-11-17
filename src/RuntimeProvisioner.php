<?php

namespace AdvancedPaging;

use RuntimeException;

/**
 * Idempotently provisions only module-owned AMI and runtime credential files.
 * Paths are injectable for tests. The manager_custom include is bracketed,
 * preserved byte-for-byte outside its marker block, and never replaces a
 * symlink or an unrelated credential/configuration file.
 */
final class RuntimeProvisioner
{
    private string $asteriskDirectory;
    private string $runtimeDirectory;
    private string $managerCustom;
    private string $runtimeConfig;
    private string $managerInclude;
    private string $lockPath;
    private ?string $ownerUser;
    private ?string $ownerGroup;
    private int $port;

    public function __construct(
        string $asteriskDirectory,
        string $runtimeDirectory,
        ?string $managerCustom = null,
        ?string $lockPath = null,
        ?string $ownerUser = 'asterisk',
        ?string $ownerGroup = 'asterisk',
        int $port = 5038
    ) {
        $this->asteriskDirectory = rtrim($asteriskDirectory, '/');
        $this->runtimeDirectory = rtrim($runtimeDirectory, '/');
        $this->managerCustom = $managerCustom ?? $this->asteriskDirectory . '/manager_custom.conf';
        $this->managerInclude = $this->asteriskDirectory . '/advancedpaging_manager.conf';
        $this->runtimeConfig = $this->asteriskDirectory . '/advancedpaging-runtime.ini';
        $this->lockPath = $lockPath ?? $this->asteriskDirectory . '/.advancedpaging-lifecycle.lock';
        $this->ownerUser = $ownerUser;
        $this->ownerGroup = $ownerGroup;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('Invalid AMI port.');
        }
        $this->port = $port;
    }

    public function install(): void
    {
        $this->withLock(function (): void {
            $this->assertSafeDirectory($this->asteriskDirectory);
            $this->assertSafeDirectory($this->runtimeDirectory);
            $managerText = $this->readRegularFile($this->managerCustom, true);
            $includeText = $this->readRegularFile($this->managerInclude, true);
            $runtimeText = $this->readRegularFile($this->runtimeConfig, true);
            $customStat = file_exists($this->managerCustom) ? stat($this->managerCustom) : null;
            $managed = $this->extractBlock($managerText);

            if ($managed !== null) {
                if ($includeText === null || $runtimeText === null) {
                    throw new RuntimeException('Advanced Paging AMI provision is incomplete; refusing to replace it.');
                }
                $managerSecret = $this->validateManagedInclude($includeText);
                $runtimeSecret = $this->validateManagedRuntime($runtimeText);
                if (!hash_equals($managerSecret, $runtimeSecret)) {
                    throw new RuntimeException('Advanced Paging manager and runtime credentials do not match.');
                }
                return;
            }
            if ($includeText !== null || $runtimeText !== null) {
                throw new RuntimeException('Advanced Paging credential paths already exist; refusing to overwrite.');
            }

            $secret = bin2hex(random_bytes(32));
            $custom = $managerText ?? '';
            if ($custom !== '' && substr($custom, -1) !== "\n") {
                $custom .= "\n";
            }
            $custom .= $this->markerBlock();
            $manager = "[advancedpaging-runtime]\n"
                . "secret={$secret}\n"
                . "deny=0.0.0.0/0.0.0.0\npermit=127.0.0.1/255.255.255.255\n"
                . "deny=::/0\npermit=::1/128\n"
                . "read=system,call,reporting,user\n"
                . "write=system,call,originate,user,reporting\n";
            $runtime = "host=127.0.0.1\nport={$this->port}\nusername=advancedpaging-runtime\nsecret={$secret}\n";

            $this->writeAtomic($this->managerInclude, $manager, 0640);
            try {
                $this->writeAtomic($this->runtimeConfig, $runtime, 0640);
                $this->writeAtomic($this->managerCustom, $custom, 0640, $customStat);
            } catch (\Throwable $error) {
                $this->removeOwnedFile($this->runtimeConfig);
                $this->removeOwnedFile($this->managerInclude);
                throw $error;
            }
        });
    }

    public function uninstall(): void
    {
        $this->withLock(function (): void {
            $managerText = $this->readRegularFile($this->managerCustom, true);
            $includeText = $this->readRegularFile($this->managerInclude, true);
            $runtimeText = $this->readRegularFile($this->runtimeConfig, true);
            $block = $this->extractBlock($managerText);
            if ($block === null) {
                if ($includeText === null && $runtimeText === null) {
                    return;
                }
                throw new RuntimeException('Advanced Paging credential ownership cannot be verified.');
            }
            if ($includeText === null || $runtimeText === null) {
                throw new RuntimeException('Advanced Paging AMI provision is incomplete; refusing destructive cleanup.');
            }
            $managerSecret = $this->validateManagedInclude($includeText);
            $runtimeSecret = $this->validateManagedRuntime($runtimeText);
            if (!hash_equals($managerSecret, $runtimeSecret)) {
                throw new RuntimeException('Advanced Paging manager and runtime credentials do not match.');
            }
            $remaining = str_replace($block, '', $managerText);
            $remaining = preg_replace('/\n{3,}/', "\n\n", $remaining);
            $stat = stat($this->managerCustom);
            $this->writeAtomic($this->managerCustom, $remaining, 0640, $stat);
            $this->removeOwnedFile($this->runtimeConfig);
            $this->removeOwnedFile($this->managerInclude);
        });
    }

    private function markerBlock(): string
    {
        return "; BEGIN ADVANCEDPAGING MANAGED\n#include advancedpaging_manager.conf\n; END ADVANCEDPAGING MANAGED\n";
    }

    private function extractBlock(?string $contents): ?string
    {
        if ($contents === null) {
            return null;
        }
        if (preg_match_all('/^; BEGIN ADVANCEDPAGING MANAGED\n.*?^; END ADVANCEDPAGING MANAGED\n?/ms', $contents, $matches) > 1) {
            throw new RuntimeException('Multiple Advanced Paging include blocks found.');
        }
        return $matches[0][0] ?? null;
    }

    private function validateManagedInclude(string $contents): string
    {
        $pattern = '/^\[advancedpaging-runtime\]\n'
            . 'secret=([a-f0-9]{64})\n'
            . 'deny=0\.0\.0\.0\/0\.0\.0\.0\npermit=127\.0\.0\.1\/255\.255\.255\.255\n'
            . 'deny=::\/0\npermit=::1\/128\n'
            . 'read=system,call,reporting,user\n'
            . 'write=system,call,originate,user,reporting\n?$/D';
        if (!preg_match($pattern, $contents, $match)) {
            throw new RuntimeException('Advanced Paging manager include has been modified.');
        }
        return $match[1];
    }

    private function validateManagedRuntime(string $contents): string
    {
        if (!preg_match('/^host=127\.0\.0\.1\nport=(?:[1-9][0-9]{0,4})\nusername=advancedpaging-runtime\nsecret=([a-f0-9]{64})\n?$/D', $contents, $match)) {
            throw new RuntimeException('Advanced Paging runtime credentials have been modified.');
        }
        return $match[1];
    }

    private function readRegularFile(string $path, bool $allowMissing): ?string
    {
        clearstatcache(true, $path);
        if (is_link($path)) {
            throw new RuntimeException('Refusing symlink in Advanced Paging lifecycle path.');
        }
        if (!file_exists($path)) {
            if ($allowMissing) {
                return null;
            }
            throw new RuntimeException('Required lifecycle file is missing.');
        }
        $info = lstat($path);
        if ($info === false || ($info['mode'] & 0170000) !== 0100000 || $info['size'] > 65536) {
            throw new RuntimeException('Invalid Advanced Paging lifecycle file.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read Advanced Paging lifecycle file.');
        }
        return $contents;
    }

    private function writeAtomic(string $path, string $contents, int $mode, ?array $existingStat = null): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Refusing symlink in Advanced Paging lifecycle path.');
        }
        $temporary = $path . '.advancedpaging-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('Cannot create Advanced Paging lifecycle file.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException('Cannot write Advanced Paging lifecycle file.');
            }
            if (!chmod($temporary, $mode)) {
                throw new RuntimeException('Cannot set Advanced Paging lifecycle file permissions.');
            }
            if ($existingStat !== null) {
                $this->setOwnership($temporary, (int) $existingStat['uid'], (int) $existingStat['gid']);
                if (!chmod($temporary, (int) $existingStat['mode'] & 0777)) {
                    throw new RuntimeException('Cannot preserve Asterisk manager configuration mode.');
                }
            } else {
                $this->setOwner($temporary);
            }
        } catch (\Throwable $error) {
            fclose($handle);
            unlink($temporary);
            throw $error;
        }
        fclose($handle);
        if (!rename($temporary, $path)) {
            unlink($temporary);
            throw new RuntimeException('Cannot install Advanced Paging lifecycle file.');
        }
    }

    private function setOwner(string $path): void
    {
        if ($this->ownerUser === null && $this->ownerGroup === null) {
            return;
        }
        if (!function_exists('posix_getpwnam') || !function_exists('posix_getgrnam')) {
            throw new RuntimeException('POSIX ownership functions are required for runtime credentials.');
        }
        $user = $this->ownerUser === null ? null : posix_getpwnam($this->ownerUser);
        $group = $this->ownerGroup === null ? null : posix_getgrnam($this->ownerGroup);
        if (($this->ownerUser !== null && $user === false) || ($this->ownerGroup !== null && $group === false)) {
            throw new RuntimeException('Asterisk service account was not found.');
        }
        $this->setOwnership(
            $path,
            $user === null || $user === false ? null : (int) $user['uid'],
            $group === null || $group === false ? null : (int) $group['gid']
        );
    }

    private function setOwnership(string $path, ?int $uid, ?int $gid): void
    {
        $stat = stat($path);
        if ($stat === false) {
            throw new RuntimeException('Cannot read lifecycle file ownership.');
        }
        $currentUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $currentGid = function_exists('posix_getegid') ? posix_getegid() : null;
        if ($uid !== null && $stat['uid'] !== $uid
            && ($currentUid === 0 || $currentUid === null || $currentUid === $stat['uid'])) {
            if (!chown($path, $uid)) {
                throw new RuntimeException('Cannot set Advanced Paging lifecycle file owner.');
            }
        } elseif ($uid !== null && $stat['uid'] !== $uid) {
            throw new RuntimeException('Cannot change lifecycle file owner without privilege.');
        }
        $stat = stat($path);
        if ($gid !== null && $stat['gid'] !== $gid
            && ($currentUid === 0 || $currentGid === $stat['gid'])) {
            if (!chgrp($path, $gid)) {
                throw new RuntimeException('Cannot set Advanced Paging lifecycle file group.');
            }
        } elseif ($gid !== null && $stat['gid'] !== $gid) {
            throw new RuntimeException('Cannot change lifecycle file group without privilege.');
        }
    }

    private function removeOwnedFile(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Refusing to remove non-regular lifecycle path.');
        }
        if (!unlink($path)) {
            throw new RuntimeException('Cannot remove Advanced Paging lifecycle file.');
        }
    }

    private function assertSafeDirectory(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_dir($path)) {
            throw new RuntimeException('Invalid Advanced Paging lifecycle directory.');
        }
    }

    private function withLock(callable $operation): void
    {
        if (is_link($this->lockPath)) {
            throw new RuntimeException('Refusing symlink lifecycle lock.');
        }
        $handle = fopen($this->lockPath, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cannot lock Advanced Paging lifecycle.');
        }
        try {
            $opened = fstat($handle);
            clearstatcache(true, $this->lockPath);
            $pathInfo = lstat($this->lockPath);
            if ($opened === false || $pathInfo === false
                || ($opened['mode'] & 0170000) !== 0100000
                || $opened['dev'] !== $pathInfo['dev'] || $opened['ino'] !== $pathInfo['ino']) {
                throw new RuntimeException('Invalid lifecycle lock file.');
            }
            $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
