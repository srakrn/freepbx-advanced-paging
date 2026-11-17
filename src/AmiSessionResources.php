<?php

namespace AdvancedPaging;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Tracks known session channels until their Hangup events are confirmed.
 * The runtime must serialize all calls and report Hangup events via observe().
 * Pending originations with no known channel must be tracked separately by
 * the eventual origination layer; this class cannot cancel an unknown channel.
 */
final class AmiSessionResources implements AsteriskSessionResources
{
    private AmiEventActions $ami;
    private $releaseLock;
    /** @var array<string, array{uniqueid: string, pending: bool}> */
    private array $channels = [];
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
        if (($event['Event'] ?? '') !== 'Hangup') {
            return;
        }
        $channel = $event['Channel'] ?? '';
        if (isset($this->channels[$channel])
            && ($event['Uniqueid'] ?? '') === $this->channels[$channel]['uniqueid']) {
            unset($this->channels[$channel]);
        }
    }

    public function cancelAndWait(): void
    {
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
        if ($this->channels) {
            throw new RuntimeException('Cannot release a session with active channels.');
        }
        ($this->releaseLock)();
        $this->released = true;
    }
}
