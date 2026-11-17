<?php

require_once __DIR__ . '/../src/GroupLock.php';

use AdvancedPaging\GroupLock;

if (($argv[1] ?? '') === '--child') {
    $lock = GroupLock::tryAcquire($argv[2], (int) $argv[3]);
    exit($lock === null ? 2 : 0);
}

function checkLock(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

$dir = sys_get_temp_dir() . '/ap-lock-test-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('Could not create test directory.');
}
try {
    $first = GroupLock::tryAcquire($dir, 1);
    checkLock($first !== null, 'First acquisition failed.');
    checkLock(GroupLock::tryAcquire($dir, 1) === null, 'Same group lock was not exclusive.');
    $other = GroupLock::tryAcquire($dir, 2);
    checkLock($other !== null, 'Different groups should operate concurrently.');

    $process = proc_open([PHP_BINARY, __FILE__, '--child', $dir, '1'], [], $pipes);
    checkLock(is_resource($process) && proc_close($process) === 2, 'Cross-process lock was not exclusive.');
    $other->release();
    $first->release();
    $process = proc_open([PHP_BINARY, __FILE__, '--child', $dir, '1'], [], $pipes);
    checkLock(is_resource($process) && proc_close($process) === 0, 'Released lock was not available.');
    symlink($dir . '/group-1.lock', $dir . '/group-3.lock');
    try {
        GroupLock::tryAcquire($dir, 3);
        throw new RuntimeException('Symlink lock was accepted.');
    } catch (RuntimeException $error) {
        checkLock($error->getMessage() === 'Group lock file must not be a symlink.', 'Unexpected symlink error.');
    }
    echo "Group lock concurrency tests passed.\n";
} finally {
    foreach (['group-1.lock', 'group-2.lock', 'group-3.lock'] as $name) {
        if (file_exists($dir . '/' . $name) || is_link($dir . '/' . $name)) {
            unlink($dir . '/' . $name);
        }
    }
    rmdir($dir);
}
