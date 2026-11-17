<?php

require_once __DIR__ . '/../src/SessionController.php';
require_once __DIR__ . '/../src/AsteriskEffects.php';
require_once __DIR__ . '/../src/AmiSessionResources.php';

use AdvancedPaging\AmiEventActions;
use AdvancedPaging\AmiSessionResources;

final class ResourceAmi implements AmiEventActions
{
    public array $requests = [];
    public array $events = [];
    public function request(array $headers): void { $this->requests[] = $headers; }
    public function waitForEvent(callable $predicate, float $timeout): ?array
    {
        foreach ($this->events as $i => $event) {
            if ($predicate($event)) {
                array_splice($this->events, $i, 1);
                return $event;
            }
        }
        return null;
    }
}
function assertResource(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$ami = new ResourceAmi();
$released = 0;
$resources = new AmiSessionResources($ami, static function () use (&$released): void { ++$released; });
$resources->track('PJSIP/101-00000001', 'u1', true);
$resources->track('PJSIP/102-00000002', 'u2', false);
$ami->events[] = ['Event' => 'Hangup', 'Channel' => 'PJSIP/101-00000001', 'Uniqueid' => 'u1'];
$resources->cancelAndWait();
assertResource(!$ami->requests, 'Already ended channel should not be hung up again.');
try {
    $resources->release();
    throw new RuntimeException('Expected release to fail.');
} catch (RuntimeException $error) {
    assertResource($error->getMessage() === 'Cannot release a session with active channels.', 'Unexpected release error.');
}
$resources->observe(['Event' => 'Hangup', 'Channel' => 'PJSIP/102-00000002', 'Uniqueid' => 'wrong']);
try {
    $resources->disconnectAndWait('PJSIP/102-00000002');
    throw new RuntimeException('Expected unconfirmed hangup to fail.');
} catch (RuntimeException $error) {
    assertResource($error->getMessage() === 'Channel hangup was not confirmed.', 'Unexpected hangup error.');
}
assertResource(count($ami->requests) === 1, 'Expected AMI hangup action.');
$ami->events[] = ['Event' => 'Hangup', 'Channel' => 'PJSIP/102-00000002', 'Uniqueid' => 'u2'];
$resources->disconnectAndWait('PJSIP/102-00000002');
$resources->release();
$resources->release();
assertResource($released === 1, 'Release not idempotent.');
echo "AMI resource registry tests passed.\n";
