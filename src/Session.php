<?php

namespace AdvancedPaging;

use LogicException;

/**
 * Pure lifecycle model. The future controller must serialize event handling,
 * enforce microphone states, and acknowledge actual Asterisk operations.
 */
final class Session
{
    public const CREATED = 'CREATED';
    public const CONNECTING = 'CONNECTING';
    public const PRE_PAGE = 'PRE_PAGE';
    public const ACTIVE = 'ACTIVE';
    public const POST_PAGE = 'POST_PAGE';
    public const TERMINATING = 'TERMINATING';
    public const TERMINATED = 'TERMINATED';

    private string $id;
    private string $state = self::CREATED;
    private bool $initiatorDisconnected = false;

    public function __construct()
    {
        $this->id = bin2hex(random_bytes(16));
    }

    public function id(): string
    {
        return $this->id;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function connect(): void
    {
        $this->requireState(self::CREATED);
        $this->state = self::CONNECTING;
    }

    /** Called once setup has closed and at least one receiver is connected. */
    public function setupComplete(bool $hasPreRecording): void
    {
        $this->requireState(self::CONNECTING);
        $this->state = $hasPreRecording ? self::PRE_PAGE : self::ACTIVE;
    }

    public function prePageComplete(): void
    {
        $this->requireState(self::PRE_PAGE);
        $this->state = self::ACTIVE;
    }

    /**
     * Returns true only for the event that claims termination. During PRE_PAGE
     * the caller must stop opening playback before starting closing playback.
     */
    public function initiatorHungUp(bool $hasReceivers, bool $hasPostRecording): bool
    {
        if ($this->initiatorDisconnected || in_array($this->state, [self::TERMINATING, self::TERMINATED], true)) {
            return false;
        }
        $this->initiatorDisconnected = true;
        // Setup cancellation does not start closing playback.
        $canPlay = in_array($this->state, [self::PRE_PAGE, self::ACTIVE], true);
        $this->state = $canPlay && $hasReceivers && $hasPostRecording ? self::POST_PAGE : self::TERMINATING;
        return true;
    }

    /** Safe to repeat for no receivers, playback failures and other errors. */
    public function terminate(): bool
    {
        if (in_array($this->state, [self::TERMINATING, self::TERMINATED], true)) {
            return false;
        }
        $this->state = self::TERMINATING;
        return true;
    }

    public function postPageComplete(): void
    {
        $this->requireState(self::POST_PAGE);
        $this->state = self::TERMINATING;
    }

    public function cleanupComplete(): void
    {
        if ($this->state === self::TERMINATED) {
            return;
        }
        $this->requireState(self::TERMINATING);
        $this->state = self::TERMINATED;
    }

    private function requireState(string $state): void
    {
        if ($this->state !== $state) {
            throw new LogicException('Invalid session transition from ' . $this->state . '; expected ' . $state . '.');
        }
    }
}
