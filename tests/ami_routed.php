<?php

foreach (['Session', 'SessionController', 'AsteriskEffects', 'AmiConnection', 'AmiPlaybackDriver',
    'AmiSessionResources', 'AmiOrigination', 'SessionEventLoop', 'SessionEventRouter', 'GroupLock',
    'SessionRuntime', 'AdmissionCoordinator', 'CrashRecovery', 'RuntimeBootstrap'] as $file) {
    require_once __DIR__ . '/../src/' . $file . '.php';
}

use AdvancedPaging\AmiConnection;
use AdvancedPaging\RuntimeBootstrap;

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
$loaded = false;
$runtime = RuntimeBootstrap::start($ami, getenv('AP_LAB_LOCK_DIR'),
    static function (int $group, array $event) use (&$loaded): array {
        if ($group !== 11) {
            throw new RuntimeException('Unexpected group.');
        }
        $loaded = true;
        return [
            'mode' => 'duplex', 'pre' => null, 'post' => '1',
            'targets' => ['Local/answer@advancedpaging-lab/n'],
            'auto_answer' => [], 'setup_seconds' => 3.0,
            'recording_files' => ['1' => 'post'],
        ];
    }, static function (string $id): string { throw new RuntimeException('Snapshot resolver was not used.'); }, true);
echo "READY\n";
flush();
$sawSession = false;
$deadline = microtime(true) + 20; // Isolated test watchdog.
while (microtime(true) < $deadline) {
    $runtime->pump(1.0);
    $sawSession = $sawSession || $runtime->sessionCount() > 0;
    if ($sawSession && $runtime->sessionCount() === 0 && $runtime->pendingCount() === 0) {
        break;
    }
}
if ($runtime->sessionCount() || !$sawSession || !$loaded || $runtime->pendingCount()) {
    throw new RuntimeException('Routed session did not finish.');
}
$runtime->close();
fclose($socket);
echo "Dialled group launched runtime; receiver heard closing playback and session cleaned up.\n";
