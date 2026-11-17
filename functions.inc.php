<?php

if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

/**
 * Register extension usage with FreePBX's collision checker. Disabled groups
 * still reserve their numbers so enabling them cannot hijack another route.
 */
function advancedpaging_check_extensions($exten = true)
{
    if (is_array($exten) && !$exten) {
        return [];
    }
    $result = [];
    foreach (\FreePBX::Advancedpaging()->listGroups() as $group) {
        $number = $group['extension'];
        if (is_array($exten) && !in_array($number, $exten, true)) {
            continue;
        }
        $result[$number] = [
            'description' => _('Advanced Paging: ') . $group['name'],
            'status' => 'INUSE',
            'edit_url' => 'config.php?display=advancedpaging',
        ];
    }
    return $result;
}
