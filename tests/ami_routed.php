<?php

foreach (['Session', 'SessionController', 'AsteriskEffects', 'AmiConnection', 'AmiPlaybackDriver',
    'AmiSessionResources', 'AmiOrigination', 'SessionEventLoop', 'SessionEventRouter', 'GroupLock',
    'SessionRuntime'] as $file) {
    require_once __DIR__ . '/../src/' . $file . '.php';
}

use AdvancedPaging\AmiConnection;
use AdvancedPaging\Session;
use AdvancedPaging\SessionRuntime;

$socket = stream_socket_client('tcp://127.0.0.1:' . getenv('AP_LAB_AMI_PORT'), $errno, $errstr, 3);
if (!$socket) {
    throw new RuntimeException('AMI connection failed: ' . $errstr);
}
stream_set_timeout($socket, 6);
if (strpos((string) fgets($socket), 'Asterisk Call Manager/') !== 0) {
    throw new RuntimeException('Invalid AMI banner.');
}
$ami = new AmiConnection($socket);
$ami->request(['Action' => 'Login', 'Username' => 'aplab', 'Secret' => getenv('AP_LAB_AMI_SECRET'), 'Events' => 'on']);
echo "READY\n";
flush();
$start = $ami->waitForEvent(static function (array $event): bool {
    return ($event['Event'] ?? '') === 'UserEvent'
        && ($event['UserEvent'] ?? '') === 'AdvancedPagingStart'
        && ($event['GroupID'] ?? '') === '11';
}, 8.0);
if (!$start || !preg_match('/^ap-[a-f0-9]{32}$/D', $start['Conference'] ?? '')
    || empty($start['CallerChannel']) || empty($start['CallerUniqueid'])
    || ($start['Uniqueid'] ?? '') !== $start['CallerUniqueid']) {
    throw new RuntimeException('Invalid or missing route start event: ' . json_encode($start));
}
$joined = $ami->waitForEvent(static function (array $event) use ($start): bool {
    return ($event['Event'] ?? '') === 'ConfbridgeJoin'
        && ($event['Conference'] ?? '') === $start['Conference']
        && ($event['Channel'] ?? '') === $start['CallerChannel']
        && ($event['Uniqueid'] ?? '') === $start['CallerUniqueid'];
}, 5.0);
if (!$joined) {
    throw new RuntimeException('Dialled initiator did not enter its conference.');
}
$runtime = new SessionRuntime($ami, getenv('AP_LAB_LOCK_DIR'), static function (string $id): string { return 'post'; });
$id = $runtime->start(11, $start['Conference'], $start['CallerChannel'], $start['CallerUniqueid'],
    'duplex', null, '1', ['Local/answer@advancedpaging-lab/n'], [], 3.0, true);
if ($id === null) {
    throw new RuntimeException('Routed group was unexpectedly busy.');
}
$controller = $runtime->controller($id);
$sawActive = false;
$sawPost = false;
$deadline = microtime(true) + 20; // Isolated test watchdog.
while ($runtime->count() && microtime(true) < $deadline) {
    $runtime->pump(1.0);
    $sawActive = $sawActive || $controller->state() === Session::ACTIVE;
    $sawPost = $sawPost || $controller->state() === Session::POST_PAGE;
}
if ($runtime->count() || !$sawActive || !$sawPost) {
    throw new RuntimeException('Routed session failed: ' . $controller->state() . ' ' . json_encode($controller->cleanupErrors()));
}
fclose($socket);
echo "Dialled group launched runtime; receiver heard closing playback and session cleaned up.\n";
