<?php

require_once __DIR__ . '/../src/RuntimeProvisioner.php';
require_once __DIR__ . '/../src/RuntimeConfig.php';

use AdvancedPaging\RuntimeConfig;
use AdvancedPaging\RuntimeProvisioner;

function verifyProvisioner(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/ap-provision-test-' . bin2hex(random_bytes(8));
$etc = $root . '/asterisk';
$run = $root . '/run';
mkdir($etc, 0700, true);
mkdir($run, 0700, true);
$custom = $etc . '/manager_custom.conf';
file_put_contents($custom, "; existing administrator configuration\n[admin-tool]\nread=system\n");
chmod($custom, 0640);
$provisioner = new RuntimeProvisioner($etc, $run, $custom, $etc . '/lock', null, null);
try {
    $provisioner->install();
    $manager = file_get_contents($etc . '/advancedpaging_manager.conf');
    $runtime = file_get_contents($etc . '/advancedpaging-runtime.ini');
    verifyProvisioner(strpos($manager, '[advancedpaging-runtime]') === 0, 'Manager user missing.');
    verifyProvisioner(strpos($manager, 'permit=127.0.0.1/255.255.255.255') !== false, 'AMI loopback restriction missing.');
    verifyProvisioner(strpos($manager, 'permit=::1/128') !== false, 'AMI IPv6 loopback restriction missing.');
    verifyProvisioner(strpos($manager, 'write=system,call,originate,user,reporting') !== false, 'AMI privileges missing.');
    $parsed = RuntimeConfig::read($etc . '/advancedpaging-runtime.ini');
    verifyProvisioner($parsed['username'] === 'advancedpaging-runtime'
        && hash_equals($parsed['secret'], trim(substr($manager, strlen('[advancedpaging-runtime]' . "\nsecret="), 64))),
        'Runtime and AMI credentials differ.');
    verifyProvisioner((fileperms($etc . '/advancedpaging-runtime.ini') & 0027) === 0, 'Runtime secret is not private.');
    verifyProvisioner(strpos(file_get_contents($custom), '; existing administrator configuration') === 0, 'Existing custom config was changed.');
    $before = file_get_contents($custom);
    $provisioner->install();
    verifyProvisioner(file_get_contents($custom) === $before, 'Install is not idempotent.');
    $provisioner->uninstall();
    verifyProvisioner(file_get_contents($custom) === "; existing administrator configuration\n[admin-tool]\nread=system\n",
        'Uninstall did not preserve administrator config.');
    verifyProvisioner(!file_exists($etc . '/advancedpaging_manager.conf')
        && !file_exists($etc . '/advancedpaging-runtime.ini'), 'Uninstall left managed credentials.');

    $provisioner->install();
    file_put_contents($etc . '/advancedpaging_manager.conf', "[admin]\nsecret=changed\n");
    try {
        $provisioner->uninstall();
        throw new RuntimeException('Modified manager include was removed.');
    } catch (RuntimeException $error) {
        verifyProvisioner($error->getMessage() === 'Advanced Paging manager include has been modified.',
            'Unexpected modified-file error.');
    }
    verifyProvisioner(file_exists($etc . '/advancedpaging-runtime.ini')
        && strpos(file_get_contents($custom), 'BEGIN ADVANCEDPAGING MANAGED') !== false,
        'Refused uninstall nevertheless changed provisioned files.');
    echo "Runtime AMI provision/install/idempotence/uninstall protection tests passed.\n";
} finally {
    foreach (glob($etc . '/*') as $path) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
    foreach (glob($etc . '/.*') as $path) {
        if (is_file($path) || is_link($path)) {
            unlink($path);
        }
    }
    rmdir($etc);
    rmdir($run);
    rmdir($root);
}
