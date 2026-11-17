<?php

namespace FreePBX\modules\Advancedpaging;

use FreePBX\modules\Backup as Base;
use RuntimeException;

final class Restore extends Base\RestoreBase
{
    /**
     * FreePBX's default reset uninstalls the module and drops its tables.
     * Preserve existing groups; runRestore rejects collisions transactionally.
     */
    public function reset()
    {
    }

    public function runRestore()
    {
        $config = $this->getConfigs();
        if (!is_array($config) || !in_array($config['version'] ?? null, [1, '1', 2, '2', 3, '3'], true)
            || !isset($config['groups']) || !is_array($config['groups'])) {
            throw new RuntimeException('Invalid or unsupported Advanced Paging backup.');
        }
        $enabled = null;
        if (in_array($config['version'], [3, '3'], true)) {
            if (!is_array($config['settings'] ?? null)) {
                throw new RuntimeException('Invalid or unsupported Advanced Paging backup settings.');
            }
            $enabled = $config['settings']['drop_silence'] ?? null;
            if (!in_array($enabled, [true, false, 0, 1, '0', '1'], true)) {
                throw new RuntimeException('Invalid or unsupported Advanced Paging backup settings.');
            }
        }
        $this->FreePBX->Advancedpaging->restoreGroups($config['groups']);
        if ($enabled !== null) {
            $this->FreePBX->Advancedpaging->setDropSilence($enabled);
            needreload();
        }
    }
}
