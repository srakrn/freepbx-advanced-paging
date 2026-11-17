<?php

foreach (['SessionController', 'AsteriskEffects', 'AmiConnection', 'AutoAnswerSettings', 'AmiOrigination'] as $file) {
    require_once __DIR__ . '/../src/' . $file . '.php';
}

use AdvancedPaging\AmiConnection;
use AdvancedPaging\AmiOrigination;
use AdvancedPaging\AutoAnswerSettings;

$port = getenv('AP_LAB_AMI_PORT');
$secret = getenv('AP_LAB_AMI_SECRET');
$room = getenv('AP_LAB_CONFERENCE');
if (!$port || !$secret || !$room) {
    throw new RuntimeException('Missing isolated PJSIP test settings.');
}
$socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
if (!$socket) {
    throw new RuntimeException('AMI connection failed: ' . $errstr);
}
stream_set_timeout($socket, 6);
if (strpos((string) fgets($socket), 'Asterisk Call Manager/') !== 0) {
    throw new RuntimeException('Invalid AMI banner.');
}
$ami = new AmiConnection($socket);
$ami->request(['Action' => 'Login', 'Username' => getenv('AP_LAB_AMI_USERNAME'), 'Secret' => $secret, 'Events' => 'on']);
$origin = new AmiOrigination($ami);
$settings = AutoAnswerSettings::fromPagingDefaults([
    ['var' => 'ALERTINFO', 'setting' => 'Ring Answer'],
    ['var' => 'CALLINFO', 'setting' => '<uri>\\;answer-after=0'],
]);
$settings['ALERTINFO'] = 'Intercom';
$origin->start('PJSIP/aptestphone', $room, 10000, false, $settings);
$deadline = microtime(true) + 8;
$joined = false;
while (!$joined && microtime(true) < $deadline) {
    $event = $ami->waitForEvent(static function (array $event) use ($origin, $room): bool {
        return $origin->matches($event) || (($event['Event'] ?? '') === 'ConfbridgeJoin'
            && ($event['Conference'] ?? '') === $room);
    }, 1.0);
    if ($event === null) {
        continue;
    }
    $origin->observe($event);
    if (($event['Event'] ?? '') === 'ConfbridgeJoin') {
        $joined = $origin->joinedChannels($event) !== null;
    }
}
if (!$joined) {
    throw new RuntimeException('Synthetic PJSIP endpoint did not answer and join.');
}
$origin->cancelAndWait(8);
if (!$origin->cancelConfirmed()) {
    throw new RuntimeException('PJSIP receiver cleanup was not confirmed.');
}
fclose($socket);
echo "PJSIP receiver answered, joined, and was disconnected with confirmed hangup.\n";
