<?php

foreach (['Session', 'SessionController', 'AsteriskEffects', 'AmiConnection', 'AmiPlaybackDriver',
    'AmiSessionResources', 'AmiOrigination', 'SessionEventLoop', 'SessionEventRouter', 'GroupLock',
    'SessionRuntime', 'AdmissionCoordinator'] as $file) {
    require_once __DIR__ . '/../src/' . $file . '.php';
}

use AdvancedPaging\AmiConnection;
use AdvancedPaging\AdmissionCoordinator;
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
$runtime = new SessionRuntime($ami, getenv('AP_LAB_LOCK_DIR'), static function (string $id): string { return 'post'; });
$loaded = false;
$admission = new AdmissionCoordinator($ami, $runtime, getenv('AP_LAB_LOCK_DIR'),
    static function (int $group, array $event) use (&$loaded): array {
        if ($group !== 11) {
            throw new RuntimeException('Unexpected group.');
        }
        $loaded = true;
        return [
            'mode' => 'duplex', 'pre' => null, 'post' => '1',
            'targets' => ['Local/answer@advancedpaging-lab/n'],
            'auto_answer' => [], 'setup_seconds' => 3.0,
        ];
    }, true);
echo "READY\n";
flush();
$sawSession = false;
$deadline = microtime(true) + 20; // Isolated test watchdog.
while (microtime(true) < $deadline) {
    $admission->pump(1.0);
    $sawSession = $sawSession || $runtime->count() > 0;
    if ($sawSession && $runtime->count() === 0 && $admission->pendingCount() === 0) {
        break;
    }
}
if ($runtime->count() || !$sawSession || !$loaded || $admission->pendingCount()) {
    throw new RuntimeException('Routed session did not finish.');
}
fclose($socket);
echo "Dialled group launched runtime; receiver heard closing playback and session cleaned up.\n";
