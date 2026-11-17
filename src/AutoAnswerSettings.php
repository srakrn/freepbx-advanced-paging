<?php

namespace AdvancedPaging;

use RuntimeException;

/** FreePBX Paging default header values, validated before dialplan use. */
final class AutoAnswerSettings
{
    public static function fromPagingDefaults(array $rows): array
    {
        $values = ['ALERTINFO' => '', 'CALLINFO' => ''];
        foreach ($rows as $row) {
            $key = $row['var'] ?? '';
            if (array_key_exists($key, $values)) {
                $values[$key] = self::validate((string) ($row['setting'] ?? ''));
            }
        }
        return $values;
    }

    public static function validate(string $value): string
    {
        $value = str_replace('\\;', ';', trim($value));
        // No commas, expression characters, CR/LF, or other dialplan syntax.
        if (strlen($value) > 200 || ($value !== ''
            && !preg_match('/^[A-Za-z0-9 ._:@\/;=<>+!?-]+$/D', $value))) {
            throw new RuntimeException('Unsafe FreePBX paging auto-answer header.');
        }
        return $value;
    }
}
