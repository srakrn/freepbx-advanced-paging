<?php

namespace AdvancedPaging;

use InvalidArgumentException;
use Throwable;

/**
 * Serialized AMI event dispatcher for one already-established session.
 *
 * This does not originate participants, enforce a group lock, or reconnect
 * AMI. The caller must register exact channel names and unique IDs before
 * start(), and must call pump() from only one thread. A shared runtime must
 * route events to their owning session rather than giving each session its
 * own consumer of the same AMI connection.
 */
final class SessionEventLoop
{
    private AmiEventActions $ami;
    private AmiPlaybackDriver $playback;
    private AmiSessionResources $resources;
    private SessionController $controller;
    private string $initiator;
    private string $initiatorId;
    /** @var array<string, string> */
    private array $receivers;
    private bool $started = false;

    public function __construct(
        AmiEventActions $ami,
        AmiPlaybackDriver $playback,
        AmiSessionResources $resources,
        SessionController $controller,
        string $initiator,
        string $initiatorId,
        array $receivers
    ) {
        if ($initiator === '' || $initiatorId === '' || !$receivers) {
            throw new InvalidArgumentException('Session channels are required.');
        }
        foreach ($receivers as $channel => $uniqueId) {
            if (!is_string($channel) || $channel === '' || $channel === $initiator
                || !is_string($uniqueId) || $uniqueId === '') {
                throw new InvalidArgumentException('Invalid receiver identity.');
            }
        }
        $this->ami = $ami;
        $this->playback = $playback;
        $this->resources = $resources;
        $this->controller = $controller;
        $this->initiator = $initiator;
        $this->initiatorId = $initiatorId;
        $this->receivers = $receivers;
    }

    /** Channels must already be joined, muted, and owned by this session. */
    public function start(): void
    {
        if ($this->started) {
            throw new InvalidArgumentException('Session already started.');
        }
        $this->started = true;
        $this->resources->track($this->initiator, $this->initiatorId, false);
        foreach ($this->receivers as $channel => $id) {
            $this->resources->track($channel, $id, false);
        }
        $this->controller->start();
        foreach ($this->receivers as $channel => $id) {
            $this->controller->receiverJoined($channel);
        }
        $this->controller->setupFinished();
    }

    /** Process at most one event; zero or finite timeout never limits a call. */
    public function pump(float $timeout): void
    {
        if (!$this->started) {
            throw new InvalidArgumentException('Session has not started.');
        }
        if ($this->controller->state() === Session::TERMINATED) {
            return;
        }
        try {
            $event = $this->ami->waitForEvent(static function (array $event): bool {
                return in_array($event['Event'] ?? '', ['Hangup', 'UserEvent'], true);
            }, $timeout);
            if ($event !== null) {
                $this->dispatch($event);
            } elseif ($this->controller->state() === Session::TERMINATING) {
                $this->controller->terminate();
            }
        } catch (Throwable $error) {
            $this->controller->terminate();
            throw $error;
        }
    }

    private function dispatch(array $event): void
    {
        if (($event['Event'] ?? '') === 'Hangup') {
            $this->resources->observe($event);
            $channel = $event['Channel'] ?? '';
            $uniqueId = $event['Uniqueid'] ?? '';
            if ($channel === $this->initiator && $uniqueId === $this->initiatorId) {
                $this->controller->initiatorHungUp();
            } elseif (isset($this->receivers[$channel]) && $this->receivers[$channel] === $uniqueId) {
                unset($this->receivers[$channel]);
                $this->controller->receiverLeft($channel);
            }
        }
        $completed = $this->playback->completion($event);
        if ($completed !== null) {
            $this->controller->playbackFinished($completed[0], $completed[1]);
        }
    }
}
