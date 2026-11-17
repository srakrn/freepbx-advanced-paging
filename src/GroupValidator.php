<?php

namespace AdvancedPaging;

use InvalidArgumentException;

/**
 * Validates configuration independently of the FreePBX transport and UI.
 *
 * Lookups are injected so the FreePBX adapter can use its supported APIs.
 */
final class GroupValidator
{
    private $extensionInUse;
    private $memberExists;
    private $recordingExists;

    public function __construct(callable $extensionInUse, callable $memberExists, callable $recordingExists)
    {
        $this->extensionInUse = $extensionInUse;
        $this->memberExists = $memberExists;
        $this->recordingExists = $recordingExists;
    }

    /**
     * @param array $input Raw group fields.
     * @param string|null $previousExtension Existing extension when editing.
     * @return array Normalized group configuration.
     */
    public function validate(array $input, ?string $previousExtension = null): array
    {
        $name = $input['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || strlen(trim($name)) > 255) {
            throw new InvalidArgumentException('A group name of at most 255 bytes is required.');
        }
        $extension = $input['extension'] ?? null;
        if (!is_string($extension) || !preg_match('/^[0-9]{1,25}$/D', $extension)) {
            throw new InvalidArgumentException('Paging extension must contain 1 to 25 digits.');
        }
        if ($extension !== $previousExtension && ($this->extensionInUse)($extension)) {
            throw new InvalidArgumentException('Paging extension is already in use.');
        }
        $enabled = $input['enabled'] ?? null;
        if (!in_array($enabled, [true, false, 0, 1, '0', '1'], true)) {
            throw new InvalidArgumentException('Invalid enabled state.');
        }
        $mode = $input['audio_mode'] ?? null;
        if (!in_array($mode, ['simplex', 'duplex'], true)) {
            throw new InvalidArgumentException('Invalid audio mode.');
        }
        $members = $input['members'] ?? null;
        if (!is_array($members)) {
            throw new InvalidArgumentException('Members must be a list of extensions.');
        }
        $normalizedMembers = [];
        foreach ($members as $member) {
            if (!is_string($member) || !preg_match('/^[0-9]{1,25}$/D', $member)
                || !($this->memberExists)($member)) {
                throw new InvalidArgumentException('Unknown or invalid receiving extension.');
            }
            $normalizedMembers[$member] = $member;
        }
        $recordings = [];
        foreach (['pre_recording_id', 'post_recording_id'] as $field) {
            $value = $input[$field] ?? null;
            if ($value === null || $value === '') {
                $recordings[$field] = null;
                continue;
            }
            if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
                || !($this->recordingExists)((string) $value)) {
                throw new InvalidArgumentException('Unknown or invalid recording.');
            }
            $recordings[$field] = (string) $value;
        }

        return [
            'name' => trim($name),
            'extension' => $extension,
            'enabled' => (bool) $enabled,
            'audio_mode' => $mode,
            'members' => array_values($normalizedMembers),
            'pre_recording_id' => $recordings['pre_recording_id'],
            'post_recording_id' => $recordings['post_recording_id'],
        ];
    }
}
