<?php

interface BMO { public function install(); public function uninstall(); }
define('FPBX_LOG_NOTICE', 1);
define('FPBX_LOG_WARNING', 2);
define('FPBX_LOG_ERROR', 3);
function freepbx_log($level, $message) { $GLOBALS['logs'][] = $message; }
function needreload() { ++$GLOBALS['reloads']; }
function framework_check_extension_usage($extension) { return []; }
require_once __DIR__ . '/../Advancedpaging.class.php';

final class LifecycleCore
{
    public function getAllUsers(): array { return ['100' => []]; }
}
final class LifecycleRecordings
{
    public function getRecordingById($id): array { return ['id' => $id]; }
}

$root = sys_get_temp_dir() . '/ap-module-life-' . bin2hex(random_bytes(8));
$etc = $root . '/etc';
$run = $root . '/run';
mkdir($etc, 0700, true);
mkdir($run, 0700, true);
file_put_contents($etc . '/manager_custom.conf', "; site config\n");
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE advancedpaging_groups (id INTEGER PRIMARY KEY, extension TEXT NOT NULL, name TEXT, enabled INTEGER NOT NULL, audio_mode TEXT NOT NULL, pre_recording_id INTEGER NULL, post_recording_id INTEGER NULL)');
$db->exec('CREATE TABLE advancedpaging_members (group_id INTEGER NOT NULL, extension TEXT NOT NULL, PRIMARY KEY(group_id, extension))');
$module = new \FreePBX\modules\Advancedpaging((object) ['Database' => $db, 'Core' => new LifecycleCore(), 'Recordings' => new LifecycleRecordings()]);
$GLOBALS['reloads'] = 0;
$GLOBALS['logs'] = [];
try {
    if (!$module->installAt($etc, $run, null, null)) {
        throw new RuntimeException('Module install hook failed.');
    }
    $indexes = $db->query('PRAGMA index_list(advancedpaging_groups)')->fetchAll(PDO::FETCH_ASSOC);
    if (!$indexes) {
        throw new RuntimeException('Install hook did not ensure the unique extension index.');
    }
    if (!file_exists($etc . '/advancedpaging_manager.conf') || !file_exists($etc . '/advancedpaging-runtime.ini')) {
        throw new RuntimeException('Install hook did not provision runtime credentials.');
    }
    foreach (['instruction_recording_id', 'speaker_volume', 'busy_policy'] as $column) {
        if (!in_array($column, array_column($db->query('PRAGMA table_info(advancedpaging_groups)')->fetchAll(PDO::FETCH_ASSOC), 'name'), true)) {
            throw new RuntimeException('Install hook did not migrate ' . $column . '.');
        }
    }
    if ($GLOBALS['reloads'] !== 1) {
        throw new RuntimeException('Install hook did not request configuration reload.');
    }
    // systemd RuntimeDirectory cleanup removes this path on stop.
    rmdir($run);
    if (!$module->uninstallAt($etc, $run, null, null)) {
        throw new RuntimeException('Module uninstall hook failed.');
    }
    if (file_exists($etc . '/advancedpaging_manager.conf') || file_exists($etc . '/advancedpaging-runtime.ini')
        || file_get_contents($etc . '/manager_custom.conf') !== "; site config\n") {
        throw new RuntimeException('Uninstall did not safely remove only owned files.');
    }
    echo "BMO install/uninstall lifecycle provisioned and removed owned configuration.\n";
} finally {
    foreach (glob($etc . '/*') as $path) { if (is_file($path) || is_link($path)) { unlink($path); } }
    foreach (glob($etc . '/.*') as $path) { if (is_file($path) || is_link($path)) { unlink($path); } }
    foreach (glob($run . '/*') as $path) { if (is_file($path) || is_link($path)) { unlink($path); } }
    foreach (glob($run . '/.*') as $path) { if (is_file($path) || is_link($path)) { unlink($path); } }
    rmdir($etc);
    rmdir($run);
    rmdir($root);
}
