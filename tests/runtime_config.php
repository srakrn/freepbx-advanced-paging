<?php

require_once __DIR__ . '/../src/RuntimeConfig.php';

use AdvancedPaging\RuntimeConfig;

$dir = sys_get_temp_dir() . '/ap-config-test-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
$path = $dir . '/runtime.ini';
try {
    file_put_contents($path, "host=127.0.0.1\nport=5038\nusername=aptest\nsecret=private-test-secret\n");
    chmod($path, 0600);
    $config = RuntimeConfig::read($path);
    if ($config['host'] !== '127.0.0.1' || $config['port'] !== 5038 || $config['username'] !== 'aptest') {
        throw new RuntimeException('Runtime configuration was not parsed.');
    }
    chmod($path, 0644);
    clearstatcache(true, $path);
    try {
        RuntimeConfig::read($path);
        throw new RuntimeException('World-readable secret was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'World-readable secret was accepted.') {
            throw $error;
        }
    }
    chmod($path, 0600);
    file_put_contents($path, "host=example.com\nport=5038\nusername=aptest\nsecret=private-test-secret\n");
    clearstatcache(true, $path);
    try {
        RuntimeConfig::read($path);
        throw new RuntimeException('Non-loopback manager was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Non-loopback manager was accepted.') {
            throw $error;
        }
    }
    symlink($path, $dir . '/link.ini');
    try {
        RuntimeConfig::read($dir . '/link.ini');
        throw new RuntimeException('Symlink credential file was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Symlink credential file was accepted.') {
            throw $error;
        }
    }
    echo "Protected runtime configuration tests passed.\n";
} finally {
    if (is_link($dir . '/link.ini')) {
        unlink($dir . '/link.ini');
    }
    if (file_exists($path)) {
        unlink($path);
    }
    rmdir($dir);
}
