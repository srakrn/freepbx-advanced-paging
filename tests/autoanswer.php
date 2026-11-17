<?php

require_once __DIR__ . '/../src/AutoAnswerSettings.php';
require_once __DIR__ . '/../src/SessionController.php';
require_once __DIR__ . '/../src/AsteriskEffects.php';
require_once __DIR__ . '/../src/AmiOrigination.php';

use AdvancedPaging\AmiEventActions;
use AdvancedPaging\AmiOrigination;
use AdvancedPaging\AutoAnswerSettings;

final class HeaderAmi implements AmiEventActions
{
    public array $requests = [];
    public function request(array $headers): void { $this->requests[] = $headers; }
    public function waitForEvent(callable $predicate, float $timeout): ?array { return null; }
}
$settings = AutoAnswerSettings::fromPagingDefaults([
    ['var' => 'ALERTINFO', 'setting' => 'Ring Answer'],
    ['var' => 'CALLINFO', 'setting' => '<uri>\\;answer-after=0'],
    ['var' => 'DOPTIONS', 'setting' => 'A(beep)'],
]);
if ($settings !== ['ALERTINFO' => 'Ring Answer', 'CALLINFO' => '<uri>;answer-after=0']) {
    throw new RuntimeException('Incorrect FreePBX Paging defaults.');
}
$ami = new HeaderAmi();
$origin = new AmiOrigination($ami);
$origin->start('PJSIP/101', 'ap-' . str_repeat('a', 32), 5000, false, $settings);
$action = $ami->requests[0];
if (($action['PreDialGoSub'] ?? '') !== 'advancedpaging-predial,s,1'
    || !in_array('__AP_ALERTINFO=Ring Answer', $action['Variable'], true)
    || !in_array('__AP_CALLINFO=<uri>;answer-after=0', $action['Variable'], true)) {
    throw new RuntimeException('PJSIP auto-answer was not supplied to the pre-dial hook.');
}
foreach (["foo\r\nInjected: value", '${SHELL(foo)}', 'test,other'] as $unsafe) {
    try {
        AutoAnswerSettings::validate($unsafe);
        throw new RuntimeException('Unsafe header was accepted.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Unsafe FreePBX paging auto-answer header.') {
            throw $error;
        }
    }
}
echo "FreePBX auto-answer defaults and pre-dial request tests passed.\n";
