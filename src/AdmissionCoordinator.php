<?php

namespace AdvancedPaging;

use RuntimeException;
use Throwable;

/**
 * Serialized admission and session event loop.
 *
 * The group loader must return an immutable, validated snapshot including
 * mode, pre, post, targets, auto_answer and setup_seconds. This class does not
 * authenticate AMI, supervise the process, or recover after a process crash.
 */
final class AdmissionCoordinator
{
    private AmiEventActions $ami;
    private SessionRuntime $runtime;
    private string $lockDirectory;
    private $groupLoader;
    private bool $allowLocalForTests;
    /** @var array<string, array> */
    private array $pending = [];

    public function __construct(
        AmiEventActions $ami,
        SessionRuntime $runtime,
        string $lockDirectory,
        callable $groupLoader,
        bool $allowLocalForTests = false
    ) {
        $this->ami = $ami;
        $this->runtime = $runtime;
        $this->lockDirectory = $lockDirectory;
        $this->groupLoader = $groupLoader;
        $this->allowLocalForTests = $allowLocalForTests;
    }

    public function pump(float $timeout): void
    {
        // Bound polling only while admissions are pending; this is not a call limit.
        if ($this->pending) {
            $timeout = min($timeout, 0.5);
        }
        $event = $this->ami->waitForEvent(static function (array $event): bool {
            return in_array($event['Event'] ?? '', ['Hangup', 'UserEvent', 'Newchannel', 'OriginateResponse', 'ConfbridgeJoin'], true);
        }, $timeout);
        if ($event !== null) {
            $this->handle($event);
        }
        $this->runtime->dispatch($event);
        $now = hrtime(true) / 1000000000;
        foreach ($this->pending as &$pending) {
            if ($now >= $pending['deadline'] && !$pending['hangup_requested']) {
                $pending['hangup_requested'] = true;
                try {
                    $this->ami->request(['Action' => 'Hangup', 'Channel' => $pending['channel']]);
                } catch (Throwable $error) {
                    // The request may have succeeded. Keep the lock until a
                    // matching Hangup event confirms the caller is gone.
                    $pending['hangup_requested'] = false;
                }
            }
        }
        unset($pending);
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    private function handle(array $event): void
    {
        if (($event['Event'] ?? '') === 'UserEvent'
            && ($event['UserEvent'] ?? '') === 'AdvancedPagingStart') {
            $this->requestAdmission($event);
            return;
        }
        $uniqueId = $event['Uniqueid'] ?? '';
        if (!isset($this->pending[$uniqueId])) {
            return;
        }
        $pending = $this->pending[$uniqueId];
        if (($event['Channel'] ?? '') !== $pending['channel']) {
            return;
        }
        if (($event['Event'] ?? '') === 'Hangup') {
            $pending['lock']->release();
            unset($this->pending[$uniqueId]);
        } elseif (($event['Event'] ?? '') === 'ConfbridgeJoin'
            && ($event['Conference'] ?? '') === $pending['conference']) {
            $snapshot = $pending['snapshot'];
            try {
                $recordings = $snapshot['recording_files'] ?? null;
                $resolver = is_array($recordings) ? static function (string $id) use ($recordings): string {
                    if (!isset($recordings[$id]) || !is_string($recordings[$id])) {
                        throw new RuntimeException('Snapshot recording is unavailable.');
                    }
                    return $recordings[$id];
                } : null;
                $id = $this->runtime->start(
                    $pending['group'], $pending['conference'], $pending['channel'], $uniqueId,
                    $snapshot['mode'], $snapshot['pre'], $snapshot['post'], $snapshot['targets'],
                    $snapshot['auto_answer'], $snapshot['setup_seconds'], $this->allowLocalForTests,
                    $pending['lock'], $resolver
                );
                if ($id === null) {
                    throw new RuntimeException('Reserved group became unavailable.');
                }
                unset($this->pending[$uniqueId]);
            } catch (Throwable $error) {
                // Runtime startup may have partially succeeded. Do not admit
                // another caller until the original caller is confirmed gone.
                try {
                    $this->ami->request(['Action' => 'Hangup', 'Channel' => $pending['channel']]);
                } catch (Throwable $ignored) {
                }
                $this->pending[$uniqueId]['hangup_requested'] = true;
            }
        }
    }

    private function requestAdmission(array $event): void
    {
        $group = $event['GroupID'] ?? '';
        $conference = $event['Conference'] ?? '';
        $channel = $event['CallerChannel'] ?? '';
        $uniqueId = $event['CallerUniqueid'] ?? '';
        if (!is_string($group) || !preg_match('/^[1-9][0-9]{0,8}$/D', $group)
            || !is_string($conference) || !preg_match('/^ap-[a-f0-9]{32}$/D', $conference)
            || !is_string($channel) || $channel === '' || $channel[0] === '/'
            || strpbrk($channel, "\r\n") !== false
            || !is_string($uniqueId) || $uniqueId === ''
            || ($event['Uniqueid'] ?? '') !== $uniqueId
            || ($event['Channel'] ?? '') !== $channel
            || ($event['Context'] ?? '') !== 'app-advancedpaging'
            || ($event['Exten'] ?? '') !== $group
            || isset($this->pending[$uniqueId])) {
            return;
        }
        try {
            $snapshot = ($this->groupLoader)((int) $group, $event);
            if (!is_array($snapshot) || empty($snapshot['targets'])) {
                return;
            }
            $lock = GroupLock::tryAcquire($this->lockDirectory, (int) $group);
            if ($lock === null) {
                return; // Dialplan watchdog rejects the caller.
            }
            $this->pending[$uniqueId] = [
                'group' => (int) $group, 'conference' => $conference, 'channel' => $channel,
                'lock' => $lock, 'snapshot' => $snapshot,
                'deadline' => hrtime(true) / 1000000000 + 5.0, 'hangup_requested' => false,
            ];
            $this->ami->request([
                'Action' => 'Setvar', 'Channel' => $channel,
                'Variable' => 'AP_ADMITTED', 'Value' => '1',
            ]);
        } catch (Throwable $error) {
            // No acknowledgement is sent on invalid configuration or failure.
            // A possibly acknowledged caller remains tracked until hangup.
        }
    }
}
