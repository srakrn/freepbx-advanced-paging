<?php

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/SessionController.php';
require_once __DIR__ . '/../src/AsteriskEffects.php';

use AdvancedPaging\AmiActions;
use AdvancedPaging\AsteriskEffects;
use AdvancedPaging\AsteriskSessionResources;
use AdvancedPaging\PlaybackDriver;
use AdvancedPaging\Session;
use AdvancedPaging\SessionController;

function verify(bool $result, string $message): void
{
    if (!$result) {
        throw new RuntimeException($message);
    }
}

final class TestAmi implements AmiActions
{
    public array $actions = [];
    public function request(array $headers): void { $this->actions[] = $headers; }
}
final class TestPlayback implements PlaybackDriver
{
    public array $calls = [];
    public ?string $lastId = null;
    public function start(string $playbackId, string $recordingId): void
    {
        $this->lastId = $playbackId;
        $this->calls[] = 'start:' . $recordingId;
    }
    public function stopAndWait(string $playbackId): void { $this->calls[] = 'stop:' . $playbackId; }
}
final class TestResources implements AsteriskSessionResources
{
    public array $calls = [];
    public function cancelAndWait(): void { $this->calls[] = 'cancel'; }
    public function disconnectAndWait(string $channel): void { $this->calls[] = 'disconnect:' . $channel; }
    public function release(): void { $this->calls[] = 'release'; }
}

$ami = new TestAmi();
$playback = new TestPlayback();
$resources = new TestResources();
$room = 'ap-' . str_repeat('a', 32);
$effects = new AsteriskEffects($ami, $playback, $resources, $room);
$caller = 'PJSIP/100-00000001';
$receiver = 'PJSIP/101-00000002';
$controller = new SessionController($effects, $caller, 'duplex', '11', '12');
$controller->start();
$controller->receiverJoined($receiver);
$controller->setupFinished();
verify($playback->calls === ['start:11'], 'Opening playback not started.');
$controller->playbackFinished($playback->lastId, true);
verify($controller->state() === Session::ACTIVE, 'Session did not activate.');
verify($ami->actions === [
    ['Action' => 'ConfbridgeUnmute', 'Conference' => $room, 'Channel' => $caller],
    ['Action' => 'ConfbridgeUnmute', 'Conference' => $room, 'Channel' => $receiver],
], 'Unexpected AMI unmute actions.');
$controller->initiatorHungUp();
verify(end($ami->actions) === ['Action' => 'ConfbridgeMute', 'Conference' => $room, 'Channel' => $receiver], 'Receiver was not muted.');
verify($playback->calls === ['start:11', 'start:12'], 'Closing playback not started.');
$controller->playbackFinished($playback->lastId, true);
verify($controller->state() === Session::TERMINATED, 'Closing playback did not clean up.');
verify(in_array('disconnect:' . $receiver, $resources->calls, true), 'Receiver was not disconnected.');
verify(end($resources->calls) === 'release', 'Session resources were not released.');

foreach (['all', 'participants', 'PJSIP/10', "PJSIP/x-00000001\r\nAction: Hangup"] as $badChannel) {
    try {
        $effects->setMuted($badChannel, true);
        throw new RuntimeException('Invalid channel accepted.');
    } catch (InvalidArgumentException $expected) {
    }
}
try {
    new AsteriskEffects($ami, $playback, $resources, 'all');
    throw new RuntimeException('Invalid conference accepted.');
} catch (InvalidArgumentException $expected) {
}
echo "Asterisk effects/controller tests passed.\n";
