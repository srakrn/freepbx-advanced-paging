<?php

if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}

/**
 * Register module-owned worker contexts and enabled group entry points.
 */
function advancedpaging_get_config($engine)
{
    if ($engine !== 'asterisk') {
        return;
    }
    global $ext;
    if (class_exists('\\FreePBX')) {
        $module = \FreePBX::Advancedpaging();
        $groups = $module->listGroups();
        foreach ($groups as &$group) {
            $id = $group['instruction_recording_id'] ?? null;
            $group['instruction_sounds'] = $id === null || $id === ''
                ? '' : $module->getRecordingSoundList((string) $id);
        }
        unset($group);
        advancedpaging_generate_group_routes($groups);
    }

    $context = 'advancedpaging-receiver';
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,quiet)', 'yes'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,startmuted)', 'yes'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,announce_join_leave)', 'no'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,announce_user_count)', 'no'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,wait_marked)', 'no'));
    $ext->add($context, 's', '', new ext_set('CONFBRIDGE(user,end_marked)', 'no'));
    $ext->add($context, 's', '', new ext_confbridge('${AP_CONF}'));
    $ext->add($context, 's', '', new ext_hangup());

    $context = 'advancedpaging-whisper';
    $ext->add($context, 's', '', new ext_chanspy('${AP_SP_DEVICE}-', 'qW'));
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
 * Generate enabled group routes. The admission watchdog fails closed when the
 * runtime is unavailable, so a caller cannot remain in an unattended bridge.
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
        $enabled[] = [$id, $extension, (string) ($group['instruction_sounds'] ?? '')];
    }
    if (!$enabled) {
        return;
    }
    $ext->addInclude('from-internal-noxfer-additional', 'ext-advancedpaging');
    foreach ($enabled as [$id, $extension, $instruction]) {
        $ext->add('ext-advancedpaging', $extension, '', new ext_goto('1', $id, 'app-advancedpaging'));
        $context = 'app-advancedpaging';
        $ext->add($context, $id, '', new ext_set('AP_CONF', 'ap-${FILTER(0-9a-f,${UUID()})}'));
        $ext->add($context, $id, '', new ext_set('AP_ADMITTED', '0'));
        $ext->add($context, $id, '', new ext_set('AP_ADMISSION_TRIES', '0'));
        if ($instruction !== '') {
            foreach (explode('&', $instruction) as $sound) {
                if ($sound === '' || strpos($sound, '..') !== false
                    || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/D', $sound)) {
                    throw new InvalidArgumentException('Invalid caller instruction sound.');
                }
            }
            // The caller hears the instruction before the controller originates
            // any receivers or joins the conference.
            $ext->add($context, $id, '', new ext_playback($instruction));
        }
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,quiet)', 'yes'));
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,startmuted)', 'yes'));
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,announce_join_leave)', 'no'));
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,announce_user_count)', 'no'));
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,wait_marked)', 'no'));
        $ext->add($context, $id, '', new ext_set('CONFBRIDGE(user,end_marked)', 'no'));
        $ext->add($context, $id, '', new ext_userevent('AdvancedPagingStart',
            'GroupID:' . $id . ',Conference:${AP_CONF},CallerChannel:${CHANNEL},CallerUniqueid:${UNIQUEID}'
            . ',CallerIDNum:${FILTER(0-9A-Za-z+._-,${CALLERID(num)})}'
            . ',CallerIDName:${FILTER(0-9A-Za-z ._-,${CALLERID(name)})}'));
        // Do not answer or join without an explicit runtime admission.
        // This is a setup watchdog, not a limit on an active session.
        $ext->add($context, $id, 'admission', new ext_gotoif('$["${AP_ADMITTED}" = "1"]', 'admitted'));
        $ext->add($context, $id, '', new ext_wait('0.1'));
        $ext->add($context, $id, '', new ext_set('AP_ADMISSION_TRIES', '$[${AP_ADMISSION_TRIES} + 1]'));
        $ext->add($context, $id, '', new ext_gotoif('$[${AP_ADMISSION_TRIES} < 30]', 'admission'));
        $ext->add($context, $id, '', new ext_hangup('41'));
        $ext->add($context, $id, 'admitted', new ext_answer());
        $ext->add($context, $id, '', new ext_confbridge('${AP_CONF}'));
        $ext->add($context, $id, '', new ext_hangup());
    }
}

/** Participate in FreePBX destination/extension collision checks. */
function advancedpaging_check_extensions($extension = true)
{
    if (is_array($extension) && !$extension) {
        return [];
    }
    $result = [];
    foreach (\FreePBX::Advancedpaging()->listGroups() as $group) {
        $number = (string) $group['extension'];
        if (is_array($extension) && !in_array($number, $extension, true)) {
            continue;
        }
        $result[$number] = [
            'description' => sprintf(_('Advanced Paging: %s'), $group['name']),
            'status' => 'INUSE',
            'edit_url' => 'config.php?display=advancedpaging&view=edit&id=' . (int) $group['id'],
        ];
    }
    return $result;
}

/** Native destination selector integration for announcements and routes. */
function advancedpaging_destinations()
{
    $destinations = [];
    foreach (\FreePBX::Advancedpaging()->listGroups() as $group) {
        if (empty($group['enabled'])) {
            continue;
        }
        $destinations[] = [
            'destination' => 'app-advancedpaging,' . (int) $group['id'] . ',1',
            'description' => sprintf(_('Advanced Paging: %s (%s)'), $group['name'], $group['extension']),
        ];
    }
    return $destinations;
}

function advancedpaging_getdest($groupId)
{
    $groupId = (string) $groupId;
    if (!preg_match('/^[1-9][0-9]{0,8}$/D', $groupId)) {
        return [];
    }
    $group = \FreePBX::Advancedpaging()->getGroup((int) $groupId);
    return $group && !empty($group['enabled']) ? ['app-advancedpaging,' . (int) $groupId . ',1'] : [];
}

function advancedpaging_getdestinfo($destination)
{
    if (!is_string($destination) || !preg_match('/^app-advancedpaging,([1-9][0-9]{0,8}),1$/D', $destination, $match)) {
        return false;
    }
    $group = \FreePBX::Advancedpaging()->getGroup((int) $match[1]);
    if (!$group || empty($group['enabled'])) {
        return [];
    }
    return [
        'description' => sprintf(_('Advanced Paging: %s (%s)'), $group['name'], $group['extension']),
        'edit_url' => 'config.php?display=advancedpaging&view=edit&id=' . (int) $match[1],
    ];
}
