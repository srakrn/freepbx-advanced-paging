<?php

namespace FreePBX\modules\Advancedpaging;

use FreePBX\modules\Backup as Base;
use RuntimeException;

final class Restore extends Base\RestoreBase
{
    public function runRestore()
    {
        $config = $this->getConfigs();
        if (!is_array($config) || !in_array($config['version'] ?? null, [1, '1'], true)
            || !isset($config['groups']) || !is_array($config['groups'])) {
            throw new RuntimeException('Invalid or unsupported Advanced Paging backup.');
        }
        $this->FreePBX->Advancedpaging->restoreGroups($config['groups']);
    }
}
