<?php

namespace AdvancedPaging;

use RuntimeException;

/**
 * Synchronous AMI action connection with event buffering.
 * The owning runtime must serialize access and drain events between requests.
 */
final class AmiConnection implements AmiEventActions
{
    private $socket;
    private array $events = [];
    private int $counter = 0;

    public function __construct($socket)
    {
        if (!is_resource($socket) || get_resource_type($socket) !== 'stream') {
            throw new RuntimeException('AMI requires an open stream.');
        }
        $this->socket = $socket;
        stream_set_read_buffer($this->socket, 0);
    }

    public function request(array $headers): void
    {
        if (empty($headers['Action'])) {
            throw new RuntimeException('Invalid AMI request.');
        }
        if (isset($headers['ActionID'])) {
            $actionId = $headers['ActionID'];
            if (!is_string($actionId) || !preg_match('/^ap-orig-[a-f0-9]{32}$/D', $actionId)) {
                throw new RuntimeException('Invalid AMI action identifier.');
            }
        } else {
            $actionId = 'ap-' . bin2hex(random_bytes(12)) . '-' . ++$this->counter;
        }
        $headers['ActionID'] = $actionId;
        $request = '';
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name)
                || (is_array($value) && $name !== 'Variable')) {
                throw new RuntimeException('Invalid AMI header.');
            }
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ((!is_string($item) && !is_int($item)) || strpbrk((string) $item, "\r\n") !== false) {
                    throw new RuntimeException('Invalid AMI header.');
                }
                $request .= $name . ': ' . $item . "\r\n";
            }
        }
        $request .= "\r\n";
        $remaining = $request;
        while ($remaining !== '') {
            $written = fwrite($this->socket, $remaining);
            if ($written === false || $written === 0) {
                throw new RuntimeException('AMI write failed.');
            }
            $remaining = substr($remaining, $written);
        }
        while (true) {
            $frame = $this->readFrame();
            if (isset($frame['Event'])) {
                $this->events[] = $frame;
            } elseif (($frame['ActionID'] ?? null) === $actionId && isset($frame['Response'])) {
                if (strcasecmp($frame['Response'], 'Success') !== 0) {
                    throw new RuntimeException('AMI action failed: ' . ($frame['Message'] ?? 'unknown error'));
                }
                return;
            } else {
                throw new RuntimeException('Unexpected AMI response.');
            }
        }
    }

    public function drainEvents(): array
    {
        $events = $this->events;
        $this->events = [];
        return $events;
    }

    /**
     * Consume one matching AMI event while retaining unrelated events.
     * Returns null on timeout. This connection must have a finite read timeout.
     */
    public function waitForEvent(callable $predicate, float $timeout): ?array
    {
        if ($timeout < 0) {
            throw new RuntimeException('Invalid AMI event timeout.');
        }
        $deadline = microtime(true) + $timeout;
        do {
            foreach ($this->events as $index => $event) {
                if ($predicate($event)) {
                    array_splice($this->events, $index, 1);
                    return $event;
                }
            }
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }
            $read = [$this->socket];
            $write = null;
            $except = null;
            $seconds = (int) $remaining;
            $micros = (int) (($remaining - $seconds) * 1000000);
            $ready = stream_select($read, $write, $except, $seconds, $micros);
            if ($ready === false) {
                throw new RuntimeException('AMI event wait failed.');
            }
            if ($ready === 0) {
                return null;
            }
            $frame = $this->readFrame();
            if (!isset($frame['Event'])) {
                throw new RuntimeException('Unexpected AMI response during event wait.');
            }
            $this->events[] = $frame;
        } while (true);
    }

    private function readFrame(): array
    {
        $frame = [];
        while (true) {
            $line = fgets($this->socket, 8192);
            if ($line === false) {
                throw new RuntimeException('AMI read failed or timed out.');
            }
            if (substr($line, -1) !== "\n") {
                throw new RuntimeException('AMI header too long.');
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($frame) {
                    return $frame;
                }
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                throw new RuntimeException('Malformed AMI frame.');
            }
            $name = substr($line, 0, $separator);
            $frame[$name] = ltrim(substr($line, $separator + 1), ' ');
        }
    }
}
