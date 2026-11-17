<?php

namespace AdvancedPaging;

use PDO;
use RuntimeException;

/**
 * Idempotent schema checks after FreePBX creates the declared module tables.
 * Installation remains gated until service, routing and recovery are complete.
 */
final class SchemaMigrator
{
    private const INDEX = 'advancedpaging_groups_extension_uq';

    public static function ensureUniqueExtensions(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $query = $db->prepare(
                'SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? ORDER BY SEQ_IN_INDEX'
            );
            $query->execute(['advancedpaging_groups', self::INDEX]);
            $existing = $query->fetchAll(PDO::FETCH_ASSOC);
            if ($existing) {
                if (count($existing) !== 1 || $existing[0]['COLUMN_NAME'] !== 'extension'
                    || (int) $existing[0]['NON_UNIQUE'] !== 0) {
                    throw new RuntimeException('Invalid Advanced Paging extension index.');
                }
                return;
            }
        } elseif ($driver === 'sqlite') {
            $indexes = $db->query('PRAGMA index_list(advancedpaging_groups)')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($indexes as $index) {
                if ($index['name'] !== self::INDEX) {
                    continue;
                }
                $columns = $db->query('PRAGMA index_info(' . self::INDEX . ')')->fetchAll(PDO::FETCH_ASSOC);
                if ((int) $index['unique'] !== 1 || count($columns) !== 1 || $columns[0]['name'] !== 'extension') {
                    throw new RuntimeException('Invalid Advanced Paging extension index.');
                }
                return;
            }
        } else {
            throw new RuntimeException('Unsupported FreePBX database driver.');
        }
        // Duplicates cause creation to fail; do not modify or remove user data.
        $db->exec('CREATE UNIQUE INDEX ' . self::INDEX . ' ON advancedpaging_groups (extension)');
    }

    public static function ensurePagingOptions(PDO $db): void
    {
        $columns = [
            'instruction_recording_id' => ['mysql' => 'BIGINT NULL', 'sqlite' => 'INTEGER NULL'],
            'speaker_volume' => ['mysql' => 'TINYINT NOT NULL DEFAULT 0', 'sqlite' => 'INTEGER NOT NULL DEFAULT 0'],
            'busy_policy' => ['mysql' => 'TINYINT NOT NULL DEFAULT 0', 'sqlite' => 'INTEGER NOT NULL DEFAULT 0'],
        ];
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $query = $db->prepare(
                'SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $query->execute(['advancedpaging_groups']);
            $existing = array_flip($query->fetchAll(PDO::FETCH_COLUMN));
        } elseif ($driver === 'sqlite') {
            $existing = [];
            foreach ($db->query('PRAGMA table_info(advancedpaging_groups)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $existing[$column['name']] = true;
            }
        } else {
            throw new RuntimeException('Unsupported FreePBX database driver.');
        }
        foreach ($columns as $name => $types) {
            if (!isset($existing[$name])) {
                $db->exec('ALTER TABLE advancedpaging_groups ADD COLUMN ' . $name . ' ' . $types[$driver]);
            }
        }
    }
}
