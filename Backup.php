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
            'version' => 3,
            'groups' => $this->FreePBX->Advancedpaging->exportGroups(),
            'settings' => ['drop_silence' => $this->FreePBX->Advancedpaging->getDropSilence()],
        ]);
    }
}
