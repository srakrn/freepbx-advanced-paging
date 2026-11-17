<?php

namespace AdvancedPaging;

use InvalidArgumentException;
use RuntimeException;

/**
 * Process-held, nonblocking group lock. Lock files are deliberately not
 * unlinked: removing one permits a second process to lock a different inode.
 *
 * The lock directory must already exist, be owned by the runtime user, and
 * not be writable by any other user. A crash releases the OS lock but does
 * not clean up Asterisk channels; the runtime must reconcile those first.
 */
final class GroupLock
{
    private $handle;
    private int $groupId;

    private function __construct($handle, int $groupId)
    {
        $this->handle = $handle;
        $this->groupId = $groupId;
    }

    public static function tryAcquire(string $directory, int $groupId): ?self
    {
        if ($groupId < 1) {
            throw new InvalidArgumentException('Invalid group ID.');
        }
        return self::acquire($directory, 'group-' . $groupId . '.lock', $groupId);
    }

    /** Dedicated process-wide lock; not in the numeric group lock namespace. */
    public static function tryAcquireRuntime(string $directory): ?self
    {
        return self::acquire($directory, 'runtime.lock', 0);
    }

    private static function acquire(string $directory, string $filename, int $groupId): ?self
    {
        $dir = realpath($directory);
        $info = $dir === false ? false : stat($dir);
        if ($info === false || !is_dir($dir) || ($info['mode'] & 0022) !== 0
            || (function_exists('posix_geteuid') && $info['uid'] !== posix_geteuid())) {
            throw new RuntimeException('Group lock directory is not private and owned by the runtime user.');
        }
        $path = $dir . '/' . $filename;
        if (is_link($path)) {
            throw new RuntimeException('Group lock file must not be a symlink.');
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open group lock.');
        }
        $file = fstat($handle);
        clearstatcache(true, $path);
        $entry = lstat($path);
        if ($file === false || $entry === false || ($file['mode'] & 0170000) !== 0100000
            || $file['dev'] !== $entry['dev'] || $file['ino'] !== $entry['ino']) {
            fclose($handle);
            throw new RuntimeException('Invalid group lock file.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return new self($handle, $groupId);
    }

    public function holds(int $groupId): bool
    {
        return $this->handle !== null && $this->groupId === $groupId;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
