<?php

require_once __DIR__ . '/../src/Session.php';
require_once __DIR__ . '/../src/SessionController.php';

use AdvancedPaging\Session;
use AdvancedPaging\SessionController;
use AdvancedPaging\SessionEffects;

function ensure(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

final class FakeEffects implements SessionEffects
{
    public array $calls = [];
    public ?string $playback = null;
    public ?string $failOn = null;

    private function record(string $call): void
    {
        $this->calls[] = $call;
        if ($this->failOn !== null && strpos($call, $this->failOn) === 0) {
            throw new RuntimeException('Simulated effect failure: ' . $call);
        }
    }
    public function cancelPendingCalls(): void { $this->record('cancel'); }
    public function setMuted(string $channel, bool $muted): void { $this->record('mute:' . $channel . ':' . (int) $muted); }
    public function play(string $playbackId, string $recordingId): void
    {
        $this->playback = $playbackId;
        $this->record('play:' . $recordingId);
    }
    public function stopPlayback(string $playbackId): void { $this->record('stop:' . $playbackId); }
    public function disconnect(string $channel): void { $this->record('disconnect:' . $channel); }
    public function release(): void { $this->record('release'); }
}

$effects = new FakeEffects();
$controller = new SessionController($effects, 'caller', 'duplex', '1', '2');
$controller->start();
$controller->receiverJoined('r1');
$controller->receiverJoined('r2');
$controller->setupFinished();
ensure($controller->state() === Session::PRE_PAGE, 'Pre-page not started.');
$preId = $effects->playback;
ensure($effects->calls === ['cancel', 'play:1'], 'Unexpected setup effects.');
$controller->playbackFinished($preId, true);
ensure($controller->state() === Session::ACTIVE, 'Pre-page did not complete.');
ensure(array_slice($effects->calls, -3) === ['mute:caller:0', 'mute:r1:0', 'mute:r2:0'], 'Duplex microphones not enabled.');
$controller->initiatorHungUp();
ensure($controller->state() === Session::POST_PAGE, 'Post-page not started.');
$postId = $effects->playback;
ensure(array_slice($effects->calls, -4) === ['cancel', 'mute:r1:1', 'mute:r2:1', 'play:2'], 'Closing audio policy failed.');
$controller->initiatorHungUp();
$controller->playbackFinished($preId, true);
ensure($controller->state() === Session::POST_PAGE, 'Duplicate or stale event affected post-page.');
$controller->receiverLeft('r1');
$controller->playbackFinished($postId, true);
ensure($controller->state() === Session::TERMINATED, 'Post-page did not terminate.');
ensure(array_slice($effects->calls, -2) === ['disconnect:r2', 'release'], 'Cleanup failed.');
$count = count($effects->calls);
$controller->terminate();
ensure(count($effects->calls) === $count, 'Cleanup repeated.');

$effects = new FakeEffects();
$controller = new SessionController($effects, 'caller', 'simplex', '1', '2');
$controller->start();
$controller->receiverJoined('r');
$controller->setupFinished();
$preId = $effects->playback;
$controller->initiatorHungUp();
ensure(array_slice($effects->calls, -4) === ['cancel', 'stop:' . $preId, 'mute:r:1', 'play:2'], 'Opening and closing playback were not serialized.');
$controller->playbackFinished($preId, true);
ensure($controller->state() === Session::POST_PAGE, 'Stopped playback callback was not ignored.');
$controller->playbackFinished($effects->playback, true);

$effects = new FakeEffects();
$controller = new SessionController($effects, 'caller', 'simplex', null, null);
$controller->start();
$controller->receiverJoined('r');
$controller->setupFinished();
ensure(!in_array('mute:r:0', $effects->calls, true), 'Simplex receiver was unmuted.');
$controller->receiverJoined('late');
ensure(in_array('disconnect:late', $effects->calls, true), 'Late receiver was not rejected.');
$controller->initiatorHungUp();
ensure($controller->state() === Session::TERMINATED, 'Missing post-page must terminate immediately.');

$effects = new FakeEffects();
$effects->failOn = 'stop:';
$controller = new SessionController($effects, 'caller', 'duplex', '1', '2');
$controller->start();
$controller->receiverJoined('r');
$controller->setupFinished();
$controller->initiatorHungUp();
ensure($controller->state() === Session::TERMINATED, 'Failed playback cancellation did not clean up.');
ensure(!in_array('play:2', $effects->calls, true), 'Closing playback overlapped failed stop.');
ensure(count($controller->cleanupErrors()) === 1, 'Effect error not retained.');

$effects = new FakeEffects();
$controller = new SessionController($effects, 'caller', 'duplex', null, null);
$controller->start();
$controller->setupFinished();
ensure($controller->state() === Session::TERMINATED, 'Empty session not terminated.');

$effects = new FakeEffects();
$controller = new SessionController($effects, 'caller', 'duplex', null, null);
$controller->start();
$controller->receiverJoined('r');
$controller->setupFinished();
$effects->failOn = 'disconnect:r';
$controller->initiatorHungUp();
ensure($controller->state() === Session::TERMINATING, 'Failed disconnect must remain retryable.');
ensure(!in_array('release', $effects->calls, true), 'Resources released before receiver disconnected.');
$effects->failOn = null;
$controller->terminate();
ensure($controller->state() === Session::TERMINATED, 'Cleanup retry failed.');
echo "Session controller tests passed.\n";
