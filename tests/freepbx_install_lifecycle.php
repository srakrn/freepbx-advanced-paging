<?php

interface BMO { public function install(); public function uninstall(); }
define('FPBX_LOG_NOTICE', 1);
define('FPBX_LOG_WARNING', 2);
define('FPBX_LOG_ERROR', 3);
function freepbx_log($level, $message) {}
function needreload() { $GLOBALS['reloads']++; }
function framework_check_extension_usage($extension) { return []; }

require '/var/www/html/admin/libraries/Composer/vendor/autoload.php';
require '/var/www/html/admin/libraries/modulefunctions.class.php';
require '/var/www/html/admin/libraries/BMO/Database/DBAL/SchemaSynchronizer.php';
require '/var/www/html/admin/libraries/BMO/Database/DBAL/AbstractSchemaSynchronizer.php';
require '/var/www/html/admin/libraries/BMO/Database/DBAL/SingleDatabaseSynchronizer.php';
require '/var/www/html/admin/libraries/BMO/Database/Migration.class.php';
require '/var/www/html/admin/libraries/BMO/Database.class.php';
require_once __DIR__ . '/../Advancedpaging.class.php';

final class InstallTestCore
{
    public function getAllUsers(): array { return ['100' => []]; }
}
$socket = getenv('AP_LAB_MYSQL_SOCKET');
if (!$socket) {
    throw new RuntimeException('Missing isolated database socket.');
}
$root = sys_get_temp_dir() . '/ap-fpbx-install-' . bin2hex(random_bytes(8));
$moduleDir = $root . '/admin/modules/advancedpaging';
$etc = $root . '/asterisk';
$run = $root . '/run/advancedpaging';
mkdir($moduleDir, 0700, true);
mkdir($etc, 0700, true);
mkdir($run, 0700, true);
copy(__DIR__ . '/../preinstall.php', $moduleDir . '/preinstall.php');
$amp_conf = ['AMPWEBROOT' => $root];
$moduleFunctions = new module_functions();
$moduleFunctions->_runscripts_include([
    'advancedpaging' => ['fileinclude' => ['install' => 'preinstall.php']],
], 'install');

$db = new \FreePBX\Database([
    'AMPDBUSER' => 'root', 'AMPDBPASS' => '', 'AMPDBNAME' => 'ap_install_test',
    'AMPDBENGINE' => 'mysql', 'AMPDBSOCK' => $socket,
]);
$xml = simplexml_load_file(__DIR__ . '/../module.xml');
if (!$xml || empty($xml->database->table)) {
    throw new RuntimeException('Advanced Paging schema not found.');
}
$db->migrateMultipleXML($xml->database->table);
$freepbx = (object) [
    'Database' => $db, 'Core' => new InstallTestCore(),
    'Recordings' => new class {
        public function getRecordingById($id): array { return []; }
    },
];
$module = new \FreePBX\modules\Advancedpaging($freepbx);
$GLOBALS['reloads'] = 0;
try {
    if (!$module->installAt($etc, $run, null, null)) {
        throw new RuntimeException('BMO install hook failed.');
    }
    $schema = $db->query("SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'advancedpaging_groups'")->fetchColumn();
    $index = $db->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'advancedpaging_groups' AND index_name = 'advancedpaging_groups_extension_uq' AND non_unique = 0");
    $index->execute();
    if ($schema !== 'InnoDB' || (int) $index->fetchColumn() !== 1 || $GLOBALS['reloads'] !== 1
        || !file_exists($etc . '/advancedpaging-runtime.ini')) {
        throw new RuntimeException('Installed lifecycle invariant failed.');
    }
    if (!$module->uninstallAt($etc, $run, null, null)) {
        throw new RuntimeException('BMO uninstall hook failed.');
    }
    echo "FreePBX 17 pre-install, XML migration, BMO install, and BMO uninstall lifecycle passed in isolation.\n";
} finally {
    foreach (glob($etc . '/*') as $path) { if (is_file($path) || is_link($path)) { unlink($path); } }
    foreach (glob($etc . '/.*') as $path) { if (is_file($path) || is_link($path)) { unlink($path); } }
    foreach (glob($run . '/*') as $path) { if (is_file($path) || is_link($path)) { unlink($path); } }
    foreach (glob($run . '/.*') as $path) { if (is_file($path) || is_link($path)) { unlink($path); } }
    unlink($moduleDir . '/preinstall.php');
    rmdir($run);
    rmdir(dirname($run));
    rmdir($etc);
    rmdir($moduleDir);
    rmdir(dirname($moduleDir));
    rmdir(dirname(dirname($moduleDir)));
    rmdir($root);
}
