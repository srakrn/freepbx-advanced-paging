<?php

foreach (['Session', 'SessionController', 'AsteriskEffects', 'AmiConnection', 'AmiPlaybackDriver',
    'AmiSessionResources', 'SessionEventLoop', 'SessionEventRouter', 'GroupLock'] as $file) {
    require_once __DIR__ . '/../src/' . $file . '.php';
}

use AdvancedPaging\AmiConnection;
use AdvancedPaging\AmiPlaybackDriver;
use AdvancedPaging\AmiSessionResources;
use AdvancedPaging\AsteriskEffects;
use AdvancedPaging\GroupLock;
use AdvancedPaging\Session;
use AdvancedPaging\SessionController;
use AdvancedPaging\SessionEventLoop;
use AdvancedPaging\SessionEventRouter;

$settings = json_decode((string) getenv('AP_LAB_SESSIONS'), true);
$port = getenv('AP_LAB_AMI_PORT');
$secret = getenv('AP_LAB_AMI_SECRET');
$lockDir = getenv('AP_LAB_LOCK_DIR');
if (!is_array($settings) || count($settings) !== 2 || !$port || !$secret || !$lockDir) {
    throw new RuntimeException('Missing concurrent test settings.');
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
$ami->request(['Action' => 'Login', 'Username' => getenv('AP_LAB_AMI_USERNAME'), 'Secret' => $secret, 'Events' => 'on']);
$router = new SessionEventRouter($ami);
$controllers = [];
foreach ($settings as $index => $item) {
    $lock = GroupLock::tryAcquire($lockDir, $index + 1);
    if ($lock === null || GroupLock::tryAcquire($lockDir, $index + 1) !== null) {
        throw new RuntimeException('Group busy policy failed.');
    }
    $resources = new AmiSessionResources($ami, static function () use ($lock): void { $lock->release(); });
    $driver = new AmiPlaybackDriver($ami, $item['room'], static function (string $id): string { return 'post'; });
    $effects = new AsteriskEffects($ami, $driver, $resources, $item['room']);
    $controller = new SessionController($effects, $item['caller'], 'duplex', null, '1');
    $loop = new SessionEventLoop($ami, $driver, $resources, $controller, $item['caller'], $item['caller_id'],
        [$item['receiver'] => $item['receiver_id']]);
    $loop->start();
    $router->add($controller->id(), $loop);
    $controllers[] = $controller;
}
$sawIndependent = false;
$deadline = microtime(true) + 24; // Test watchdog only.
while ($router->count() && microtime(true) < $deadline) {
    $router->pump(1.0);
    if ($controllers[0]->state() === Session::TERMINATED && $controllers[1]->state() === Session::ACTIVE) {
        $sawIndependent = true;
    }
}
if ($router->count() !== 0 || !$sawIndependent) {
    throw new RuntimeException('Concurrent sessions were not isolated: ' . $controllers[0]->state() . ', ' . $controllers[1]->state());
}
foreach ([1, 2] as $group) {
    $lock = GroupLock::tryAcquire($lockDir, $group);
    if ($lock === null) {
        throw new RuntimeException('Completed group lock was not released.');
    }
    $lock->release();
}
fclose($socket);
echo "Concurrent groups remained isolated; same-group calls were locked; both locks released.\n";
