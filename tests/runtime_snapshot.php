<?php

require_once __DIR__ . '/../src/RuntimeSnapshot.php';

use AdvancedPaging\RuntimeSnapshot;

$groups = [
    1 => ['enabled' => 1, 'audio_mode' => 'duplex', 'members' => ['100', '101'],
        'pre_recording_id' => '7', 'post_recording_id' => '8'],
    2 => ['enabled' => 0, 'audio_mode' => 'simplex', 'members' => []],
];
$recordings = ['7' => 'custom/open&custom/part2', '8' => 'custom/close'];
$loader = new RuntimeSnapshot(
    static function (int $id) use (&$groups) { return $groups[$id] ?? null; },
    static function (string $device) { return $device === '100' ? '100' : null; },
    static function (array $members, string $caller, string $device): array {
        if ($caller !== '100' || $device !== '100') {
            throw new RuntimeException('Caller identity was not passed.');
        }
        return ['PJSIP/101'];
    },
    static function (): array { return ['ALERTINFO' => 'Ring Answer', 'CALLINFO' => '<uri>;answer-after=0']; },
    static function (string $id) use (&$recordings): string { return $recordings[$id] ?? ''; }
);
$snapshot = $loader->load(1, ['CallerChannel' => 'PJSIP/100-00000001']);
$recordings['8'] = 'custom/changed';
$groups[1]['audio_mode'] = 'simplex';
if ($snapshot['mode'] !== 'duplex' || $snapshot['recording_files']['8'] !== 'custom/close'
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
