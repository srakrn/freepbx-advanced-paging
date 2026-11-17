<?php

namespace AdvancedPaging;

use InvalidArgumentException;
use Throwable;

/**
 * In-process session startup and shared event coordination.
 *
 * A caller must already be in its unique conference, muted, and identified
 * by a trusted call-control layer. This component does not discover calls,
 * reconnect AMI, supervise itself, or recover sessions after a crash.
 */
final class SessionRuntime
{
    private AmiEventActions $ami;
    private SessionEventRouter $router;
    private string $lockDirectory;
    private $recordingResolver;
    /** @var array<string, array{loop: SessionEventLoop, controller: SessionController, deadline: float}> */
    private array $sessions = [];

    public function __construct(AmiEventActions $ami, string $lockDirectory, callable $recordingResolver)
    {
        $this->ami = $ami;
        $this->router = new SessionEventRouter($ami);
        $this->lockDirectory = $lockDirectory;
        $this->recordingResolver = $recordingResolver;
    }

    /**
     * Return the session ID, or null if the group is busy.
     *
     * Targets are trusted internal PJSIP endpoint strings. allowLocalForTests
     * is for isolated tests only and must never be set from a user request.
     */
    public function start(
        int $groupId,
        string $conference,
        string $caller,
        string $callerId,
        string $mode,
        ?string $preRecordingId,
        ?string $postRecordingId,
        array $targets,
        array $autoAnswer,
        float $setupSeconds,
        bool $allowLocalForTests = false,
        ?GroupLock $reservation = null,
        ?callable $sessionRecordingResolver = null
    ): ?string {
        if ($setupSeconds < 1 || $setupSeconds > 60 || !$targets) {
            throw new InvalidArgumentException('Invalid receiver setup.');
        }
        foreach ($targets as $target) {
            if (!is_string($target) && (!is_array($target) || !is_string($target['channel'] ?? null))) {
                throw new InvalidArgumentException('Invalid receiver target.');
            }
        }
        if ($reservation !== null && !$reservation->holds($groupId)) {
            throw new InvalidArgumentException('Invalid group reservation.');
        }
        $lock = $reservation ?? GroupLock::tryAcquire($this->lockDirectory, $groupId);
        if ($lock === null) {
            return null;
        }
        $registered = false;
        try {
            $resources = new AmiSessionResources($this->ami, static function () use ($lock): void {
                $lock->release();
            });
            $playback = new AmiPlaybackDriver($this->ami, $conference, $sessionRecordingResolver ?? $this->recordingResolver);
            $effects = new AsteriskEffects($this->ami, $playback, $resources, $conference);
            $controller = new SessionController($effects, $caller, $mode, $preRecordingId, $postRecordingId);
            $loop = new SessionEventLoop($this->ami, $playback, $resources, $controller, $caller, $callerId, []);
            $loop->startConnecting();
            $sessionId = $controller->id();
            $this->router->add($sessionId, $loop);
            $this->sessions[$sessionId] = [
                'loop' => $loop,
                'controller' => $controller,
                'deadline' => hrtime(true) / 1000000000 + $setupSeconds,
            ];
            $registered = true;
            $seenTargets = [];
            foreach ($targets as $target) {
                $targetChannel = is_string($target) ? $target : $target['channel'];
                if (isset($seenTargets[$targetChannel])) {
                    continue;
                }
                $seenTargets[$targetChannel] = true;
                $targetAutoAnswer = $autoAnswer;
                if (is_array($target) && isset($target['auto_answer']) && is_array($target['auto_answer'])) {
                    $targetAutoAnswer = array_replace($autoAnswer, $target['auto_answer']);
                }
                $resources->startOrigination(
                    new AmiOrigination($this->ami),
                    $targetChannel,
                    $conference,
                    (int) ceil($setupSeconds * 1000),
                    $allowLocalForTests,
                    $targetAutoAnswer
                );
            }
            return $sessionId;
        } catch (Throwable $error) {
            if ($registered) {
                // Keep the session and group lock registered if any uncertain
                // AMI operation cannot yet be confirmed as cancelled.
                $controller->terminate();
                return $sessionId;
            } else {
                // A caller which already holds a reservation may already have
                // been admitted; its admission owner must confirm hangup.
                if ($reservation === null) {
                    $lock->release();
                }
            }
            throw $error;
        }
    }

    /** Process one event and close setup windows without limiting live calls. */
    public function pump(float $timeout): void
    {
        if ($timeout < 0) {
            throw new InvalidArgumentException('Invalid event timeout.');
        }
        $now = hrtime(true) / 1000000000;
        foreach ($this->sessions as $entry) {
            if ($entry['controller']->state() === Session::CONNECTING) {
                $timeout = min($timeout, max(0.0, $entry['deadline'] - $now));
            }
        }
        $event = $this->ami->waitForEvent(static function (array $event): bool {
            return in_array($event['Event'] ?? '', ['Hangup', 'UserEvent', 'Newchannel', 'OriginateResponse', 'ConfbridgeJoin'], true);
        }, $timeout);
        $this->dispatch($event);
    }

    /** Dispatch an event already consumed by a shared admission loop. */
    public function dispatch(?array $event): void
    {
        $this->router->dispatch($event);
        $now = hrtime(true) / 1000000000;
        foreach ($this->sessions as $id => $entry) {
            if ($entry['controller']->state() === Session::CONNECTING && $now >= $entry['deadline']) {
                $entry['loop']->finishSetup();
            }
            if ($entry['controller']->state() === Session::TERMINATED) {
                unset($this->sessions[$id]);
            }
        }
    }

    public function controller(string $sessionId): ?SessionController
    {
        return $this->sessions[$sessionId]['controller'] ?? null;
    }

    public function count(): int
    {
        return count($this->sessions);
    }
}
