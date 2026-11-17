<?php

foreach (['Session', 'SessionController', 'AsteriskEffects', 'AmiConnection', 'AmiPlaybackDriver',
    'AmiSessionResources', 'AmiOrigination', 'SessionEventLoop', 'SessionEventRouter', 'GroupLock',
    'SessionRuntime'] as $file) {
    require_once __DIR__ . '/../src/' . $file . '.php';
}

use AdvancedPaging\AmiConnection;
use AdvancedPaging\GroupLock;
use AdvancedPaging\Session;
use AdvancedPaging\SessionRuntime;

$port = getenv('AP_LAB_AMI_PORT');
$secret = getenv('AP_LAB_AMI_SECRET');
$room = getenv('AP_LAB_CONFERENCE');
$caller = getenv('AP_LAB_CALLER');
$callerId = getenv('AP_LAB_CALLER_ID');
$lockDir = getenv('AP_LAB_LOCK_DIR');
if (!$port || !$secret || !$room || !$caller || !$callerId || !$lockDir) {
    throw new RuntimeException('Missing isolated setup settings.');
}
$socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
if (!$socket) {
    throw new RuntimeException('AMI connection failed: ' . $errstr);
}
stream_set_timeout($socket, 6);
if (strpos((string) fgets($socket), 'Asterisk Call Manager/') !== 0) {
    throw new RuntimeException('Invalid AMI banner.');
}
$ami = new AmiConnection($socket);
$ami->request(['Action' => 'Login', 'Username' => 'aplab', 'Secret' => $secret, 'Events' => 'on']);
$runtime = new SessionRuntime($ami, $lockDir, static function (string $id): string { return 'post'; });
$targets = ['Local/answer@advancedpaging-lab/n', 'Local/unanswered@advancedpaging-lab/n'];
$id = $runtime->start(3, $room, $caller, $callerId, 'duplex', null, '1', $targets, [], 3.0, true);
if ($id === null || $runtime->start(3, $room, $caller, $callerId, 'duplex', null, '1', $targets, [], 3.0, true) !== null) {
    throw new RuntimeException('Runtime failed to enforce group busy policy.');
}
$controller = $runtime->controller($id);
$sawPost = false;
$sawActive = false;
$deadline = microtime(true) + 20;
while ($runtime->count() && microtime(true) < $deadline) {
    $runtime->pump(1.0);
    if ($controller->state() === Session::ACTIVE && $controller->receiverCount() === 1) {
        $sawActive = true;
    }
    $sawPost = $sawPost || $controller->state() === Session::POST_PAGE;
}
if ($runtime->count() || !$sawPost || !$sawActive) {
    throw new RuntimeException('Originated session did not terminate: ' . $controller->state() . ' ' . json_encode($controller->cleanupErrors()));
}
$reacquired = GroupLock::tryAcquire($lockDir, 3);
if ($reacquired === null) {
    throw new RuntimeException('Group lock was not released.');
}
$reacquired->release();
fclose($socket);
echo "Runtime enforced group busy, started receiver, cancelled unanswered attempt, and completed hangup cleanup.\n";
