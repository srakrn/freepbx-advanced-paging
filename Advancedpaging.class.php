<?php

namespace FreePBX\modules;

use AdvancedPaging\GroupRepository;
use AdvancedPaging\GroupValidator;
use AdvancedPaging\EndpointResolver;
use AdvancedPaging\AutoAnswerSettings;
use AdvancedPaging\RuntimeSnapshot;
use AdvancedPaging\RuntimeProvisioner;
use AdvancedPaging\GroupLock;
use AdvancedPaging\SchemaMigrator;
use BMO;
use InvalidArgumentException;
use PDO;
use RuntimeException;

require_once __DIR__ . '/src/GroupRepository.php';
require_once __DIR__ . '/src/GroupValidator.php';
require_once __DIR__ . '/src/EndpointResolver.php';
require_once __DIR__ . '/src/AutoAnswerSettings.php';
require_once __DIR__ . '/src/RuntimeSnapshot.php';
require_once __DIR__ . '/src/RuntimeProvisioner.php';
require_once __DIR__ . '/src/GroupLock.php';
require_once __DIR__ . '/src/SchemaMigrator.php';

/**
 * FreePBX module adapter.
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

    /**
     * The BMO config-page lifecycle calls this hook for every module page.
     * Advanced Paging processes its page actions in the page controller.
     */
    public function doConfigPageInit($page): void
    {
    }

    public function install()
    {
        return $this->installAt('/etc/asterisk', '/run/advancedpaging', 'asterisk', 'asterisk');
    }

    /** Same lifecycle with injectable paths for isolated tests. */
    public function installAt(string $asteriskDirectory, string $runtimeDirectory, ?string $ownerUser, ?string $ownerGroup): bool
    {
        try {
            SchemaMigrator::ensureUniqueExtensions($this->freepbx->Database);
            $this->ensureRuntimeDirectory($runtimeDirectory, $ownerUser, $ownerGroup);
            (new RuntimeProvisioner($asteriskDirectory, $runtimeDirectory, null, null, $ownerUser, $ownerGroup))->install();
            freepbx_log(FPBX_LOG_NOTICE, 'Advanced Paging schema and AMI runtime account provisioned');
            needreload();
            return true;
        } catch (\Throwable $error) {
            freepbx_log(FPBX_LOG_ERROR, 'Advanced Paging installation failed safely: ' . $error->getMessage());
            return false;
        }
    }

    public function uninstall()
    {
        return $this->uninstallAt('/etc/asterisk', '/run/advancedpaging', null, null);
    }

    public function uninstallAt(string $asteriskDirectory, string $runtimeDirectory, ?string $ownerUser, ?string $ownerGroup): bool
    {
        try {
            // systemd may remove RuntimeDirectory when stopping the service.
            $this->ensureRuntimeDirectory($runtimeDirectory, $ownerUser, $ownerGroup);
        } catch (\Throwable $error) {
            freepbx_log(FPBX_LOG_ERROR, 'Advanced Paging uninstall could not prepare the runtime lock directory');
            return false;
        }
        $lock = GroupLock::tryAcquireRuntime($runtimeDirectory);
        if ($lock === null) {
            freepbx_log(FPBX_LOG_WARNING, 'Stop the Advanced Paging runtime before uninstalling the module');
            return false;
        }
        try {
            (new RuntimeProvisioner($asteriskDirectory, $runtimeDirectory, null, null, $ownerUser, $ownerGroup))->uninstall();
            $lock->release();
            freepbx_log(FPBX_LOG_NOTICE, 'Advanced Paging AMI runtime account removed');
            needreload();
            return true;
        } catch (\Throwable $error) {
            $lock->release();
            freepbx_log(FPBX_LOG_ERROR, 'Advanced Paging uninstall failed safely: ' . $error->getMessage());
            return false;
        }
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
            },
            function (string $device) use ($manager): array { return $this->registeredContacts($manager, $device); }
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
            function (array $targets): array { return $this->getAutoAnswerForTargets($targets); },
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

    private function registeredContacts($manager, string $device): array
    {
        $rows = $manager->database_show('registrar/contact');
        if (!is_array($rows)) {
            throw new RuntimeException('Cannot load PJSIP registrations.');
        }
        $prefix = '/registrar/contact/' . $device . ';';
        $result = [];
        foreach ($rows as $key => $json) {
            if (!is_string($key) || strpos($key, $prefix) !== 0 || !is_string($json)) {
                continue;
            }
            $contact = json_decode($json, true);
            if (!is_array($contact) || empty($contact['uri']) || empty($contact['user_agent'])) {
                continue;
            }
            $result[] = ['uri' => $contact['uri'], 'user_agent' => $contact['user_agent']];
        }
        return $result;
    }

    /**
     * Reuse existing Paging user-agent overrides for alert headers only.
     * Paging-specific Dial options and expressions are intentionally ignored.
     */
    private function getAutoAnswerForTargets(array $targets): array
    {
        $defaults = $this->getPagingAutoAnswerDefaults();
        $statement = $this->freepbx->Database->query(
            "SELECT useragent, var, setting FROM paging_autoanswer WHERE useragent != 'default' ORDER BY LENGTH(useragent) DESC, useragent, var"
        );
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $overrides = [];
        foreach ($targets as $target) {
            if (!is_array($target) || empty($target['channel']) || empty($target['user_agent'])) {
                continue;
            }
            $perTarget = [];
            foreach ($rows as $row) {
                $prefix = (string) ($row['useragent'] ?? '');
                $variable = (string) ($row['var'] ?? '');
                if ($prefix === '' || strpos((string) $target['user_agent'], $prefix) !== 0
                    || !in_array($variable, ['ALERTINFO', 'CALLINFO'], true)
                    || array_key_exists($variable, $perTarget)) {
                    continue;
                }
                $perTarget[$variable] = AutoAnswerSettings::validate((string) ($row['setting'] ?? ''));
            }
            $overrides[$target['channel']] = $perTarget;
        }
        return ['ALERTINFO' => $defaults['ALERTINFO'], 'CALLINFO' => $defaults['CALLINFO'], 'overrides' => $overrides];
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

    private function ensureRuntimeDirectory(string $path, ?string $ownerUser, ?string $ownerGroup): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Runtime directory must not be a symlink.');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create Advanced Paging runtime directory.');
        }
        if (!chmod($path, 0700)) {
            throw new RuntimeException('Cannot secure Advanced Paging runtime directory.');
        }
        if ($ownerUser !== null || $ownerGroup !== null) {
            if (!function_exists('posix_getpwnam') || !function_exists('posix_getgrnam')) {
                throw new RuntimeException('POSIX ownership functions are required.');
            }
            $user = $ownerUser === null ? null : posix_getpwnam($ownerUser);
            $group = $ownerGroup === null ? null : posix_getgrnam($ownerGroup);
            if (($ownerUser !== null && $user === false) || ($ownerGroup !== null && $group === false)
                || ($user !== null && $user !== false && !chown($path, $user['uid']))
                || ($group !== null && $group !== false && !chgrp($path, $group['gid']))) {
                throw new RuntimeException('Cannot assign runtime directory to Asterisk.');
            }
        }
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
