<?php

namespace AdvancedPaging;

use RuntimeException;
use Throwable;

/**
 * Acquires the singleton lock and completes recovery before accepting calls.
 *
 * A service entry point must supply authenticated AMI, validated FreePBX
 * snapshot/recording loaders, signal handling and process supervision.
 * This object does not create credentials or configure a service.
 */
final class RuntimeBootstrap
{
    private GroupLock $lock;
    private CrashRecovery $recovery;
    private SessionRuntime $runtime;
    private AdmissionCoordinator $admission;
    private bool $closed = false;

    private function __construct(
        GroupLock $lock,
        CrashRecovery $recovery,
        SessionRuntime $runtime,
        AdmissionCoordinator $admission
    ) {
        $this->lock = $lock;
        $this->recovery = $recovery;
        $this->runtime = $runtime;
        $this->admission = $admission;
    }

    public static function start(
        AmiEventActions $ami,
        string $lockDirectory,
        callable $groupLoader,
        callable $recordingResolver,
        bool $allowLocalForTests = false,
        ?callable $log = null
    ): self {
        $lock = GroupLock::tryAcquireRuntime($lockDirectory);
        if ($lock === null) {
            throw new RuntimeException('Advanced Paging runtime is already running.');
        }
        try {
            $recovery = new CrashRecovery($ami, $lock);
            $recovery->recover();
            $runtime = new SessionRuntime($ami, $lockDirectory, $recordingResolver);
            $admission = new AdmissionCoordinator($ami, $runtime, $lockDirectory, $groupLoader, $allowLocalForTests, $log);
            return new self($lock, $recovery, $runtime, $admission);
        } catch (Throwable $error) {
            $lock->release();
            throw $error;
        }
    }

    public function pump(float $timeout): void
    {
        if ($this->closed) {
            throw new RuntimeException('Advanced Paging runtime is closed.');
        }
        $this->admission->pump($timeout);
    }

    public function sessionCount(): int
    {
        return $this->runtime->count();
    }

    public function pendingCount(): int
    {
        return $this->admission->pendingCount();
    }

    /**
     * Stop calls and verify cleanup before releasing the singleton lock.
     * The service process must exit after close so all group locks are freed.
     */
    public function close(?AmiEventActions $cleanupAmi = null): void
    {
        if ($this->closed) {
            return;
        }
        if ($cleanupAmi === null) {
            $this->recovery->recover();
        } else {
            (new CrashRecovery($cleanupAmi, $this->lock))->recover();
        }
        $this->lock->release();
        $this->closed = true;
    }
}
