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
 * Experimental route generator, intentionally NOT called by get_config.
 * Caller admission/watchdog and runtime discovery must be completed first;
 * otherwise a caller could be stranded in an unattended conference.
 */
function advancedpaging_generate_group_routes(array $groups)
{
    global $ext;
    $enabled = [];
    foreach ($groups as $group) {
        if (empty($group['enabled'])) {
            continue;
        }
        $id = (string) ($group['id'] ?? '');
        $extension = $group['extension'] ?? null;
        if (!preg_match('/^[1-9][0-9]*$/D', $id)
            || !is_string($extension) || !preg_match('/^[0-9]{1,25}$/D', $extension)) {
            throw new InvalidArgumentException('Invalid paging group route.');
        }
        $enabled[] = [$id, $extension];
    }
    if (!$enabled) {
        return;
    }
    $ext->addInclude('from-internal-noxfer-additional', 'ext-advancedpaging');
    foreach ($enabled as [$id, $extension]) {
        $ext->add('ext-advancedpaging', $extension, '', new ext_goto('1', $id, 'app-advancedpaging'));
        $context = 'app-advancedpaging';
        $ext->add($context, $id, '', new ext_set('AP_CONF', 'ap-${FILTER(0-9a-f,${UUID()})}'));
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,quiet)', 'yes'));
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,startmuted)', 'yes'));
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,announce_join_leave)', 'no'));
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,announce_user_count)', 'no'));
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,wait_marked)', 'no'));
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,end_marked)', 'no'));
        $ext->add($context, $id, '', new ext_answer());
        $ext->add($context, $id, '', new ext_userevent('AdvancedPagingStart',
            'GroupID:' . $id . ',Conference:${AP_CONF},CallerChannel:${CHANNEL},CallerUniqueid:${UNIQUEID}'));
        $ext->add($context, $id, '', new ext_confbridge('${AP_CONF}'));
        $ext->add($context, $id, '', new ext_hangup());
    }
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
