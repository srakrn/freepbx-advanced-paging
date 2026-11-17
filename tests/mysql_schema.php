<?php

require_once __DIR__ . '/../src/SchemaMigrator.php';

use AdvancedPaging\SchemaMigrator;

$socket = getenv('AP_LAB_MYSQL_SOCKET');
if (!$socket) {
    throw new RuntimeException('Missing isolated database socket.');
}
$db = new PDO('mysql:unix_socket=' . $socket . ';dbname=ap_test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE advancedpaging_groups (id INT PRIMARY KEY, extension VARCHAR(25) NOT NULL, name VARCHAR(255)) ENGINE=InnoDB');
SchemaMigrator::ensureUniqueExtensions($db);
SchemaMigrator::ensureUniqueExtensions($db);
$db->exec("INSERT INTO advancedpaging_groups VALUES (1, '100', 'one')");
try {
    $db->exec("INSERT INTO advancedpaging_groups VALUES (2, '100', 'two')");
    throw new RuntimeException('MySQL accepted a duplicate extension.');
} catch (PDOException $expected) {
}
echo "Isolated MariaDB schema migration passed.\n";
