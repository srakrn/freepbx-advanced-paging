<?php

require_once __DIR__ . '/../src/EndpointResolver.php';

use AdvancedPaging\EndpointResolver;

$devices = [
    '101' => ['id' => '101', 'tech' => 'pjsip'],
    '102' => ['id' => '102', 'tech' => 'pjsip'],
    '103' => ['id' => '103', 'tech' => 'custom'],
    '104' => ['id' => '104', 'tech' => 'pjsip'],
];
$resolver = new EndpointResolver(
    static function (): array { return ['100' => [], '101' => [], '102' => [], '103' => []]; },
    static function (string $user): string {
        return ['100' => '100', '101' => '101&102', '102' => '101&103', '103' => '104'][$user];
    },
    static function (string $id) use ($devices): array { return $devices[$id] ?? []; },
    static function (string $endpoint): string {
        return $endpoint === 'PJSIP/102' ? 'INUSE' : ($endpoint === 'PJSIP/104' ? 'UNAVAILABLE' : 'NOT_INUSE');
    },
    static function (string $device): array {
        return $device === '101' ? [
            ['uri' => 'sip:101@192.0.2.1:5060', 'user_agent' => 'Grandstream GRP2602W'],
            ['uri' => 'sip:101@192.0.2.2:5060', 'user_agent' => 'Other Phone'],
        ] : [['uri' => 'sip:device@192.0.2.3:5060', 'user_agent' => 'Phone']];
    }
);
$found = $resolver->resolve(['100', '101', '102', '103', '999'], '100', '100');
if (count($found) !== 2 || $found[0]['channel'] !== 'PJSIP/101/sip:101@192.0.2.1:5060'
    || $found[0]['user_agent'] !== 'Grandstream GRP2602W') {
    throw new RuntimeException('Endpoint resolution failed: ' . json_encode($found));
}
if ($resolver->resolve(['101'], '100', '101') !== []) {
    throw new RuntimeException('Caller device was not excluded.');
}
try {
    $resolver->resolve(['101@external'], '100', '100');
    throw new RuntimeException('Invalid member was accepted.');
} catch (InvalidArgumentException $expected) {
}
echo "FreePBX endpoint resolution policy tests passed.\n";
