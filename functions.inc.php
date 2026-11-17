<?php

if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

/**
 * Module-owned, non-routable worker contexts. Group dialplan entry points and
 * controller startup are deliberately not registered while install is gated.
 */
function advancedpaging_get_config($engine)
{
    if ($engine !== 'asterisk') {
        return;
    }
    global $ext;

    $context = 'advancedpaging-receiver';
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,quiet)', 'yes'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,startmuted)', 'yes'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,announce_join_leave)', 'no'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,announce_user_count)', 'no'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,wait_marked)', 'no'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,end_marked)', 'no'));
    $ext->add($context, 's', '', new ext_confbridge('${AP_CONF}'));
    $ext->add($context, 's', '', new ext_hangup());

    $context = 'advancedpaging-inject';
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,quiet)', 'yes'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,startmuted)', 'no'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,announce_join_leave)', 'no'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,announce_user_count)', 'no'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,wait_marked)', 'no'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,end_marked)', 'no'));
    $ext->add($context, 's', '', new ext_confbridge('${AP_CONF}'));
    $ext->add($context, 's', '', new ext_hangup());

    $context = 'advancedpaging-player';
    $ext->add($context, 's', '', new ext_playback('${AP_SOUNDS}'));
    $ext->add($context, 's', '', new ext_userevent('AdvancedPagingPlayback', 'PlaybackID:${AP_PLAYBACK_ID},Status:${PLAYBACKSTATUS}'));
    $ext->add($context, 's', '', new ext_hangup());

    $context = 'advancedpaging-predial';
    $ext->add($context, 's', '', new ext_gosub('1', 's', 'autoanswer', '${AP_ALERTINFO},${AP_CALLINFO}'));
    $ext->add($context, 's', '', new ext_return());

    $context = 'advancedpaging-dial';
    $ext->add($context, 's', '', new ext_dial('PJSIP/${AP_DEVICE}', '${AP_DIAL_TIMEOUT},b(advancedpaging-predial^s^1)'));
    $ext->add($context, 's', '', new ext_hangup());
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
