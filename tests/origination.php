<?php

foreach (['SessionController', 'AsteriskEffects', 'AmiOrigination'] as $file) {
    require_once __DIR__ . '/../src/' . $file . '.php';
}

use AdvancedPaging\AmiEventActions;
use AdvancedPaging\AmiOrigination;

final class OriginationAmi implements AmiEventActions
{
    public array $requests = [];
    public array $events = [];
    public function request(array $headers): void { $this->requests[] = $headers; }
    public function waitForEvent(callable $predicate, float $timeout): ?array
    {
        foreach ($this->events as $index => $event) {
            if ($predicate($event)) {
                array_splice($this->events, $index, 1);
                return $event;
            }
        }
        return null;
    }
}
function checkOrigination(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

$ami = new OriginationAmi();
$origin = new AmiOrigination($ami);
$origin->start('Local/test@lab/n', 'ap-' . str_repeat('a', 32), 5000, true);
$request = $ami->requests[0];
$primary = $request['ChannelId'];
$peer = $request['OtherChannelId'];
$action = $request['ActionID'];
$ami->events = [
    ['Event' => 'Newchannel', 'Uniqueid' => $primary, 'Channel' => 'Local/test@lab-00000001;1'],
    ['Event' => 'Newchannel', 'Uniqueid' => $peer, 'Channel' => 'Local/test@lab-00000001;2'],
    ['Event' => 'Hangup', 'Uniqueid' => $primary],
    ['Event' => 'Hangup', 'Uniqueid' => $peer],
    ['Event' => 'OriginateResponse', 'ActionID' => $action, 'Response' => 'Failure', 'Uniqueid' => $primary],
];
$origin->cancelAndWait(1);
checkOrigination($origin->cancelConfirmed(), 'Both Local legs were not confirmed.');
checkOrigination(count($ami->requests) === 3, 'Both Local legs should have been cancelled.');

$ami = new OriginationAmi();
$uncertain = new AmiOrigination($ami);
$uncertain->start('Local/test@lab/n', 'ap-' . str_repeat('b', 32), 5000, true);
$request = $ami->requests[0];
$ami->events = [
    ['Event' => 'OriginateResponse', 'ActionID' => $request['ActionID'], 'Response' => 'Failure', 'Uniqueid' => $request['ChannelId']],
];
try {
    $uncertain->cancelAndWait(0.01);
    throw new RuntimeException('Unobserved channel must not be treated as ended.');
} catch (RuntimeException $error) {
    checkOrigination($error->getMessage() === 'Origination cancellation was not confirmed.', 'Unexpected cancellation error.');
}
checkOrigination(!$uncertain->cancelConfirmed(), 'Uncertain origination was marked complete.');

$ami = new OriginationAmi();
$whisper = new AmiOrigination($ami);
$whisper->start('PJSIP/210/sip:210@192.0.2.10:5060', 'ap-' . str_repeat('c', 32), 5000, false,
    ['ALERTINFO' => 'Ring Answer', 'CALLINFO' => ''], true, ['number' => '101', 'name' => 'Caller One']);
$request = $ami->requests[0];
checkOrigination($request['Channel'] === 'Local/s@advancedpaging-whisper/n'
    && $request['Application'] === 'ConfBridge'
    && $request['Data'] === 'ap-' . str_repeat('c', 32) . ',,,user_menu'
    && in_array('__AP_SP_DEVICE=PJSIP/210', $request['Variable'], true)
    && in_array('__CONFBRIDGE(user,startmuted)=no', $request['Variable'], true)
    && $request['CallerID'] === 'Caller One <101>',
    'Whisper origination was not routed through the ChanSpy conference worker.');

try {
    (new AmiOrigination($ami))->start('PJSIP/123@outside', 'ap-' . str_repeat('a', 32), 5000);
    throw new RuntimeException('External dial string accepted.');
} catch (InvalidArgumentException $expected) {
}
echo "Origination tracking and failure tests passed.\n";
