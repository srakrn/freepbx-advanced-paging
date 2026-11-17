<?php

namespace AdvancedPaging;

use RuntimeException;
use Throwable;

/**
 * Fail-closed startup reconciliation after exclusive runtime lock acquisition.
 *
 * Removes only module-namespaced conferences, module-assigned channel IDs,
 * and callers still executing the module-owned entry context. It does not
 * restore calls. AMI event subscription must already be active and exclusive
 * group admission must remain disabled until recovery completes.
 */
final class CrashRecovery
{
    private AmiEventActions $ami;
    private GroupLock $runtimeLock;

    public function __construct(AmiEventActions $ami, GroupLock $runtimeLock)
    {
        if (!$runtimeLock->holds(0)) {
            throw new RuntimeException('Exclusive runtime lock is required for recovery.');
        }
        $this->ami = $ami;
        $this->runtimeLock = $runtimeLock;
    }

    public function recover(float $timeoutSeconds = 10.0): void
    {
        if ($timeoutSeconds <= 0 || !$this->runtimeLock->holds(0)) {
            throw new RuntimeException('Invalid recovery state.');
        }
        $deadline = hrtime(true) / 1000000000 + $timeoutSeconds;
        do {
            $rooms = $this->listEvents('ConfbridgeListRooms', 'ConfbridgeListRooms', 'ConfbridgeListRoomsComplete');
            $channels = $this->listEvents('CoreShowChannels', 'CoreShowChannel', 'CoreShowChannelsComplete');
            $ownedRooms = [];
            foreach ($rooms as $room) {
                $name = $room['Conference'] ?? '';
                if (is_string($name) && preg_match('/^ap-[a-f0-9]{32}$/D', $name)) {
                    $ownedRooms[$name] = true;
                }
            }
            $ownedChannels = [];
            foreach ($channels as $channel) {
                $id = $channel['Uniqueid'] ?? '';
                $name = $channel['Channel'] ?? '';
                $ownedId = is_string($id) && preg_match('/^ap-(?:recv|play)-[a-f0-9]{32}(?:-peer)?$/D', $id);
                $ownedCaller = ($channel['Context'] ?? '') === 'app-advancedpaging'
                    && preg_match('/^[1-9][0-9]*$/D', (string) ($channel['Exten'] ?? ''));
                if (($ownedId || $ownedCaller) && is_string($name) && $name !== ''
                    && $name[0] !== '/' && strpbrk($name, "\r\n") === false) {
                    $ownedChannels[$name] = true;
                }
            }
            if (!$ownedRooms && !$ownedChannels) {
                return;
            }
            foreach (array_keys($ownedRooms) as $room) {
                try {
                    $this->ami->request(['Action' => 'ConfbridgeKick', 'Conference' => $room, 'Channel' => 'all']);
                } catch (Throwable $error) {
                    // A disappearing conference is harmless only if a fresh
                    // listing confirms it is gone.
                }
            }
            foreach (array_keys($ownedChannels) as $channel) {
                try {
                    $this->ami->request(['Action' => 'Hangup', 'Channel' => $channel]);
                } catch (Throwable $error) {
                    // The same rule applies to disappearing channels.
                }
            }
            usleep(100000);
        } while (hrtime(true) / 1000000000 < $deadline);
        throw new RuntimeException('Could not confirm Advanced Paging crash cleanup.');
    }

    private function listEvents(string $action, string $itemEvent, string $completeEvent): array
    {
        $id = 'ap-list-' . bin2hex(random_bytes(16));
        try {
            $this->ami->request(['Action' => $action, 'ActionID' => $id]);
        } catch (AmiActionException $error) {
            if ($action === 'ConfbridgeListRooms' && $error->getMessage() === 'AMI action failed: No active conferences.') {
                return [];
            }
            throw $error;
        }
        $events = [];
        $deadline = hrtime(true) / 1000000000 + 5.0;
        do {
            $remaining = $deadline - hrtime(true) / 1000000000;
            if ($remaining <= 0) {
                break;
            }
            $event = $this->ami->waitForEvent(static function (array $event) use ($id, $itemEvent, $completeEvent): bool {
                return ($event['ActionID'] ?? '') === $id
                    && in_array($event['Event'] ?? '', [$itemEvent, $completeEvent], true);
            }, $remaining);
            if ($event === null) {
                break;
            }
            if ($event['Event'] === $completeEvent) {
                return $events;
            }
            $events[] = $event;
        } while (true);
        throw new RuntimeException('AMI recovery listing did not complete.');
    }
}
