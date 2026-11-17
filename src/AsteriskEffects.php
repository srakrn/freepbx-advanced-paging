<?php

namespace AdvancedPaging;

use InvalidArgumentException;

/**
 * AMI requests must return only after receiving the matching ActionID response.
 * They must throw on timeout, connection loss, or an AMI error response.
 */
interface AmiActions
{
    public function request(array $headers): void;
}

interface AmiEventActions extends AmiActions
{
    public function waitForEvent(callable $predicate, float $timeout): ?array;
}

/**
 * Playback driver for a session-owned injector.
 *
 * stopAndWait must confirm that the injector can no longer transmit before
 * returning. An AMI Hangup success response alone does not satisfy this.
 * Completion events must be delivered to SessionController separately.
 */
interface PlaybackDriver
{
    public function start(string $playbackId, string $recordingId): void;
    public function stopAndWait(string $playbackId): void;
}

/**
 * Resource registry owns the pending calls and lock associated with one session.
 *
 * cancelAndWait must confirm every pending call has ended or cannot join.
 * disconnectAndWait must confirm the given channel is no longer active.
 * release must refuse to release the group lock while session resources remain.
 */
interface AsteriskSessionResources
{
    public function cancelAndWait(): void;
    public function disconnectAndWait(string $channel): void;
    public function release(): void;
}

/**
 * Maps controller effects to Asterisk. Not usable without a transport,
 * playback driver, resource registry, and serialized event loop.
 */
final class AsteriskEffects implements SessionEffects
{
    private AmiActions $ami;
    private PlaybackDriver $playback;
    private AsteriskSessionResources $resources;
    private string $conference;

    public function __construct(
        AmiActions $ami,
        PlaybackDriver $playback,
        AsteriskSessionResources $resources,
        string $conference
    ) {
        // Session IDs, not administrator-provided names, identify rooms.
        if (!preg_match('/^ap-[a-f0-9]{32}$/D', $conference)) {
            throw new InvalidArgumentException('Invalid conference identifier.');
        }
        $this->ami = $ami;
        $this->playback = $playback;
        $this->resources = $resources;
        $this->conference = $conference;
    }

    public function cancelPendingCalls(): void
    {
        $this->resources->cancelAndWait();
    }

    public function setMuted(string $channel, bool $muted): void
    {
        // ConfbridgeMute accepts channel prefixes and the special values "all"
        // and "participants". Require a complete Asterisk channel name so a
        // malformed name cannot mute a different session participant.
        if (!preg_match('/^(?:PJSIP\/[A-Za-z0-9_.@+-]+-[0-9a-fA-F]{8}|Local\/[A-Za-z0-9_.@+-]+-[0-9a-fA-F]{8};[12])$/D', $channel)) {
            throw new InvalidArgumentException('Invalid exact channel name.');
        }
        $this->ami->request([
            'Action' => $muted ? 'ConfbridgeMute' : 'ConfbridgeUnmute',
            'Conference' => $this->conference,
            'Channel' => $channel,
        ]);
    }

    public function play(string $playbackId, string $recordingId): void
    {
        $this->validatePlayback($playbackId);
        if (!preg_match('/^[1-9][0-9]*$/D', $recordingId)) {
            throw new InvalidArgumentException('Invalid recording reference.');
        }
        $this->playback->start($playbackId, $recordingId);
    }

    public function stopPlayback(string $playbackId): void
    {
        $this->validatePlayback($playbackId);
        $this->playback->stopAndWait($playbackId);
    }

    public function disconnect(string $channel): void
    {
        if ($channel === '' || strpbrk($channel, "\r\n") !== false) {
            throw new InvalidArgumentException('Invalid channel.');
        }
        $this->resources->disconnectAndWait($channel);
    }

    public function release(): void
    {
        $this->resources->release();
    }

    private function validatePlayback(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new InvalidArgumentException('Invalid playback identifier.');
        }
    }
}
