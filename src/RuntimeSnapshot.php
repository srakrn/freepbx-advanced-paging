<?php

namespace AdvancedPaging;

use RuntimeException;

/**
 * Build an immutable session snapshot from trusted FreePBX callbacks.
 * No caller-supplied dial string or recording path is accepted.
 */
final class RuntimeSnapshot
{
    private $getGroup;
    private $deviceUser;
    private $resolveTargets;
    private $autoAnswer;
    private $recording;

    public function __construct(
        callable $getGroup,
        callable $deviceUser,
        callable $resolveTargets,
        callable $autoAnswer,
        callable $recording
    ) {
        $this->getGroup = $getGroup;
        $this->deviceUser = $deviceUser;
        $this->resolveTargets = $resolveTargets;
        $this->autoAnswer = $autoAnswer;
        $this->recording = $recording;
    }

    public function load(int $groupId, array $event): array
    {
        $channel = $event['CallerChannel'] ?? '';
        if (!is_string($channel) || !preg_match('/^PJSIP\/([A-Za-z0-9_.-]+)-[0-9a-fA-F]{8}$/D', $channel, $match)) {
            throw new RuntimeException('Paging caller is not an internal PJSIP device.');
        }
        $deviceId = $match[1];
        $extension = ($this->deviceUser)($deviceId);
        if (!is_string($extension) || !preg_match('/^[0-9]{1,25}$/D', $extension)) {
            throw new RuntimeException('Paging caller has no valid internal user.');
        }
        $group = ($this->getGroup)($groupId);
        if (!is_array($group) || empty($group['enabled'])
            || !in_array($group['audio_mode'] ?? '', ['simplex', 'duplex'], true)
            || !isset($group['members']) || !is_array($group['members'])) {
            throw new RuntimeException('Paging group is unavailable.');
        }
        $targets = ($this->resolveTargets)($group['members'], $extension, $deviceId);
        $autoAnswer = ($this->autoAnswer)($targets);
        if (!is_array($targets) || !$targets || !is_array($autoAnswer)) {
            throw new RuntimeException('No eligible paging endpoints or auto-answer settings.');
        }
        $overrides = $autoAnswer['overrides'] ?? [];
        unset($autoAnswer['overrides']);
        foreach ($targets as &$target) {
            if (!is_array($target) || !isset($target['channel'])) {
                continue;
            }
            $target['auto_answer'] = array_replace($autoAnswer, $overrides[$target['channel']] ?? []);
        }
        unset($target);
        $files = [];
        $ids = [];
        foreach (['pre_recording_id', 'post_recording_id'] as $field) {
            $id = $group[$field] ?? null;
            if ($id === null || $id === '') {
                $ids[] = null;
                continue;
            }
            if ((!is_string($id) && !is_int($id)) || !preg_match('/^[1-9][0-9]*$/D', (string) $id)) {
                throw new RuntimeException('Invalid recording reference.');
            }
            $id = (string) $id;
            $soundList = ($this->recording)($id);
            if (!is_string($soundList) || $soundList === '') {
                // Missing recordings are skipped; callers must log the issue
                // in the FreePBX adapter without exposing paths.
                $ids[] = null;
                continue;
            }
            foreach (explode('&', $soundList) as $sound) {
                if ($sound === '' || strpos($sound, '..') !== false
                    || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $sound)) {
                    throw new RuntimeException('Unsafe system recording reference.');
                }
            }
            $ids[] = $id;
            $files[$id] = $soundList;
        }
        return [
            'mode' => $group['audio_mode'], 'pre' => $ids[0], 'post' => $ids[1],
            'targets' => array_values($targets), 'auto_answer' => $autoAnswer,
            'setup_seconds' => 5.0, 'recording_files' => $files,
        ];
    }
}
