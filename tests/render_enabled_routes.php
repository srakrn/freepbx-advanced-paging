<?php

define('FREEPBX_IS_AUTH', true);
require '/var/www/html/admin/libraries/extensions.class.php';
require __DIR__ . '/../functions.inc.php';

final class EnabledRouteCollector
{
    public array $entries = [];
    public array $includes = [];
    public function add($context, $extension, $label, $command): void
    {
        $this->entries[$context][$extension][] = [$label, $command->output()];
    }
    public function addInclude($context, $include): void { $this->includes[] = [$context, $include]; }
}

$ext = new EnabledRouteCollector();
$ext->addInclude('from-internal-noxfer-additional', 'ext-advancedpaging');
advancedpaging_generate_group_routes([
    ['id' => 1, 'extension' => '8555', 'enabled' => 1, 'instruction_sounds' => 'custom/instruction'],
    ['id' => 2, 'extension' => '8556', 'enabled' => 0],
]);
if (!isset($ext->entries['ext-advancedpaging']['8555'])
    || isset($ext->entries['ext-advancedpaging']['8556'])) {
    throw new RuntimeException('Enabled/disabled route generation failed.');
}
$start = array_column($ext->entries['app-advancedpaging']['1'], 1);
$dialplan = implode("\n", $start);
foreach (['UUID()', 'AdvancedPagingStart', 'AP_ADMITTED', 'AP_ADMISSION_TRIES', 'Hangup(41)', 'ConfBridge(${AP_CONF}'] as $expected) {
    if (strpos($dialplan, $expected) === false) {
        throw new RuntimeException('Admission-safe route missing ' . $expected . ': ' . $dialplan);
    }
}
if (strpos($dialplan, 'Playback(custom/instruction)') === false
    || strpos($dialplan, 'Playback(custom/instruction)') > strpos($dialplan, 'AdvancedPagingStart')) {
    throw new RuntimeException('Caller instruction must play before receiver admission.');
}
if (strpos($dialplan, 'Wait(') === false || strpos($dialplan, 'GotoIf(') === false) {
    throw new RuntimeException('Admission watchdog was not rendered.');
}
echo "Enabled route renderer includes start signaling and fail-closed watchdog.\n";
