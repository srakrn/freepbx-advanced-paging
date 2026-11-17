<?php

foreach (['SessionController', 'AsteriskEffects', 'AmiConnection', 'GroupLock', 'CrashRecovery'] as $file) {
    require_once __DIR__ . '/../src/' . $file . '.php';
}

use AdvancedPaging\AmiConnection;
use AdvancedPaging\CrashRecovery;
use AdvancedPaging\GroupLock;

$lock = GroupLock::tryAcquireRuntime(getenv('AP_LAB_LOCK_DIR'));
if ($lock === null) {
    throw new RuntimeException('Another runtime owns the process lock.');
}
$socket = stream_socket_client('tcp://127.0.0.1:' . getenv('AP_LAB_AMI_PORT'), $errno, $errstr, 3);
if (!$socket) {
    throw new RuntimeException('AMI connection failed: ' . $errstr);
}
stream_set_timeout($socket, 6);
if (strpos((string) fgets($socket), 'Asterisk Call Manager/') !== 0) {
    throw new RuntimeException('Invalid AMI banner.');
}
$ami = new AmiConnection($socket);
$ami->request(['Action' => 'Login', 'Username' => getenv('AP_LAB_AMI_USERNAME'), 'Secret' => getenv('AP_LAB_AMI_SECRET'), 'Events' => 'on']);
(new CrashRecovery($ami, $lock))->recover();
$lock->release();
fclose($socket);
echo "Crash recovery confirmed all owned rooms and channels ended.\n";
