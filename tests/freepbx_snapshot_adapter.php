<?php

interface BMO { public function install(); public function uninstall(); }
define('FPBX_LOG_WARNING', 1);
function freepbx_log($level, $message) {}
function paging_get_autoanswer_defaults() {
    return [['var' => 'ALERTINFO', 'setting' => 'Ring Answer'], ['var' => 'CALLINFO', 'setting' => '<uri>;answer-after=0']];
}
require_once __DIR__ . '/../Advancedpaging.class.php';

final class MockCore
{
    public function getAllUsers(): array { return ['100' => [], '101' => []]; }
    public function getDevice($id): array { return ['id' => $id, 'tech' => 'pjsip']; }
}
final class MockManager
{
    public function connected(): bool { return true; }
    public function database_get($family, $key) {
        return ['DEVICE:100/user' => '100', 'AMPUSER:101/device' => '101'][$family . ':' . $key] ?? '';
    }
    public function GetVar($channel, $variable): array { return ['Response' => 'Success', 'Value' => 'NOT_INUSE']; }
    public function database_show($family): array {
        return ['/registrar/contact/101;@sip:101@192.0.2.10:5060' => '{"uri":"sip:101@192.0.2.10:5060","user_agent":"TestPhone"}'];
    }
}
final class MockRecordings
{
    public function getRecordingById($id): array {
        return ['filename' => $id === '7' ? 'custom/open' : 'custom/close',
            'soundlist' => [['languages' => ['en']]]];
    }
}
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE advancedpaging_groups (id INTEGER PRIMARY KEY, name TEXT, extension TEXT, enabled INTEGER, audio_mode TEXT, pre_recording_id INTEGER, post_recording_id INTEGER)');
$db->exec('CREATE TABLE advancedpaging_members (group_id INTEGER, extension TEXT)');
$db->exec('CREATE TABLE paging_autoanswer (useragent TEXT, var TEXT, setting TEXT)');
$db->exec("INSERT INTO paging_autoanswer VALUES ('TestPhone', 'ALERTINFO', 'Specific Answer')");
$db->exec("INSERT INTO advancedpaging_groups VALUES (1, 'Test', '8555', 1, 'duplex', 7, 8)");
$db->exec("INSERT INTO advancedpaging_members VALUES (1, '100'), (1, '101')");
$freepbx = (object) ['Database' => $db, 'Core' => new MockCore(), 'astman' => new MockManager(), 'Recordings' => new MockRecordings()];
$module = new \FreePBX\modules\Advancedpaging($freepbx);
$snapshot = $module->loadRuntimeSnapshot(1, ['CallerChannel' => 'PJSIP/100-00000001']);
if (($snapshot['targets'][0]['channel'] ?? '') !== 'PJSIP/101/sip:101@192.0.2.10:5060'
    || ($snapshot['targets'][0]['auto_answer']['ALERTINFO'] ?? '') !== 'Specific Answer'
    || $snapshot['recording_files'] !== [7 => 'custom/open', 8 => 'custom/close']
    || $snapshot['auto_answer']['ALERTINFO'] !== 'Ring Answer') {
    throw new RuntimeException('FreePBX module snapshot adapter failed: ' . json_encode($snapshot));
}
echo "FreePBX module runtime snapshot adapter passed.\n";
