<?php

require_once __DIR__ . '/../src/SchemaMigrator.php';
require_once __DIR__ . '/../src/GroupRepository.php';

use AdvancedPaging\SchemaMigrator;
use AdvancedPaging\GroupRepository;

$socket = getenv('AP_LAB_MYSQL_SOCKET');
if (!$socket) {
    throw new RuntimeException('Missing isolated database socket.');
}
$db = new PDO('mysql:unix_socket=' . $socket . ';dbname=ap_test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE advancedpaging_groups (id INT PRIMARY KEY AUTO_INCREMENT, extension VARCHAR(25) NOT NULL, name VARCHAR(255), enabled TINYINT NOT NULL, audio_mode VARCHAR(10) NOT NULL, pre_recording_id INT NULL, post_recording_id INT NULL, instruction_recording_id INT NULL, speaker_volume TINYINT NOT NULL DEFAULT 0, busy_policy TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB');
$db->exec('CREATE TABLE advancedpaging_members (group_id INT NOT NULL, extension VARCHAR(25) NOT NULL, PRIMARY KEY (group_id, extension)) ENGINE=InnoDB');
SchemaMigrator::ensureUniqueExtensions($db);
SchemaMigrator::ensureUniqueExtensions($db);
$db->exec("INSERT INTO advancedpaging_groups (id, extension, name, enabled, audio_mode) VALUES (1, '100', 'one', 1, 'simplex')");
try {
    $db->exec("INSERT INTO advancedpaging_groups (id, extension, name, enabled, audio_mode) VALUES (2, '100', 'two', 1, 'simplex')");
    throw new RuntimeException('MySQL accepted a duplicate extension.');
} catch (PDOException $expected) {
}
$repository = new GroupRepository($db);
$first = ['name' => 'first', 'extension' => '101', 'enabled' => true, 'audio_mode' => 'duplex',
    'pre_recording_id' => null, 'post_recording_id' => null, 'members' => ['201']];
$conflict = $first;
$conflict['extension'] = '100';
try {
    $repository->insertMany([$first, $conflict]);
    throw new RuntimeException('Conflicting bulk restore was accepted.');
} catch (PDOException $expected) {
    if ((int) $db->query('SELECT COUNT(*) FROM advancedpaging_groups')->fetchColumn() !== 1
        || (int) $db->query('SELECT COUNT(*) FROM advancedpaging_members')->fetchColumn() !== 0) {
        throw new RuntimeException('MariaDB bulk restore did not roll back.');
    }
}
echo "Isolated MariaDB schema migration and restore rollback passed.\n";
