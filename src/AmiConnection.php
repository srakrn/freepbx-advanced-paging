<?php

namespace AdvancedPaging;

use RuntimeException;

/**
 * Synchronous AMI action connection with event buffering.
 * The owning runtime must serialize access and drain events between requests.
 */
final class AmiConnection implements AmiActions
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
    }

    public function request(array $headers): void
    {
        if (empty($headers['Action']) || isset($headers['ActionID'])) {
            throw new RuntimeException('Invalid AMI request.');
        }
        $actionId = 'ap-' . bin2hex(random_bytes(12)) . '-' . ++$this->counter;
        $headers['ActionID'] = $actionId;
        $request = '';
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name)
                || (!is_string($value) && !is_int($value))
                || strpbrk((string) $value, "\r\n") !== false) {
                throw new RuntimeException('Invalid AMI header.');
            }
            $request .= $name . ': ' . $value . "\r\n";
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
