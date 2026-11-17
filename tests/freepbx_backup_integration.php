<?php

// Exercise the installed FreePBX 17 BackupBase and RestoreBase against two
// explicitly supplied temporary MariaDB databases, without FreePBX bootstrap.
interface BMO { public function install(); public function uninstall(); }
define('FPBX_LOG_NOTICE', 1);
function freepbx_log($level, $message) {}
function needreload() {}
function framework_check_extension_usage($extension) { return []; }

require '/var/www/html/admin/libraries/Composer/vendor/autoload.php';
require '/var/www/html/admin/libraries/modulefunctions.class.php';
require '/var/www/html/admin/modules/backup/Models/ModelBase.php';
require '/var/www/html/admin/modules/backup/Models/Backup.php';
require '/var/www/html/admin/modules/backup/Handlers/FreePBXModule.php';
require '/var/www/html/admin/modules/backup/Models/Restore.php';
require '/var/www/html/admin/modules/backup/BackupBase.php';
require '/var/www/html/admin/modules/backup/RestoreBase.php';
require_once __DIR__ . '/../Advancedpaging.class.php';
require_once __DIR__ . '/../Backup.php';
require_once __DIR__ . '/../Restore.php';

final class RealBackupTestCore {
    public function getAllUsers(): array { return ['100' => [], '101' => []]; }
}
final class RealBackupTestRecordings {
    public function getRecordingById($id): array { return (string) $id === '7' ? ['id' => 7] : []; }
}
function createBackupModule(string $database, string $socket): \FreePBX\modules\Advancedpaging {
    $db = new PDO('mysql:unix_socket=' . $socket . ';dbname=' . $database, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE advancedpaging_groups (id INT PRIMARY KEY AUTO_INCREMENT, extension VARCHAR(25) NOT NULL UNIQUE, name VARCHAR(255), enabled TINYINT NOT NULL, audio_mode VARCHAR(10) NOT NULL, pre_recording_id INT NULL, post_recording_id INT NULL) ENGINE=InnoDB');
    $db->exec('CREATE TABLE advancedpaging_members (group_id INT NOT NULL, extension VARCHAR(25) NOT NULL, PRIMARY KEY (group_id, extension)) ENGINE=InnoDB');
    return new \FreePBX\modules\Advancedpaging((object) ['Database' => $db, 'Core' => new RealBackupTestCore(), 'Recordings' => new RealBackupTestRecordings()]);
}
$socket = getenv('AP_LAB_MYSQL_SOCKET');
if (!$socket) {
    throw new RuntimeException('Missing isolated database socket.');
}
$source = createBackupModule('ap_backup_source', $socket);
$source->saveGroup(['name' => 'Source', 'extension' => '500', 'enabled' => '1', 'audio_mode' => 'duplex',
    'members' => ['100', '101'], 'pre_recording_id' => '7', 'post_recording_id' => null]);
$backup = new \FreePBX\modules\Advancedpaging\Backup((object) ['Advancedpaging' => $source], '17', null, 'test', [], false);
$backup->runBackup('test', 'test');
$data = $backup->getData();
if ($backup->getDependencies() !== ['core', 'recordings'] || count($backup->getConfigs()['groups']) !== 1) {
    throw new RuntimeException('Actual FreePBX backup model did not retain expected data.');
}
$target = createBackupModule('ap_backup_target', $socket);
$target->saveGroup(['name' => 'Existing', 'extension' => '600', 'enabled' => '1', 'audio_mode' => 'simplex',
    'members' => ['101'], 'pre_recording_id' => null, 'post_recording_id' => null]);
$restore = new \FreePBX\modules\Advancedpaging\Restore((object) ['Advancedpaging' => $target], '17', null, 'test', $data, dirname($socket), false);
$restore->reset();
$restore->runRestore();
$groups = $target->listGroups();
if (count($groups) !== 2 || $groups[0]['extension'] !== '500' || $groups[1]['extension'] !== '600') {
    throw new RuntimeException('Actual FreePBX restore did not preserve existing groups.');
}
try {
    $restore->runRestore();
    throw new RuntimeException('Restore overwrote an existing group.');
} catch (PDOException $expected) {
    if (count($target->listGroups()) !== 2) {
        throw new RuntimeException('Restore conflict modified existing data.');
    }
}
echo "FreePBX 17 backup/restore base integration preserved existing groups and rejected conflicts.\n";
