<?php

namespace FreePBX\modules;

use AdvancedPaging\GroupRepository;
use AdvancedPaging\GroupValidator;
use AdvancedPaging\EndpointResolver;
use AdvancedPaging\AutoAnswerSettings;
use AdvancedPaging\RuntimeSnapshot;
use BMO;
use InvalidArgumentException;
use PDO;
use RuntimeException;

require_once __DIR__ . '/src/GroupRepository.php';
require_once __DIR__ . '/src/GroupValidator.php';
require_once __DIR__ . '/src/EndpointResolver.php';
require_once __DIR__ . '/src/AutoAnswerSettings.php';
require_once __DIR__ . '/src/RuntimeSnapshot.php';

/**
 * Development-only FreePBX adapter. The install guard prevents accidental
 * deployment before the call-control engine and UI are implemented and tested.
 */
final class Advancedpaging implements BMO
{
    private $freepbx;
    private GroupRepository $groups;

    public function __construct($freepbx = null)
    {
        if ($freepbx === null) {
            throw new InvalidArgumentException('FreePBX is required.');
        }
        $this->freepbx = $freepbx;
        $this->groups = new GroupRepository($freepbx->Database);
    }

    public function install()
    {
        throw new RuntimeException('Advanced Paging is a development preview and cannot be installed.');
    }

    public function uninstall()
    {
        // FreePBX owns the schema declared in module.xml.
    }

    public function listGroups(): array
    {
        return $this->groups->list();
    }

    public function getGroup(int $id): ?array
    {
        return $this->groups->get($id);
    }

    /** Resolve a session snapshot using Core and AstDB, never from-internal. */
    public function resolveEligibleDevices(array $members, string $callerExtension, string $callerDevice): array
    {
        $manager = $this->freepbx->astman;
        if (!$manager->connected()) {
            throw new RuntimeException('Asterisk manager is unavailable.');
        }
        $resolver = new EndpointResolver(
            function (): array { return $this->freepbx->Core->getAllUsers(); },
            static function (string $extension) use ($manager) {
                return $manager->database_get('AMPUSER', $extension . '/device');
            },
            function (string $device): array { return $this->freepbx->Core->getDevice($device); },
            static function (string $endpoint) use ($manager): string {
                $result = $manager->GetVar(null, 'DEVICE_STATE(' . $endpoint . ')');
                return ($result['Response'] ?? '') === 'Success' ? (string) ($result['Value'] ?? '') : '';
            }
        );
        return $resolver->resolve($members, $callerExtension, $callerDevice);
    }

    public function getPagingAutoAnswerDefaults(): array
    {
        return AutoAnswerSettings::fromPagingDefaults(paging_get_autoanswer_defaults());
    }

    /** Resolve configuration and media once, without exposing credentials. */
    public function loadRuntimeSnapshot(int $groupId, array $event): array
    {
        $manager = $this->freepbx->astman;
        if (!$manager->connected()) {
            throw new RuntimeException('Asterisk manager is unavailable.');
        }
        $loader = new RuntimeSnapshot(
            function (int $id): ?array { return $this->getGroup($id); },
            function (string $device) use ($manager) {
                $record = $this->freepbx->Core->getDevice($device);
                if (strtolower((string) ($record['tech'] ?? '')) !== 'pjsip'
                    || (string) ($record['id'] ?? '') !== $device) {
                    return null;
                }
                $user = $manager->database_get('DEVICE', $device . '/user');
                $users = $this->freepbx->Core->getAllUsers();
                return is_string($user) && isset($users[$user]) ? $user : null;
            },
            function (array $members, string $caller, string $device): array {
                return $this->resolveEligibleDevices($members, $caller, $device);
            },
            function (): array { return $this->getPagingAutoAnswerDefaults(); },
            function (string $id) use ($groupId): string {
                $record = $this->freepbx->Recordings->getRecordingById($id);
                if (empty($record['filename']) || empty($record['soundlist'])) {
                    freepbx_log(FPBX_LOG_WARNING, 'Advanced Paging: missing recording ' . $id . ' for group ' . $groupId);
                    return '';
                }
                foreach ($record['soundlist'] as $sound) {
                    if (empty($sound['languages'])) {
                        freepbx_log(FPBX_LOG_WARNING, 'Advanced Paging: unavailable recording ' . $id . ' for group ' . $groupId);
                        return '';
                    }
                }
                return (string) $record['filename'];
            }
        );
        return $loader->load($groupId, $event);
    }

