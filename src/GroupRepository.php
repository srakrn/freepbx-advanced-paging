<?php

namespace AdvancedPaging;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Group storage. Schema installation and validation are separate responsibilities.
 * The provided PDO connection must use exception error mode and transactional tables.
 */
final class GroupRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function list(): array
    {
        $statement = $this->db->query(
            'SELECT g.*, COUNT(m.extension) AS member_count FROM advancedpaging_groups g ' .
            'LEFT JOIN advancedpaging_members m ON m.group_id = g.id ' .
            'GROUP BY g.id, g.name, g.extension, g.enabled, g.audio_mode, g.pre_recording_id, g.post_recording_id, ' .
            'g.instruction_recording_id, g.speaker_volume, g.busy_policy ' .
            'ORDER BY g.extension'
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM advancedpaging_groups WHERE id = ?');
        $statement->execute([$id]);
        $group = $statement->fetch(PDO::FETCH_ASSOC);
        if ($group === false) {
            return null;
        }
        $members = $this->db->prepare('SELECT extension FROM advancedpaging_members WHERE group_id = ? ORDER BY extension');
        $members->execute([$id]);
        $group['members'] = $members->fetchAll(PDO::FETCH_COLUMN);
        return $group;
    }

    /**
     * Save an already validated group and all its members atomically.
     * A unique database index on extension is required to resolve races.
     */
    public function save(array $group, ?int $id = null): int
    {
        $this->db->beginTransaction();
        try {
            $values = [
                $group['name'], $group['extension'], (int) $group['enabled'],
                $group['audio_mode'], $group['pre_recording_id'], $group['post_recording_id'],
                $group['instruction_recording_id'], $group['speaker_volume'], $group['busy_policy'],
            ];
            if ($id === null) {
                $statement = $this->db->prepare(
                    'INSERT INTO advancedpaging_groups (name, extension, enabled, audio_mode, pre_recording_id, post_recording_id, instruction_recording_id, speaker_volume, busy_policy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->execute($values);
                $id = (int) $this->db->lastInsertId();
            } else {
                // Verify existence even when the values have not changed.
                $query = $this->db->prepare('SELECT id FROM advancedpaging_groups WHERE id = ?');
                $query->execute([$id]);
                if ($query->fetchColumn() === false) {
                    throw new RuntimeException('Group does not exist.');
                }
                $statement = $this->db->prepare(
                    'UPDATE advancedpaging_groups SET name = ?, extension = ?, enabled = ?, audio_mode = ?, pre_recording_id = ?, post_recording_id = ?, instruction_recording_id = ?, speaker_volume = ?, busy_policy = ? WHERE id = ?'
                );
                $values[] = $id;
                $statement->execute($values);
                $statement = $this->db->prepare('DELETE FROM advancedpaging_members WHERE group_id = ?');
                $statement->execute([$id]);
            }
            $statement = $this->db->prepare('INSERT INTO advancedpaging_members (group_id, extension) VALUES (?, ?)');
            foreach ($group['members'] as $member) {
                $statement->execute([$id, $member]);
            }
            $this->db->commit();
            return $id;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function delete(int $id): bool
    {
        $this->db->beginTransaction();
        try {
            $members = $this->db->prepare('DELETE FROM advancedpaging_members WHERE group_id = ?');
            $members->execute([$id]);
            $group = $this->db->prepare('DELETE FROM advancedpaging_groups WHERE id = ?');
            $group->execute([$id]);
            $deleted = $group->rowCount() > 0;
            $this->db->commit();
            return $deleted;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Insert prevalidated backup groups as one transaction. Never overwrites
     * existing groups. Requires transactional tables and a unique extension
     * index to protect against concurrent inserts.
     *
     * @return int[] New local group IDs.
     */
    public function insertMany(array $groups): array
    {
        if (!$groups) {
            return [];
        }
        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT INTO advancedpaging_groups (name, extension, enabled, audio_mode, pre_recording_id, post_recording_id, instruction_recording_id, speaker_volume, busy_policy) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $member = $this->db->prepare('INSERT INTO advancedpaging_members (group_id, extension) VALUES (?, ?)');
            $ids = [];
            foreach ($groups as $group) {
                $insert->execute([
                    $group['name'], $group['extension'], (int) $group['enabled'], $group['audio_mode'],
                    $group['pre_recording_id'], $group['post_recording_id'], $group['instruction_recording_id'],
                    $group['speaker_volume'], $group['busy_policy'],
                ]);
                $id = (int) $this->db->lastInsertId();
                foreach ($group['members'] as $extension) {
                    $member->execute([$id, $extension]);
                }
                $ids[] = $id;
            }
            $this->db->commit();
            return $ids;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
