<?php

namespace FreePBX\modules\Advancedpaging;

use FreePBX\modules\Backup as Base;

final class Backup extends Base\BackupBase
{
    public function runBackup($id, $transaction)
    {
        $this->addDependency('core');
        $this->addDependency('recordings');
        $this->addConfigs([
            'version' => 2,
            'groups' => $this->FreePBX->Advancedpaging->exportGroups(),
        ]);
    }
}