    public function saveGroup(array $input, ?int $id = null): int
    {
        $previous = $id === null ? null : $this->groups->get($id);
        if ($id !== null && $previous === null) {
            throw new InvalidArgumentException('Group does not exist.');
        }
        $validator = $this->groupValidator();
        $group = $validator->validate($input, $previous['extension'] ?? null);
        $id = $this->groups->save($group, $id);
        freepbx_log(FPBX_LOG_NOTICE, 'Advanced Paging group ' . $id . ($previous === null ? ' created' : ' modified'));
        needreload();
        return $id;
    }

    /** Export configuration only; runtime state, credentials and audio excluded. */
    public function exportGroups(): array
    {
        $db = $this->freepbx->Database;
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            $result = [];
            foreach ($this->groups->list() as $entry) {
                $group = $this->groups->get((int) $entry['id']);
                if ($group === null) {
                    throw new RuntimeException('Paging group changed during backup.');
                }
                $result[] = [
                    'name' => $group['name'], 'extension' => $group['extension'],
                    'enabled' => (int) $group['enabled'], 'audio_mode' => $group['audio_mode'],
                    'members' => $group['members'], 'pre_recording_id' => $group['pre_recording_id'],
                    'post_recording_id' => $group['post_recording_id'],
                ];
            }
            if ($ownTransaction) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $error) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Restore groups without replacing existing groups. Validate the whole
     * input before writing and roll back the entire set on any database error.
     */
    public function restoreGroups(array $groups): array
    {
        $validator = $this->groupValidator();
        $normalized = [];
        $extensions = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                throw new InvalidArgumentException('Invalid paging group backup.');
            }
            $valid = $validator->validate($group);
            if (isset($extensions[$valid['extension']])) {
                throw new InvalidArgumentException('The backup contains duplicate paging extensions.');
            }
            $extensions[$valid['extension']] = true;
            $normalized[] = $valid;
        }
        $ids = $this->groups->insertMany($normalized);
        if ($ids) {
            freepbx_log(FPBX_LOG_NOTICE, 'Advanced Paging restored ' . count($ids) . ' groups');
            needreload();
        }
        return $ids;
    }

    private function groupValidator(): GroupValidator
    {
        $users = $this->freepbx->Core->getAllUsers();
        return new GroupValidator(
            static function (string $extension): bool {
                return !empty(framework_check_extension_usage($extension));
            },
            static function (string $extension) use ($users): bool {
                return isset($users[$extension]);
            },
            function (string $id): bool {
                return !empty($this->freepbx->Recordings->getRecordingById($id));
            }
        );
    }

    public function deleteGroup(int $id): bool
    {
        $deleted = $this->groups->delete($id);
        if ($deleted) {
            freepbx_log(FPBX_LOG_NOTICE, 'Advanced Paging group ' . $id . ' deleted');
            needreload();
        }
        return $deleted;
    }

    /** FreePBX Core invokes this when deleting an extension, not on edits. */
    public function delUser($extension, $editmode = false): void
    {
        if ($editmode || !is_string($extension) || !preg_match('/^[0-9]{1,25}$/D', $extension)) {
            return;
        }
        $query = $this->freepbx->Database->prepare('DELETE FROM advancedpaging_members WHERE extension = ?');
        $query->execute([$extension]);
        if ($query->rowCount() > 0) {
            needreload();
        }
    }
}
