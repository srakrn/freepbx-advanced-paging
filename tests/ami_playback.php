<?php

require_once __DIR__ . '/../src/SessionController.php';
require_once __DIR__ . '/../src/AsteriskEffects.php';
require_once __DIR__ . '/../src/AmiConnection.php';
require_once __DIR__ . '/../src/AmiPlaybackDriver.php';

use AdvancedPaging\AmiConnection;
use AdvancedPaging\AmiPlaybackDriver;

$port = getenv('AP_LAB_AMI_PORT');
$secret = getenv('AP_LAB_AMI_SECRET');
$room = getenv('AP_LAB_CONFERENCE');
if (!$port || !$secret || !$room) {
    throw new RuntimeException('Missing isolated test settings.');
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
$driver = new AmiPlaybackDriver($ami, $room, static function (string $id): string {
    if ($id !== '1') {
        throw new RuntimeException('Unknown recording.');
    }
    return 'post';
});
$id = bin2hex(random_bytes(16));
$driver->start($id, '1');
$event = $ami->waitForEvent(static function (array $event) use ($id): bool {
    return ($event['Event'] ?? '') === 'UserEvent'
        && ($event['UserEvent'] ?? '') === 'AdvancedPagingPlayback'
        && ($event['PlaybackID'] ?? '') === $id;
}, 8.0);
if (!$event || $driver->completion($event) !== [$id, true]) {
    throw new RuntimeException('Playback completion was not reported successfully: ' . json_encode($event));
}
$stoppedId = bin2hex(random_bytes(16));
$driver->start($stoppedId, '1');
usleep(200000);
$stopStarted = microtime(true);
$driver->stopAndWait($stoppedId);
if (microtime(true) - $stopStarted > 1.5) {
    throw new RuntimeException('Playback did not stop promptly.');
}
$driver->stopAndWait($stoppedId);
fclose($socket);
echo "AMI playback completed and a second playback stopped with confirmed hangup.\n";
