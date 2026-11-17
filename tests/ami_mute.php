<?php

require_once __DIR__ . '/../src/SessionController.php';
require_once __DIR__ . '/../src/AsteriskEffects.php';
require_once __DIR__ . '/../src/AmiConnection.php';

use AdvancedPaging\AmiConnection;
use AdvancedPaging\AsteriskEffects;
use AdvancedPaging\AsteriskSessionResources;
use AdvancedPaging\PlaybackDriver;

final class UnusedPlayback implements PlaybackDriver
{
    public function start(string $playbackId, string $recordingId): void { throw new RuntimeException('Not used.'); }
    public function stopAndWait(string $playbackId): void { throw new RuntimeException('Not used.'); }
}
final class UnusedResources implements AsteriskSessionResources
{
    public function cancelAndWait(): void { throw new RuntimeException('Not used.'); }
    public function disconnectAndWait(string $channel): void { throw new RuntimeException('Not used.'); }
    public function release(): void { throw new RuntimeException('Not used.'); }
}

$port = getenv('AP_LAB_AMI_PORT');
$secret = getenv('AP_LAB_AMI_SECRET');
$conference = getenv('AP_LAB_CONFERENCE');
$channel = getenv('AP_LAB_CHANNEL');
$mute = getenv('AP_LAB_MUTE');
if (!$port || !$secret || !$conference || !$channel || !in_array($mute, ['0', '1'], true)) {
    throw new RuntimeException('Missing isolated test settings.');
}
$socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
if (!$socket) {
    throw new RuntimeException('Cannot connect to isolated AMI: ' . $errstr);
}
stream_set_timeout($socket, 3);
$banner = fgets($socket);
if ($banner === false || strpos($banner, 'Asterisk Call Manager/') !== 0) {
    throw new RuntimeException('Invalid AMI banner.');
}
$ami = new AmiConnection($socket);
$ami->request(['Action' => 'Login', 'Username' => 'aplab', 'Secret' => $secret, 'Events' => 'off']);
$effects = new AsteriskEffects($ami, new UnusedPlayback(), new UnusedResources(), $conference);
$effects->setMuted($channel, $mute === '1');
fclose($socket);
echo "AMI effect accepted.\n";
