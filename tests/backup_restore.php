<?php

namespace FreePBX\modules\Backup {
    class BackupBase {
        public $FreePBX;
        public array $dependencies = [];
        public array $configs = [];
        public function __construct($freepbx) { $this->FreePBX = $freepbx; }
        public function addDependency($name): void { $this->dependencies[] = $name; }
        public function addConfigs($config): void { $this->configs = $config; }
    }
    class RestoreBase {
        public $FreePBX;
        public $configs;
        public function __construct($freepbx, $configs) { $this->FreePBX = $freepbx; $this->configs = $configs; }
        public function getConfigs() { return $this->configs; }
    }
}

namespace {
    interface BMO { public function install(); public function uninstall(); }
    define('FPBX_LOG_NOTICE', 1);
    function freepbx_log($level, $message) {}
    function needreload() { $GLOBALS['reloadCount']++; }
    function framework_check_extension_usage($extension) { return $extension === '900' ? ['used'] : []; }
    require_once __DIR__ . '/../Advancedpaging.class.php';
    require_once __DIR__ . '/../Backup.php';
    require_once __DIR__ . '/../Restore.php';

    final class BackupTestCore {
        public function getAllUsers(): array { return ['100' => [], '101' => []]; }
    }
    final class BackupTestRecordings {
        public function getRecordingById($id): array { return in_array((string) $id, ['7', '8'], true) ? ['id' => $id] : []; }
    }
    function backupDb(bool $rejectMember = false): PDO {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE advancedpaging_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, extension TEXT NOT NULL UNIQUE, enabled INTEGER NOT NULL, audio_mode TEXT NOT NULL, pre_recording_id INTEGER, post_recording_id INTEGER)');
        $db->exec('CREATE TABLE advancedpaging_members (group_id INTEGER NOT NULL, extension TEXT NOT NULL '
            . ($rejectMember ? "CHECK(extension != '101')" : '') . ', PRIMARY KEY (group_id, extension))');
        return $db;
    }
    function backupModule(PDO $db): \FreePBX\modules\Advancedpaging {
        return new \FreePBX\modules\Advancedpaging((object) ['Database' => $db, 'Core' => new BackupTestCore(), 'Recordings' => new BackupTestRecordings()]);
    }
    function checkBackup(bool $value, string $message): void {
        if (!$value) { throw new RuntimeException($message); }
    }

    $GLOBALS['reloadCount'] = 0;
    $source = backupModule(backupDb());
    $source->saveGroup(['name' => 'Office', 'extension' => '500', 'enabled' => '1', 'audio_mode' => 'duplex',
        'members' => ['100', '101'], 'pre_recording_id' => '7', 'post_recording_id' => '8']);
    $backup = new \FreePBX\modules\Advancedpaging\Backup((object) ['Advancedpaging' => $source]);
    $backup->runBackup(null, null);
    checkBackup($backup->dependencies === ['core', 'recordings'], 'Missing backup dependencies.');
    checkBackup($backup->configs['version'] === 1 && count($backup->configs['groups']) === 1, 'Backup not exported.');
    checkBackup(!isset($backup->configs['groups'][0]['id']), 'Backup must not export internal group IDs.');
    $target = backupModule(backupDb());
    $restore = new \FreePBX\modules\Advancedpaging\Restore((object) ['Advancedpaging' => $target], $backup->configs);
    $restore->runRestore();
    $result = $target->getGroup(1);
    checkBackup($result['extension'] === '500' && $result['members'] === ['100', '101'], 'Backup round trip failed.');
    checkBackup((string) $result['pre_recording_id'] === '7' && (string) $result['post_recording_id'] === '8', 'Recording IDs not preserved.');
    try {
        $restore->runRestore();
        throw new RuntimeException('Existing group was overwritten.');
    } catch (PDOException $expected) {
        checkBackup(count($target->listGroups()) === 1, 'Conflict damaged existing groups.');
    }

    $two = $backup->configs['groups'];
    $two[] = $two[0];
    $two[1]['extension'] = '501';
    $db = backupDb(true);
    $rollback = backupModule($db);
    try {
        $rollback->restoreGroups($two);
        throw new RuntimeException('Expected member insert failure.');
    } catch (PDOException $expected) {
        checkBackup((int) $db->query('SELECT COUNT(*) FROM advancedpaging_groups')->fetchColumn() === 0, 'Restore did not roll back.');
    }
    $two[1]['extension'] = '900';
    try {
        backupModule(backupDb())->restoreGroups($two);
        throw new RuntimeException('Expected FreePBX extension conflict.');
    } catch (InvalidArgumentException $expected) {
    }
    $duplicate = [$two[0], $two[0]];
    $empty = backupDb();
    try {
        backupModule($empty)->restoreGroups($duplicate);
        throw new RuntimeException('Duplicate backup extension was accepted.');
    } catch (InvalidArgumentException $expected) {
        checkBackup((int) $empty->query('SELECT COUNT(*) FROM advancedpaging_groups')->fetchColumn() === 0, 'Invalid backup wrote partial groups.');
    }
    $missing = $two[0];
    $missing['post_recording_id'] = '999';
    try {
        backupModule(backupDb())->restoreGroups([$missing]);
        throw new RuntimeException('Missing recording was accepted.');
    } catch (InvalidArgumentException $expected) {
    }
    foreach ([99, '1invalid', null] as $version) {
        try {
            (new \FreePBX\modules\Advancedpaging\Restore((object) ['Advancedpaging' => $target], ['version' => $version, 'groups' => []]))->runRestore();
            throw new RuntimeException('Invalid backup version accepted.');
        } catch (RuntimeException $error) {
            checkBackup($error->getMessage() === 'Invalid or unsupported Advanced Paging backup.', 'Unexpected backup error.');
        }
    }
    echo "Backup round trip, dependency, conflict, and rollback tests passed.\n";
}
