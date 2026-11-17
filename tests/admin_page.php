<?php

interface BMO { public function install(); public function uninstall(); }
define('FPBX_LOG_NOTICE', 1);
function freepbx_log($level, $message) {}
function needreload() { $GLOBALS['reloadCount']++; }
function framework_check_extension_usage($extension) { return $extension === '900' ? ['used'] : []; }
require_once __DIR__ . '/../Advancedpaging.class.php';
require_once __DIR__ . '/../src/AdminPage.php';

use AdvancedPaging\AdminPage;

final class AdminTestCore
{
    public function getAllUsers(): array {
        return ['100' => ['name' => '<script>bad</script>'], '101' => ['name' => 'Receiver']];
    }
}
final class AdminTestRecordings
{
    public function getAllRecordings(): array { return [['id' => '7', 'displayname' => '<opening>']]; }
    public function getRecordingById($id): array { return (string) $id === '7' ? ['id' => 7] : []; }
}
function checkAdmin(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE advancedpaging_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, extension TEXT NOT NULL UNIQUE, enabled INTEGER NOT NULL, audio_mode TEXT NOT NULL, pre_recording_id INTEGER, post_recording_id INTEGER)');
$db->exec('CREATE TABLE advancedpaging_members (group_id INTEGER NOT NULL, extension TEXT NOT NULL, PRIMARY KEY (group_id, extension))');
$core = new AdminTestCore();
$recordings = new AdminTestRecordings();
$module = new \FreePBX\modules\Advancedpaging((object) ['Database' => $db, 'Core' => $core, 'Recordings' => $recordings]);
$page = new AdminPage($module, $core, $recordings);
$session = [];
$GLOBALS['reloadCount'] = 0;
$result = $page->handle('GET', ['view' => 'add'], [], $session);
checkAdmin($result['status'] === 200 && strpos($result['html'], '&lt;script&gt;bad&lt;/script&gt;') !== false, 'Extension labels not escaped.');
checkAdmin(strpos($result['html'], '&lt;opening&gt;') !== false, 'Recording names not escaped.');
$token = $session['advancedpaging_csrf'];
$post = ['action' => 'save', 'csrf' => $token, 'id' => '', 'name' => '<Group>', 'extension' => '500',
    'enabled' => '1', 'audio_mode' => 'simplex', 'members' => ['100', '101'],
    'pre_recording_id' => '7', 'post_recording_id' => ''];
$bad = $post;
$bad['csrf'] = 'incorrect';
checkAdmin($page->handle('POST', [], $bad, $session)['status'] === 403 && !$module->listGroups(), 'CSRF validation failed.');
$result = $page->handle('POST', [], $post, $session);
checkAdmin($result['status'] === 200 && strpos($result['html'], '&lt;Group&gt;') !== false, 'Save or list escaping failed.');
checkAdmin($GLOBALS['reloadCount'] === 1 && count($module->getGroup(1)['members']) === 2, 'Save did not persist group and members.');
checkAdmin($session['advancedpaging_csrf'] !== $token, 'CSRF token did not rotate.');
checkAdmin($page->handle('POST', [], $post, $session)['status'] === 403, 'Old CSRF token was reusable.');
$post['csrf'] = $session['advancedpaging_csrf'];
$post['id'] = '1';
$post['extension'] = '900';
$result = $page->handle('POST', [], $post, $session);
checkAdmin(strpos($result['html'], 'already in use') !== false && $module->getGroup(1)['extension'] === '500', 'Extension conflict was not rejected.');
$post['extension'] = '501';
$post['audio_mode'] = 'duplex';
$post['members'] = ['101'];
$result = $page->handle('POST', [], $post, $session);
checkAdmin($module->getGroup(1)['extension'] === '501' && $module->getGroup(1)['members'] === ['101'], 'Edit failed.');
$result = $page->handle('POST', [], ['action' => 'delete', 'id' => '1', 'csrf' => $session['advancedpaging_csrf']], $session);
checkAdmin($result['status'] === 200 && $module->getGroup(1) === null && $GLOBALS['reloadCount'] === 3, 'Delete or apply-config signal failed.');
checkAdmin($page->handle('GET', ['id' => '../1'], [], $session)['status'] === 400, 'Malformed group ID was accepted.');
echo "Administration CRUD, CSRF, validation, and escaping tests passed.\n";
