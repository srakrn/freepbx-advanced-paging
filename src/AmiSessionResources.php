<?php

namespace AdvancedPaging;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Tracks known session channels until their Hangup events are confirmed.
 * The runtime must serialize all calls and report Hangup events via observe().
 * Originations must be registered before the AMI request is sent.
 */
final class AmiSessionResources implements AsteriskSessionResources
{
    private AmiEventActions $ami;
    private $releaseLock;
    /** @var array<string, array{uniqueid: string, pending: bool}> */
    private array $channels = [];
    /** @var array<string, AmiOrigination> */
    private array $originations = [];
    private bool $released = false;

    public function __construct(AmiEventActions $ami, callable $releaseLock)
    {
        $this->ami = $ami;
        $this->releaseLock = $releaseLock;
    }

    public function track(string $channel, string $uniqueId, bool $pending): void
    {
        if ($this->released || $channel === '' || $uniqueId === ''
            || strpbrk($channel . $uniqueId, "\r\n") !== false
            || $channel[0] === '/') {
            throw new InvalidArgumentException('Invalid tracked channel.');
        }
        if (isset($this->channels[$channel]) && $this->channels[$channel]['uniqueid'] !== $uniqueId) {
            throw new RuntimeException('Channel identity changed.');
        }
        $this->channels[$channel] = ['uniqueid' => $uniqueId, 'pending' => $pending];
    }

    public function joined(string $channel): void
    {
        if (!isset($this->channels[$channel])) {
            throw new RuntimeException('Unknown session channel.');
        }
        $this->channels[$channel]['pending'] = false;
    }

    public function observe(array $event): void
    {
        foreach ($this->originations as $origin) {
            $origin->observe($event);
        }
        if (($event['Event'] ?? '') !== 'Hangup') {
            return;
        }
        $channel = $event['Channel'] ?? '';
        if (isset($this->channels[$channel])
            && ($event['Uniqueid'] ?? '') === $this->channels[$channel]['uniqueid']) {
            unset($this->channels[$channel]);
        }
    }

    public function startOrigination(AmiOrigination $origin, string $target, string $conference, int $timeoutMs, bool $allowLocalForTests = false, array $autoAnswer = [], bool $whisper = false, array $callerId = [], bool $dropSilence = true): void
    {
        if ($this->released || isset($this->originations[$origin->id()])) {
            throw new RuntimeException('Cannot register origination.');
        }
        // Register first: a write or response failure may still have created a call.
        $this->originations[$origin->id()] = $origin;
        try {
            $origin->start($target, $conference, $timeoutMs, $allowLocalForTests, $autoAnswer, $whisper, $callerId, $dropSilence);
        } catch (Throwable $error) {
            if (!$origin->submitted()) {
                unset($this->originations[$origin->id()]);
            }
            throw $error;
        }
    }

    /** Return the joined receiver channel after transferring all known legs. */
    public function adoptJoined(array $event): ?string
    {
        foreach ($this->originations as $id => $origin) {
            $channels = $origin->joinedChannels($event);
            if ($channels === null) {
                continue;
            }
            foreach ($channels as $channel => $uniqueId) {
                $this->track($channel, $uniqueId, false);
            }
            unset($this->originations[$id]);
            return $event['Channel'];
        }
        return null;
    }

    public function cancelAndWait(): void
    {
        foreach ($this->originations as $id => $origin) {
            $origin->cancelAndWait(8.0);
            unset($this->originations[$id]);
        }
        foreach ($this->channels as $channel => $entry) {
            if ($entry['pending']) {
                $this->disconnectAndWait($channel);
            }
        }
    }

    public function disconnectAndWait(string $channel): void
    {
        if (!isset($this->channels[$channel])) {
            // A previously observed hangup is already complete.
            return;
        }
        $entry = $this->channels[$channel];
        $isHangup = static function (array $event) use ($channel, $entry): bool {
            return ($event['Event'] ?? '') === 'Hangup'
                && ($event['Channel'] ?? '') === $channel
                && ($event['Uniqueid'] ?? '') === $entry['uniqueid'];
        };
        if ($this->ami->waitForEvent($isHangup, 0.0) !== null) {
            unset($this->channels[$channel]);
            return;
        }
        $error = null;
        try {
            $this->ami->request(['Action' => 'Hangup', 'Channel' => $channel]);
        } catch (Throwable $e) {
            $error = $e;
        }
        if ($this->ami->waitForEvent($isHangup, 5.0) === null) {
            throw new RuntimeException('Channel hangup was not confirmed.', 0, $error);
        }
        unset($this->channels[$channel]);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        // Hangup of one Local leg normally destroys its peer. Reap events
        // already buffered while disconnectAndWait waited on the first leg.
        foreach ($this->channels as $channel => $entry) {
            $ended = $this->ami->waitForEvent(static function (array $event) use ($channel, $entry): bool {
                return ($event['Event'] ?? '') === 'Hangup'
                    && ($event['Channel'] ?? '') === $channel
                    && ($event['Uniqueid'] ?? '') === $entry['uniqueid'];
            }, 0.0);
            if ($ended !== null) {
                unset($this->channels[$channel]);
            }
        }
        if ($this->channels || $this->originations) {
            throw new RuntimeException('Cannot release a session with active channels.');
        }
        ($this->releaseLock)();
        $this->released = true;
    }
}
