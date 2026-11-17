<?php

namespace AdvancedPaging;

use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves FreePBX extension/device mappings without calling from-internal,
 * following call forwarding, or using arbitrary configured dial strings.
 *
 * State lookup must return the Asterisk DEVICE_STATE value. Unknown, busy,
 * ringing, unavailable and exceptional states are deliberately skipped.
 */
final class EndpointResolver
{
    private $users;
    private $userDevices;
    private $device;
    private $state;
    private $contacts;

    public function __construct(callable $users, callable $userDevices, callable $device, callable $state, ?callable $contacts = null)
    {
        $this->users = $users;
        $this->userDevices = $userDevices;
        $this->device = $device;
        $this->state = $state;
        $this->contacts = $contacts;
    }

    /** Returns registered PJSIP contacts, never dialplan destinations. */
    public function resolve(array $members, string $callerExtension, string $callerDevice): array
    {
        $users = ($this->users)();
        if (!is_array($users)) {
            throw new RuntimeException('Cannot resolve FreePBX extensions.');
        }
        $targets = [];
        foreach ($members as $extension) {
            if (!is_string($extension) || !preg_match('/^[0-9]{1,25}$/D', $extension)) {
                throw new InvalidArgumentException('Invalid receiving extension.');
            }
            if ($extension === $callerExtension || !isset($users[$extension])) {
                continue;
            }
            $list = ($this->userDevices)($extension);
            if (!is_string($list)) {
                throw new RuntimeException('Cannot resolve extension devices.');
            }
            foreach (explode('&', $list) as $deviceId) {
                if ($deviceId === '' || $deviceId === $callerDevice) {
                    continue;
                }
                if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $deviceId)) {
                    throw new RuntimeException('Invalid FreePBX device identifier.');
                }
                $device = ($this->device)($deviceId);
                if (!is_array($device) || strtolower((string) ($device['tech'] ?? '')) !== 'pjsip'
                    || (string) ($device['id'] ?? '') !== $deviceId) {
                    continue;
                }
                $endpoint = 'PJSIP/' . $deviceId;
                if (($this->state)($endpoint) === 'NOT_INUSE') {
                    $contacts = $this->contacts === null ? [] : ($this->contacts)($deviceId);
                    if (!is_array($contacts)) {
                        throw new RuntimeException('Cannot resolve registered PJSIP contacts.');
                    }
                    foreach ($contacts as $contact) {
                        if (!is_array($contact)) {
                            continue;
                        }
                        $uri = $contact['uri'] ?? '';
                        $agent = $contact['user_agent'] ?? '';
                        if (!is_string($uri) || !preg_match('/^[A-Za-z0-9_.:@+;%=\-\[\]]{1,512}$/D', $uri)
                            || !is_string($agent) || strlen($agent) > 255
                            || strpbrk($agent, "\r\n") !== false) {
                            continue;
                        }
                        $target = 'PJSIP/' . $deviceId . '/' . $uri;
                        $targets[$target] = ['channel' => $target, 'endpoint' => $deviceId, 'user_agent' => $agent];
                    }
                }
            }
        }
        return array_values($targets);
    }
}
