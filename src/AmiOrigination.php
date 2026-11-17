<?php

namespace AdvancedPaging;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Tracks one asynchronous AMI origination, including both Local channel legs.
 *
 * This is an experimental primitive, not yet the production paging originator.
 * All calls and AMI events must be serialized. An uncertain operation remains
 * tracked; callers must not release its session lock until cancellation is
 * confirmed. The module-owned receiver and pre-dial contexts are not yet
 * installed by FreePBX.
 */
final class AmiOrigination
{
    private AmiEventActions $ami;
    private string $id;
    private string $actionId;
    private string $primaryId;
    private string $peerId;
    private bool $local;
    private string $conference = '';
    private bool $submitted = false;
    private bool $responseSeen = false;
    private ?string $response = null;
    private ?string $responseUniqueId = null;
    private bool $cancelled = false;
    /** @var array<string, array{channel: string, ended: bool, requested: bool}> */
    private array $channels = [];

    public function __construct(AmiEventActions $ami)
    {
        $this->ami = $ami;
        $this->id = bin2hex(random_bytes(16));
        $this->actionId = 'ap-orig-' . $this->id;
        $this->primaryId = 'ap-recv-' . $this->id;
        $this->peerId = $this->primaryId . '-peer';
        $this->local = false;
    }

    public function id(): string
    {
        return $this->id;
    }

    /**
     * Only internal endpoints are accepted. Local channels are allowed solely
     * for isolated tests; callers must never pass untrusted dial strings.
     */
    public function start(string $target, string $conference, int $timeoutMs, bool $allowLocalForTests = false, array $autoAnswer = []): void
    {
        if ($this->submitted || !preg_match('/^ap-[a-f0-9]{32}$/D', $conference)
            || $timeoutMs < 1000 || $timeoutMs > 60000) {
            throw new InvalidArgumentException('Invalid origination request.');
        }
        $this->local = $allowLocalForTests
            && (bool) preg_match('/^Local\/[A-Za-z0-9_-]+@[A-Za-z0-9_-]+\/n$/D', $target);
        if (!$this->local && !preg_match('/^PJSIP\/[A-Za-z0-9_.-]+$/D', $target)) {
            throw new InvalidArgumentException('Invalid internal endpoint.');
        }
        $this->conference = $conference;
        $headers = [
            'Action' => 'Originate',
            'ActionID' => $this->actionId,
            'Channel' => $target,
            'Context' => 'advancedpaging-receiver',
            'Exten' => 's',
            'Priority' => 1,
            'Timeout' => $timeoutMs,
            'Async' => 'true',
            'ChannelId' => $this->primaryId,
            'OtherChannelId' => $this->peerId,
            'Variable' => ['__AP_CONF=' . $conference, '__AP_ORIGINATION_ID=' . $this->id],
        ];
        if (!$this->local) {
            if (!array_key_exists('ALERTINFO', $autoAnswer) || !array_key_exists('CALLINFO', $autoAnswer)
                || !is_string($autoAnswer['ALERTINFO']) || !is_string($autoAnswer['CALLINFO'])) {
                throw new InvalidArgumentException('FreePBX auto-answer settings are required.');
            }
            $headers['Variable'][] = '__AP_ALERTINFO=' . AutoAnswerSettings::validate($autoAnswer['ALERTINFO']);
            $headers['Variable'][] = '__AP_CALLINFO=' . AutoAnswerSettings::validate($autoAnswer['CALLINFO']);
            $headers['PreDialGoSub'] = 'advancedpaging-predial,s,1';
        }
        // Mark before writing: an AMI transport failure has an uncertain result.
        $this->submitted = true;
        $this->ami->request($headers);
    }

    public function submitted(): bool
    {
        return $this->submitted;
    }

