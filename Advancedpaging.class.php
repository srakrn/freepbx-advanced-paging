<?php

namespace FreePBX\modules;

use AdvancedPaging\GroupRepository;
use AdvancedPaging\GroupValidator;
use BMO;
use InvalidArgumentException;
use PDO;
use RuntimeException;

require_once __DIR__ . '/src/GroupRepository.php';
require_once __DIR__ . '/src/GroupValidator.php';

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

    public function saveGroup(array $input, ?int $id = null): int
    {
        $previous = $id === null ? null : $this->groups->get($id);
        if ($id !== null && $previous === null) {
            throw new InvalidArgumentException('Group does not exist.');
        }
        $users = $this->freepbx->Core->getAllUsers();
        $validator = new GroupValidator(
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
        $group = $validator->validate($input, $previous['extension'] ?? null);
        $id = $this->groups->save($group, $id);
        needreload();
        return $id;
    }

    public function deleteGroup(int $id): bool
    {
        $deleted = $this->groups->delete($id);
        if ($deleted) {
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
