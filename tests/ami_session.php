<?php

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/SessionController.php';
require_once __DIR__ . '/../src/AsteriskEffects.php';
require_once __DIR__ . '/../src/AmiConnection.php';
require_once __DIR__ . '/../src/AmiPlaybackDriver.php';
require_once __DIR__ . '/../src/AmiSessionResources.php';
require_once __DIR__ . '/../src/SessionEventLoop.php';

use AdvancedPaging\AmiConnection;
use AdvancedPaging\AmiPlaybackDriver;
use AdvancedPaging\AmiSessionResources;
use AdvancedPaging\AsteriskEffects;
use AdvancedPaging\Session;
use AdvancedPaging\SessionController;
use AdvancedPaging\SessionEventLoop;

$port = getenv('AP_LAB_AMI_PORT');
$secret = getenv('AP_LAB_AMI_SECRET');
$room = getenv('AP_LAB_CONFERENCE');
$caller = getenv('AP_LAB_CALLER');
$callerId = getenv('AP_LAB_CALLER_ID');
$receiver = getenv('AP_LAB_CHANNEL');
$receiverId = getenv('AP_LAB_UNIQUEID');
if (!$port || !$secret || !$room || !$caller || !$callerId || !$receiver || !$receiverId) {
    throw new RuntimeException('Missing isolated session settings.');
}
$socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
if (!$socket) {
    throw new RuntimeException('Cannot connect to isolated AMI: ' . $errstr);
}
stream_set_timeout($socket, 6);
if (strpos((string) fgets($socket), 'Asterisk Call Manager/') !== 0) {
    throw new RuntimeException('Invalid AMI banner.');
}
$ami = new AmiConnection($socket);
$ami->request(['Action' => 'Login', 'Username' => 'aplab', 'Secret' => $secret, 'Events' => 'on']);
$playback = new AmiPlaybackDriver($ami, $room, static function (string $id): string {
    if ($id !== '1') {
        throw new RuntimeException('Unknown recording.');
    }
    return 'post';
});
$released = false;
$resources = new AmiSessionResources($ami, static function () use (&$released): void { $released = true; });
$effects = new AsteriskEffects($ami, $playback, $resources, $room);
$controller = new SessionController($effects, $caller, 'duplex', null, '1');
$loop = new SessionEventLoop($ami, $playback, $resources, $controller, $caller, $callerId, [$receiver => $receiverId]);
$loop->start();
if ($controller->state() !== Session::ACTIVE) {
    throw new RuntimeException('Automatic session did not become active.');
}
$sawPost = false;
$deadline = microtime(true) + 15; // Test watchdog, not a paging duration limit.
while ($controller->state() !== Session::TERMINATED && microtime(true) < $deadline) {
    $loop->pump(1.0);
    $sawPost = $sawPost || $controller->state() === Session::POST_PAGE;
}
if ($controller->state() !== Session::TERMINATED || !$sawPost || !$released) {
    throw new RuntimeException('Automatic session did not complete: ' . $controller->state() . ' ' . json_encode($controller->cleanupErrors()));
}
fclose($socket);
echo "Automatic initiator hangup, closing playback, receiver cleanup and lock release succeeded.\n";
