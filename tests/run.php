<?php

require __DIR__ . '/../src/GroupValidator.php';
require __DIR__ . '/../src/Session.php';
require __DIR__ . '/../src/GroupRepository.php';

use AdvancedPaging\GroupValidator;
use AdvancedPaging\Session;
use AdvancedPaging\GroupRepository;

function check(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $fn): void
{
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException('Expected validation failure.');
}

$validator = new GroupValidator(
    fn ($extension) => $extension === '100',
    fn ($extension) => in_array($extension, ['101', '102'], true),
    fn ($id) => $id === '7'
);
$group = [
    'name' => ' Office ', 'extension' => '500', 'enabled' => '1',
    'audio_mode' => 'simplex', 'members' => ['101', '101', '102'],
    'pre_recording_id' => '7', 'post_recording_id' => '',
];
$valid = $validator->validate($group);
check($valid['name'] === 'Office' && $valid['members'] === ['101', '102'], 'Normalization failed.');
check($valid['pre_recording_id'] === '7' && $valid['post_recording_id'] === null, 'Recording normalization failed.');
foreach ([
    ['extension' => '100'], ['extension' => '5${X}'], ['members' => ['999']],
    ['audio_mode' => 'broadcast'], ['pre_recording_id' => '../audio'],
    ['enabled' => 'yes'], ['name' => []],
] as $override) {
    rejects(fn () => $validator->validate(array_replace($group, $override)));
}
check($validator->validate(array_replace($group, ['extension' => '100']), '100')['extension'] === '100', 'Editing failed.');

$session = new Session();
$other = new Session();
check($session->id() !== $other->id(), 'Session IDs collided.');
$session->connect();
$session->setupComplete(true);
check($session->initiatorHungUp(true, true), 'Hangup should claim termination.');
check(!$session->initiatorHungUp(true, true), 'Duplicate hangup claimed termination.');
check($session->state() === Session::POST_PAGE, 'Expected closing phase.');
$session->postPageComplete();
check(!$session->terminate(), 'Termination must be idempotent.');
$session->cleanupComplete();
$session->cleanupComplete();
check($session->state() === Session::TERMINATED, 'Cleanup failed.');

$session = new Session();
$session->connect();
$session->initiatorHungUp(true, true);
check($session->state() === Session::TERMINATING, 'Setup hangup must cancel setup.');

$session = new Session();
$session->connect();
$session->setupComplete(false);
check($session->state() === Session::ACTIVE, 'Missing pre-page should be skipped.');
$session->initiatorHungUp(true, false);
check($session->state() === Session::TERMINATING, 'Missing post-page should be skipped.');

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE advancedpaging_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, extension TEXT NOT NULL UNIQUE, enabled INTEGER NOT NULL, audio_mode TEXT NOT NULL, pre_recording_id TEXT, post_recording_id TEXT)');
$db->exec('CREATE TABLE advancedpaging_members (group_id INTEGER NOT NULL, extension TEXT NOT NULL, PRIMARY KEY (group_id, extension))');
$repository = new GroupRepository($db);
$first = $repository->save($valid);
$secondGroup = $valid;
$secondGroup['extension'] = '501';
$secondGroup['members'] = ['101'];
$second = $repository->save($secondGroup);
check(count($repository->list()) === 2, 'Expected two independent groups.');
check($repository->get($first)['members'] === ['101', '102'], 'Members not persisted.');
$secondGroup['audio_mode'] = 'duplex';
$secondGroup['members'] = ['102'];
$repository->save($secondGroup, $second);
check($repository->get($first)['audio_mode'] === 'simplex', 'Update affected unrelated group.');
check($repository->get($second)['members'] === ['102'], 'Members not replaced.');
try {
    $broken = $secondGroup;
    $broken['members'] = ['101', '101'];
    $repository->save($broken, $second);
    throw new RuntimeException('Expected duplicate member error.');
} catch (PDOException $expected) {
    check($repository->get($second)['members'] === ['102'], 'Failed update did not roll back.');
}
try {
    $repository->save($valid);
    throw new RuntimeException('Expected duplicate extension error.');
} catch (PDOException $expected) {
    check(count($repository->list()) === 2, 'Failed insert did not roll back.');
}
check($repository->delete($first), 'Delete failed.');
check($repository->get($first) === null && $repository->get($second) !== null, 'Delete affected unrelated group.');
check(!$repository->delete($first), 'Repeated delete should report no group.');

echo "Core tests passed.\n";