    /**
     * Hand a successfully joined receiver and its known Local peer to the
     * resource registry. Returns null for an event belonging to another call.
     */
    public function joinedChannels(array $event): ?array
    {
        if (($event['Event'] ?? '') !== 'ConfbridgeJoin'
            || ($event['Conference'] ?? '') !== $this->conference
            || ($event['Uniqueid'] ?? '') !== $this->primaryId) {
            return null;
        }
        $entry = $this->channels[$this->primaryId] ?? null;
        if ($entry === null || $entry['ended'] || ($event['Channel'] ?? '') !== $entry['channel']) {
            throw new RuntimeException('Joined receiver channel was not tracked.');
        }
        if ($this->local && (!isset($this->channels[$this->peerId]) || $this->channels[$this->peerId]['ended'])) {
            throw new RuntimeException('Joined Local receiver peer was not tracked.');
        }
        $result = [];
        foreach ($this->channels as $id => $channel) {
            if (!$channel['ended']) {
                $result[$channel['channel']] = $id;
            }
        }
        return $result;
    }

    public function matches(array $event): bool
    {
        if (($event['Event'] ?? '') === 'OriginateResponse') {
            return ($event['ActionID'] ?? '') === $this->actionId;
        }
        return in_array($event['Uniqueid'] ?? '', [$this->primaryId, $this->peerId], true)
            && in_array($event['Event'] ?? '', ['Newchannel', 'Hangup'], true);
    }

    /** Called by the serialized event loop for matching events. */
    public function observe(array $event): void
    {
        if (!$this->matches($event)) {
            return;
        }
        $type = $event['Event'];
        if ($type === 'OriginateResponse') {
            $this->responseSeen = true;
            $this->response = $event['Response'] ?? '';
            $this->responseUniqueId = $event['Uniqueid'] ?? null;
            return;
        }
        $uniqueId = $event['Uniqueid'];
        if ($type === 'Newchannel') {
            $channel = $event['Channel'] ?? '';
            if (!is_string($channel) || $channel === '' || $channel[0] === '/'
                || strpbrk($channel, "\r\n") !== false) {
                throw new RuntimeException('Invalid originated channel identity.');
            }
            if (!isset($this->channels[$uniqueId])) {
                $this->channels[$uniqueId] = ['channel' => $channel, 'ended' => false, 'requested' => false];
            }
        } elseif (isset($this->channels[$uniqueId])) {
            $this->channels[$uniqueId]['ended'] = true;
        }
    }

    /** Cancel and wait for matching AMI evidence; timeout leaves it tracked. */
    public function cancelAndWait(float $timeout): void
    {
        if (!$this->submitted || $timeout <= 0) {
            throw new InvalidArgumentException('Invalid cancellation.');
        }
        $this->cancelled = true;
        $deadline = microtime(true) + $timeout;
        while (true) {
            foreach ($this->channels as $uniqueId => $channel) {
                if (!$channel['ended'] && !$channel['requested']) {
                    $this->channels[$uniqueId]['requested'] = true;
                    try {
                        $this->ami->request(['Action' => 'Hangup', 'Channel' => $channel['channel']]);
                    } catch (Throwable $error) {
                        // It may have hung up concurrently. Only a Hangup event
                        // can confirm that; otherwise keep the operation pending.
                        $this->channels[$uniqueId]['requested'] = false;
                    }
                }
            }
            if ($this->cancelConfirmed()) {
                return;
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('Origination cancellation was not confirmed.');
            }
            $event = $this->ami->waitForEvent(function (array $event): bool {
                return $this->matches($event);
            }, $remaining);
            if ($event === null) {
                throw new RuntimeException('Origination cancellation was not confirmed.');
            }
            $this->observe($event);
        }
    }

    public function cancelConfirmed(): bool
    {
        if (!$this->cancelled || !$this->responseSeen) {
            return false;
        }
        if (!in_array(strtolower((string) $this->response), ['success', 'failure'], true)) {
            return false;
        }
        // Do not infer absence of a channel from a failed request alone.
        if (!isset($this->channels[$this->primaryId])
            && !(strcasecmp((string) $this->response, 'Failure') === 0
                && $this->responseUniqueId === '<null>')) {
            return false;
        }
        // If a Local primary existed, its second leg must also be observed.
        if ($this->local && isset($this->channels[$this->primaryId]) && !isset($this->channels[$this->peerId])) {
            return false;
        }
        foreach ($this->channels as $channel) {
            if (!$channel['ended']) {
                return false;
            }
        }
        return true;
    }
}
