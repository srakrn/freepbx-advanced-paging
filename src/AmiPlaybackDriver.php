<?php

namespace AdvancedPaging;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Playback via a session-owned Local injector and the module's player dialplan.
 *
 * Event ownership and calls to this driver must be serialized. The resolver
 * returns an Asterisk sound-list (not a path supplied by an administrator).
 * Asterisk must emit AdvancedPagingPlayback when Playback has actually ended;
 * acceptance of Originate is not a completion signal.
 */
final class AmiPlaybackDriver implements PlaybackDriver
{
    private AmiEventActions $ami;
    private string $conference;
    private $resolveRecording;
    /** @var array<string, array{uniqueid: string, channel: ?string}> */
    private array $active = [];

    public function __construct(AmiEventActions $ami, string $conference, callable $resolveRecording)
    {
        if (!preg_match('/^ap-[a-f0-9]{32}$/D', $conference)) {
            throw new InvalidArgumentException('Invalid conference identifier.');
        }
        $this->ami = $ami;
        $this->conference = $conference;
        $this->resolveRecording = $resolveRecording;
    }

    public function start(string $playbackId, string $recordingId): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $playbackId)
            || !preg_match('/^[1-9][0-9]*$/D', $recordingId)
            || isset($this->active[$playbackId])) {
            throw new InvalidArgumentException('Invalid playback request.');
        }
        $sounds = ($this->resolveRecording)($recordingId);
        if (!is_string($sounds) || strlen($sounds) > 2048) {
            throw new InvalidArgumentException('Recording is unavailable.');
        }
        foreach (explode('&', $sounds) as $sound) {
            if ($sound === '' || strpos($sound, '..') !== false
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $sound)) {
                throw new InvalidArgumentException('Recording contains an invalid sound reference.');
            }
        }
        $uniqueId = 'ap-play-' . $playbackId;
        $this->active[$playbackId] = ['uniqueid' => $uniqueId, 'channel' => null];
        $this->ami->request([
            'Action' => 'Originate',
            'Channel' => 'Local/s@advancedpaging-inject/n',
            'Context' => 'advancedpaging-player',
            'Exten' => 's',
            'Priority' => 1,
            'Async' => 'true',
            'Timeout' => 5000,
            'ChannelId' => $uniqueId,
            'OtherChannelId' => $uniqueId . '-peer',
            'Variable' => [
                '__AP_CONF=' . $this->conference,
                '__AP_PLAYBACK_ID=' . $playbackId,
                '__AP_SOUNDS=' . $sounds,
            ],
        ]);
        $channel = $this->ami->waitForEvent(static function (array $event) use ($uniqueId): bool {
            return ($event['Event'] ?? '') === 'Newchannel' && ($event['Uniqueid'] ?? '') === $uniqueId;
        }, 5.0);
        if (!$channel || empty($channel['Channel'])) {
            throw new RuntimeException('Playback channel was not observed.');
        }
        $this->active[$playbackId]['channel'] = $channel['Channel'];
    }

    /**
     * Called by the serialized event loop for every UserEvent. Returns null
     * for unrelated or stale events, otherwise [playback ID, success].
     */
    public function completion(array $event): ?array
    {
        $id = $event['PlaybackID'] ?? '';
        if (($event['Event'] ?? '') !== 'UserEvent'
            || ($event['UserEvent'] ?? '') !== 'AdvancedPagingPlayback'
            || !isset($this->active[$id])
            || ($event['Uniqueid'] ?? '') !== $this->active[$id]['uniqueid']) {
            return null;
        }
        unset($this->active[$id]);
        return [$id, ($event['Status'] ?? '') === 'SUCCESS'];
    }

    public function stopAndWait(string $playbackId): void
    {
        if (!isset($this->active[$playbackId])) {
            return;
        }
        $entry = $this->active[$playbackId];
        $isHangup = static function (array $event) use ($entry): bool {
            return ($event['Event'] ?? '') === 'Hangup' && ($event['Uniqueid'] ?? '') === $entry['uniqueid'];
        };
        // A hangup already seen is proof even if the channel no longer exists.
        if ($this->ami->waitForEvent($isHangup, 0.0) !== null) {
            unset($this->active[$playbackId]);
            return;
        }
        if ($entry['channel'] === null) {
            throw new RuntimeException('Cannot safely identify playback channel.');
        }
        $error = null;
        try {
            $this->ami->request(['Action' => 'Hangup', 'Channel' => $entry['channel']]);
        } catch (Throwable $e) {
            $error = $e;
        }
        if ($this->ami->waitForEvent($isHangup, 5.0) === null) {
            throw new RuntimeException('Playback channel stop was not confirmed.', 0, $error);
        }
        unset($this->active[$playbackId]);
    }
}
