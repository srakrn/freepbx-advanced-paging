<?php

namespace AdvancedPaging;

use InvalidArgumentException;

/**
 * Single-threaded AMI event routing. Dispatch is broadcast to registered
 * sessions, whose exact channel/unique IDs and playback IDs isolate effects.
 * No session may call its own pump() while attached to this router.
 */
final class SessionEventRouter
{
    private AmiEventActions $ami;
    /** @var array<string, SessionEventLoop> */
    private array $sessions = [];

    public function __construct(AmiEventActions $ami)
    {
        $this->ami = $ami;
    }

    public function add(string $sessionId, SessionEventLoop $session): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $sessionId) || isset($this->sessions[$sessionId])) {
            throw new InvalidArgumentException('Invalid or duplicate session ID.');
        }
        $this->sessions[$sessionId] = $session;
    }

    public function pump(float $timeout): void
    {
        $event = $this->ami->waitForEvent(static function (array $event): bool {
            return in_array($event['Event'] ?? '', ['Hangup', 'UserEvent', 'Newchannel', 'OriginateResponse', 'ConfbridgeJoin'], true);
        }, $timeout);
        $this->dispatch($event);
    }

    public function dispatch(?array $event): void
    {
        foreach ($this->sessions as $id => $session) {
            if ($event !== null) {
                $session->dispatch($event);
            }
            $session->retryCleanup();
            if ($session->terminated()) {
                unset($this->sessions[$id]);
            }
        }
    }

    public function count(): int
    {
        return count($this->sessions);
    }
}
