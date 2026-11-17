<?php

require_once __DIR__ . '/../src/RuntimeSnapshot.php';
require_once __DIR__ . '/../src/AutoAnswerSettings.php';

use AdvancedPaging\RuntimeSnapshot;

$groups = [
    1 => ['enabled' => 1, 'audio_mode' => 'duplex', 'members' => ['100', '101'],
        'pre_recording_id' => '7', 'post_recording_id' => '8', 'instruction_recording_id' => '7',
        'speaker_volume' => 4, 'busy_policy' => 2],
    2 => ['enabled' => 0, 'audio_mode' => 'simplex', 'members' => []],
];
$recordings = ['7' => 'custom/open&custom/part2', '8' => 'custom/close'];
$loader = new RuntimeSnapshot(
    static function (int $id) use (&$groups) { return $groups[$id] ?? null; },
    static function (string $device) { return $device === '100' ? '100' : null; },
    static function (array $members, string $caller, string $device, int $busyPolicy): array {
        if ($caller !== '100' || $device !== '100') {
            throw new RuntimeException('Caller identity was not passed.');
        }
        if ($busyPolicy !== 2) {
            throw new RuntimeException('Busy policy was not passed.');
        }
        return [['channel' => 'PJSIP/101/sip:101@192.0.2.1:5060', 'endpoint' => '101', 'user_agent' => 'TestPhone', 'busy' => true]];
    },
    static function (array $targets): array {
        return ['ALERTINFO' => 'Ring Answer', 'CALLINFO' => '<uri>;answer-after=0',
            'overrides' => [$targets[0]['channel'] => ['ALERTINFO' => 'Intercom']]];
    },
    static function (string $id) use (&$recordings): string { return $recordings[$id] ?? ''; }
);
$snapshot = $loader->load(1, ['CallerChannel' => 'PJSIP/100-00000001']);
$recordings['8'] = 'custom/changed';
$groups[1]['audio_mode'] = 'simplex';
if ($snapshot['mode'] !== 'duplex' || $snapshot['recording_files']['8'] !== 'custom/close'
    || $snapshot['targets'][0]['auto_answer']['ALERTINFO'] !== 'Intercom;volume=3'
    || empty($snapshot['targets'][0]['whisper']) || $snapshot['instruction_recording_id'] !== '7'
    || $snapshot['recording_files']['7'] !== 'custom/open&custom/part2') {
    throw new RuntimeException('Session snapshot was not stable.');
}
foreach ([
    [1, ['CallerChannel' => 'Local/100@from-internal-00000001;1']],
    [1, ['CallerChannel' => 'PJSIP/999-00000001']],
    [2, ['CallerChannel' => 'PJSIP/100-00000001']],
] as [$id, $event]) {
    try {
        $loader->load($id, $event);
        throw new RuntimeException('Invalid runtime snapshot was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() === 'Invalid runtime snapshot was accepted.') {
            throw $error;
        }
    }
}
$recordings['7'] = '../unsafe';
try {
    $loader->load(1, ['CallerChannel' => 'PJSIP/100-00000001']);
    throw new RuntimeException('Unsafe recording was accepted.');
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'Unsafe recording was accepted.') {
        throw $error;
    }
}
echo "FreePBX runtime snapshot tests passed.\n";
