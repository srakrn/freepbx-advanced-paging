<?php

namespace AdvancedPaging;

use RuntimeException;
use Throwable;

/**
 * Foreground runtime loop. A supervisor is responsible for starting and
 * restarting the process; every successful connection reconciles first.
 */
final class RuntimeService
{
    private $connect;
    private string $lockDirectory;
    private $groupLoader;
    private $recordingResolver;
    private $log;
    private $ready;
    private bool $allowLocalForTests;
    private bool $stopping = false;

    public function __construct(
        callable $connect,
        string $lockDirectory,
        callable $groupLoader,
        callable $recordingResolver,
        callable $log,
        callable $ready,
        bool $allowLocalForTests = false
    ) {
        $this->connect = $connect;
        $this->lockDirectory = $lockDirectory;
        $this->groupLoader = $groupLoader;
        $this->recordingResolver = $recordingResolver;
        $this->log = $log;
        $this->ready = $ready;
        $this->allowLocalForTests = $allowLocalForTests;
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function run(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            throw new RuntimeException('PCNTL is required for runtime signal handling.');
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void { $this->stop(); });
        pcntl_signal(SIGINT, function (): void { $this->stop(); });
        $failures = 0;
        while (!$this->stopping) {
            $ami = null;
            $bootstrap = null;
            try {
                $ami = ($this->connect)();
                if (!$ami instanceof AmiConnection) {
                    throw new RuntimeException('AMI connector returned an invalid connection.');
                }
                $bootstrap = RuntimeBootstrap::start(
                    $ami, $this->lockDirectory, $this->groupLoader,
                    $this->recordingResolver, $this->allowLocalForTests
                );
                $failures = 0;
                ($this->ready)();
                while (!$this->stopping) {
                    $bootstrap->pump(0.5);
                }
                $bootstrap->close();
            } catch (Throwable $error) {
                // Do not log exception text: connection/authentication errors
                // can contain sensitive configuration in third-party code.
                ($this->log)('Advanced Paging runtime connection or recovery failed.');
                if ($this->stopping) {
                    if ($bootstrap !== null) {
                        $bootstrap->close();
                        return;
                    }
                    throw $error;
                }
                if (++$failures >= 5) {
                    throw new RuntimeException('Repeated Advanced Paging runtime failures.', 0, $error);
                }
            } finally {
                if ($ami instanceof AmiConnection) {
                    $ami->close();
                }
                // Drop all references to group/singleton locks before retry;
                // the next bootstrap must reconcile before re-admitting calls.
                unset($bootstrap, $ami);
                gc_collect_cycles();
            }
            if (!$this->stopping) {
                usleep(500000);
            }
        }
    }
}
