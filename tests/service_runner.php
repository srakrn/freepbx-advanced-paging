<?php

foreach (['Session', 'SessionController', 'AsteriskEffects', 'AmiConnection', 'AmiPlaybackDriver',
    'AmiSessionResources', 'AmiOrigination', 'SessionEventLoop', 'SessionEventRouter', 'GroupLock',
    'SessionRuntime', 'AdmissionCoordinator', 'CrashRecovery', 'RuntimeBootstrap', 'RuntimeConfig',
    'RuntimeService'] as $file) {
    require_once __DIR__ . '/../src/' . $file . '.php';
}

use AdvancedPaging\RuntimeConfig;
use AdvancedPaging\RuntimeService;

$config = RuntimeConfig::read(getenv('AP_LAB_CONFIG'));
$service = new RuntimeService(
    static function () use ($config) { return RuntimeConfig::connect($config); },
    getenv('AP_LAB_LOCK_DIR'),
    static function (int $group, array $event): array {
        if ($group !== 11) {
            throw new RuntimeException('Unexpected test group.');
        }
        return [
            'mode' => 'duplex', 'pre' => null, 'post' => '1',
            'targets' => ['Local/answer@advancedpaging-lab/n'],
            'auto_answer' => [], 'setup_seconds' => 3.0,
            'recording_files' => ['1' => 'post'],
        ];
    },
    static function (string $id): string { throw new RuntimeException('Snapshot resolver required.'); },
    static function (string $message): void { fwrite(STDERR, $message . "\n"); },
    static function (): void { echo "READY\n"; flush(); },
    true
);
$service->run();
echo "STOPPED\n";
