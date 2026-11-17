<?php

namespace AdvancedPaging;

use LogicException;
use Throwable;

/**
 * Effect interface for a single serialized paging session.
 *
 * Implementations must throw on failure and must not deliver callbacks
 * reentrantly. stopPlayback() and mute() must complete before returning.
 * Playback completion must be reported separately to playbackFinished().
 */
interface SessionEffects
{
    public function cancelPendingCalls(): void;
    public function setMuted(string $channel, bool $muted): void;
    public function play(string $playbackId, string $recordingId): void;
    public function stopPlayback(string $playbackId): void;
    public function disconnect(string $channel): void;
    public function release(): void;
}

/**
 * A serialized controller for one configuration snapshot.
 *
 * This is transport-independent: it is not yet connected to Asterisk and
 * does not itself provide event serialization or cross-process group locking.
 */
final class SessionController
{
    private SessionEffects $effects;
    private Session $session;
    private string $initiator;
    private string $mode;
    private ?string $pre;
    private ?string $post;
    /** @var array<string, bool> */
    private array $receivers = [];
    private ?string $playbackId = null;
    private bool $setupClosed = false;
    private bool $released = false;
    private bool $initiatorDisconnected = false;
    /** @var array<int, string> */
    private array $cleanupErrors = [];

    public function __construct(
        SessionEffects $effects,
        string $initiator,
        string $mode,
        ?string $preRecordingId,
        ?string $postRecordingId
    ) {
        if ($initiator === '' || !in_array($mode, ['simplex', 'duplex'], true)) {
            throw new LogicException('Invalid initiator or audio mode.');
        }
        foreach ([$preRecordingId, $postRecordingId] as $id) {
            if ($id !== null && !preg_match('/^[1-9][0-9]*$/D', $id)) {
                throw new LogicException('Invalid recording reference.');
            }
        }
        $this->effects = $effects;
        $this->initiator = $initiator;
        $this->mode = $mode;
        $this->pre = $preRecordingId;
        $this->post = $postRecordingId;
        $this->session = new Session();
    }

    public function id(): string
    {
        return $this->session->id();
    }

    public function state(): string
    {
        return $this->session->state();
    }

    public function cleanupErrors(): array
    {
        return $this->cleanupErrors;
    }

    public function receiverCount(): int
    {
        return count($this->receivers);
    }

    /** The transport must attach the initiator muted before setup begins. */
    public function start(): void
    {
        $this->session->connect();
    }

    /** The transport must attach every receiver muted before reporting it. */
    public function receiverJoined(string $channel): void
    {
        if ($channel === '' || $channel === $this->initiator) {
            throw new LogicException('Invalid receiver channel.');
        }
        if (isset($this->receivers[$channel])) {
            return;
        }
        if ($this->setupClosed || $this->state() !== Session::CONNECTING) {
            $this->attempt(function () use ($channel): void {
                $this->effects->disconnect($channel);
            });
            return;
        }
        $this->receivers[$channel] = true;
    }

    /** Called once the finite setup window has closed. */
    public function setupFinished(): void
    {
        if ($this->state() !== Session::CONNECTING || $this->setupClosed) {
            return;
        }
        $this->setupClosed = true;
        try {
            $this->effects->cancelPendingCalls();
            if (!$this->receivers) {
                $this->terminate();
                return;
            }
            $this->session->setupComplete($this->pre !== null);
            if ($this->pre !== null) {
                $this->beginPlayback($this->pre);
            } else {
                $this->activateAudio();
            }
        } catch (Throwable $error) {
            $this->fail($error);
        }
    }

    public function playbackFinished(string $id, bool $success): void
    {
        if ($id !== $this->playbackId || $this->playbackId === null) {
            return;
        }
        $this->playbackId = null;
        try {
            if (!$success) {
                $this->terminate();
            } elseif ($this->state() === Session::PRE_PAGE) {
                $this->session->prePageComplete();
                $this->activateAudio();
            } elseif ($this->state() === Session::POST_PAGE) {
                $this->session->postPageComplete();
                $this->cleanup();
            }
        } catch (Throwable $error) {
            $this->fail($error);
        }
    }

    public function initiatorHungUp(): void
    {
        if (!$this->session->initiatorHungUp((bool) $this->receivers, $this->post !== null)) {
            return;
        }
        $this->initiatorDisconnected = true;
        try {
            $this->setupClosed = true;
            $this->effects->cancelPendingCalls();
            if ($this->playbackId !== null) {
                $oldPlayback = $this->playbackId;
                $this->effects->stopPlayback($oldPlayback);
                $this->playbackId = null;
            }
            if ($this->state() !== Session::POST_PAGE) {
                $this->cleanup();
                return;
            }
            foreach (array_keys($this->receivers) as $channel) {
                $this->effects->setMuted($channel, true);
            }
            $this->beginPlayback($this->post);
        } catch (Throwable $error) {
            $this->fail($error);
        }
    }

    public function receiverLeft(string $channel): void
    {
        if (!isset($this->receivers[$channel])) {
            return;
        }
        unset($this->receivers[$channel]);
        if (!$this->receivers && $this->setupClosed) {
            $this->terminate();
        }
    }

    public function terminate(): void
    {
        if ($this->state() === Session::TERMINATED) {
            return;
        }
        $this->session->terminate();
        $this->setupClosed = true;
        $pendingCancelled = $this->attempt(function (): void {
            $this->effects->cancelPendingCalls();
        });
        if ($this->playbackId !== null) {
            $oldPlayback = $this->playbackId;
            if ($this->attempt(function () use ($oldPlayback): void {
                $this->effects->stopPlayback($oldPlayback);
            })) {
                $this->playbackId = null;
            } else {
                // Do not release the group while an injector may still send.
                return;
            }
        }
        if (!$pendingCancelled) {
            return;
        }
        $this->cleanup();
    }

    private function beginPlayback(string $recordingId): void
    {
        $this->playbackId = bin2hex(random_bytes(16));
        $this->effects->play($this->playbackId, $recordingId);
    }

    private function activateAudio(): void
    {
        $this->effects->setMuted($this->initiator, false);
        if ($this->mode === 'duplex') {
            foreach (array_keys($this->receivers) as $channel) {
                $this->effects->setMuted($channel, false);
            }
        }
    }

    private function cleanup(): void
    {
        if ($this->released) {
            return;
        }
        foreach (array_keys($this->receivers) as $channel) {
            if ($this->attempt(function () use ($channel): void {
                $this->effects->disconnect($channel);
            })) {
                unset($this->receivers[$channel]);
            }
        }
        if (!$this->initiatorDisconnected) {
            $this->initiatorDisconnected = $this->attempt(function (): void {
                $this->effects->disconnect($this->initiator);
            });
        }
        if ($this->receivers || !$this->initiatorDisconnected) {
            return;
        }
        if (!$this->attempt(function (): void {
            $this->effects->release();
        })) {
            return;
        }
        $this->released = true;
        $this->session->cleanupComplete();
    }

    private function fail(Throwable $error): void
    {
        $this->cleanupErrors[] = $error->getMessage();
        $this->terminate();
    }

    private function attempt(callable $effect): bool
    {
        try {
            $effect();
            return true;
        } catch (Throwable $error) {
            $this->cleanupErrors[] = $error->getMessage();
            return false;
        }
    }
}
