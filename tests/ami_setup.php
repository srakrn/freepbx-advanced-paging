<?php

foreach (['Session', 'SessionController', 'AsteriskEffects', 'AmiConnection', 'AmiPlaybackDriver',
    'AmiSessionResources', 'AmiOrigination', 'SessionEventLoop', 'SessionEventRouter', 'GroupLock'] as $file) {
    require_once __DIR__ . '/../src/' . $file . '.php';
}

use AdvancedPaging\AmiConnection;
use AdvancedPaging\AmiOrigination;
use AdvancedPaging\AmiPlaybackDriver;
use AdvancedPaging\AmiSessionResources;
use AdvancedPaging\AsteriskEffects;
use AdvancedPaging\GroupLock;
use AdvancedPaging\Session;
use AdvancedPaging\SessionController;
use AdvancedPaging\SessionEventLoop;
use AdvancedPaging\SessionEventRouter;

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
$lock = GroupLock::tryAcquire($lockDir, 3);
if ($lock === null) {
    throw new RuntimeException('Test group is busy.');
}
$resources = new AmiSessionResources($ami, static function () use ($lock): void { $lock->release(); });
$playback = new AmiPlaybackDriver($ami, $room, static function (string $id): string { return 'post'; });
$controller = new SessionController(new AsteriskEffects($ami, $playback, $resources, $room), $caller, 'duplex', null, '1');
$loop = new SessionEventLoop($ami, $playback, $resources, $controller, $caller, $callerId, []);
$router = new SessionEventRouter($ami);
$loop->startConnecting();
$router->add($controller->id(), $loop);
$resources->startOrigination(new AmiOrigination($ami), 'Local/answer@advancedpaging-lab/n', $room, 10000, true);
$resources->startOrigination(new AmiOrigination($ami), 'Local/unanswered@advancedpaging-lab/n', $room, 10000, true);
$deadline = microtime(true) + 5;
while ($controller->receiverCount() === 0 && microtime(true) < $deadline) {
    $router->pump(0.5);
}
if ($controller->receiverCount() !== 1 || $controller->state() !== Session::CONNECTING) {
    throw new RuntimeException('Answered receiver did not join: ' . $controller->state());
}
$loop->finishSetup();
if ($controller->state() !== Session::ACTIVE) {
    throw new RuntimeException('Setup did not finish: ' . json_encode($controller->cleanupErrors()));
}
$sawPost = false;
$deadline = microtime(true) + 20;
while ($router->count() && microtime(true) < $deadline) {
    $router->pump(1.0);
    $sawPost = $sawPost || $controller->state() === Session::POST_PAGE;
}
if ($router->count() || !$sawPost) {
    throw new RuntimeException('Originated session did not terminate: ' . $controller->state() . ' ' . json_encode($controller->cleanupErrors()));
}
$reacquired = GroupLock::tryAcquire($lockDir, 3);
if ($reacquired === null) {
    throw new RuntimeException('Group lock was not released.');
}
$reacquired->release();
fclose($socket);
echo "Originated receiver joined; unanswered attempt cancelled; hangup playback and cleanup completed.\n";
