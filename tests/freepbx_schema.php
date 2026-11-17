<?php

// Uses the installed FreePBX 17 migration implementation with an explicitly
// supplied temporary database configuration, never /etc/freepbx.conf.
require '/var/www/html/admin/libraries/Composer/vendor/autoload.php';
require '/var/www/html/admin/libraries/BMO/Database/DBAL/SchemaSynchronizer.php';
require '/var/www/html/admin/libraries/BMO/Database/DBAL/AbstractSchemaSynchronizer.php';
require '/var/www/html/admin/libraries/BMO/Database/DBAL/SingleDatabaseSynchronizer.php';
require '/var/www/html/admin/libraries/BMO/Database/Migration.class.php';
require '/var/www/html/admin/libraries/BMO/Database.class.php';
require_once __DIR__ . '/../src/SchemaMigrator.php';

$socket = getenv('AP_LAB_MYSQL_SOCKET');
if (!$socket) {
    throw new RuntimeException('Missing isolated database socket.');
}
$db = new \FreePBX\Database([
    'AMPDBUSER' => 'root', 'AMPDBPASS' => '', 'AMPDBNAME' => 'ap_freepbx_test',
    'AMPDBENGINE' => 'mysql', 'AMPDBSOCK' => $socket,
]);
$xml = simplexml_load_file(__DIR__ . '/../module.xml');
if (!$xml || empty($xml->database->table)) {
    throw new RuntimeException('Module schema not found.');
}
$db->migrateMultipleXML($xml->database->table);
\AdvancedPaging\SchemaMigrator::ensureUniqueExtensions($db);
$db->migrateMultipleXML($xml->database->table);
\AdvancedPaging\SchemaMigrator::ensureUniqueExtensions($db);
$engines = $db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('advancedpaging_groups', 'advancedpaging_members')")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
if (count($engines) !== 2 || array_unique(array_values($engines)) !== ['InnoDB']) {
    throw new RuntimeException('Module tables are missing or not transactional: ' . json_encode($engines));
}
$db->exec("INSERT INTO advancedpaging_groups (name, extension, enabled, audio_mode) VALUES ('first', '500', 1, 'simplex')");
try {
    $db->exec("INSERT INTO advancedpaging_groups (name, extension, enabled, audio_mode) VALUES ('second', '500', 1, 'simplex')");
    throw new RuntimeException('FreePBX migration did not enforce extension uniqueness.');
} catch (PDOException $expected) {
}
echo "FreePBX 17 XML migration, upgrade repeat, InnoDB, and unique index passed.\n";
