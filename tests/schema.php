<?php

require_once __DIR__ . '/../src/SchemaMigrator.php';

use AdvancedPaging\SchemaMigrator;

function schemaDb(): PDO
{
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE advancedpaging_groups (id INTEGER PRIMARY KEY, extension TEXT, name TEXT)');
    return $db;
}
$db = schemaDb();
SchemaMigrator::ensureUniqueExtensions($db);
SchemaMigrator::ensureUniqueExtensions($db);
$db->exec("INSERT INTO advancedpaging_groups VALUES (1, '100', 'one')");
try {
    $db->exec("INSERT INTO advancedpaging_groups VALUES (2, '100', 'two')");
    throw new RuntimeException('Duplicate extension was accepted.');
} catch (PDOException $expected) {
}
$db = schemaDb();
$db->exec('CREATE INDEX advancedpaging_groups_extension_uq ON advancedpaging_groups (name)');
try {
    SchemaMigrator::ensureUniqueExtensions($db);
    throw new RuntimeException('Incorrect existing index was accepted.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Invalid Advanced Paging extension index.') {
        throw $error;
    }
}
$db = schemaDb();
$db->exec("INSERT INTO advancedpaging_groups VALUES (1, '100', 'one'), (2, '100', 'two')");
try {
    SchemaMigrator::ensureUniqueExtensions($db);
    throw new RuntimeException('Duplicate data was silently changed.');
} catch (PDOException $expected) {
    if ((int) $db->query('SELECT COUNT(*) FROM advancedpaging_groups')->fetchColumn() !== 2) {
        throw new RuntimeException('Migration removed existing data.');
    }
}
echo "Schema migration and duplicate preservation tests passed.\n";
