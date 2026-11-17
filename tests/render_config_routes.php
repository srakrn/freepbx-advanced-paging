<?php

define('FREEPBX_IS_AUTH', true);
require '/var/www/html/admin/libraries/extensions.class.php';

final class RouteTestModule
{
    public function listGroups(): array
    {
        return [
            ['id' => '21', 'extension' => '8555', 'enabled' => 1, 'name' => 'Office'],
            ['id' => '22', 'extension' => '8556', 'enabled' => 0, 'name' => 'Disabled'],
        ];
    }
    public function getDropSilence(): bool { return false; }
}
class FreePBX
{
    public static function Advancedpaging(): RouteTestModule { return new RouteTestModule(); }
}
require __DIR__ . '/../functions.inc.php';

final class ConfigRouteCollector
{
    public array $contexts = [];
    public array $includes = [];
    public function add($context, $extension, $label, $command): void {
        $this->contexts[$context][$extension][] = [$label, $command->output()];
    }
    public function addInclude($context, $included): void { $this->includes[] = [$context, $included]; }
}
$ext = new ConfigRouteCollector();
advancedpaging_get_config('asterisk');
if (!isset($ext->contexts['ext-advancedpaging']['8555'])
    || isset($ext->contexts['ext-advancedpaging']['8556'])
    || $ext->includes !== [['from-internal-noxfer-additional', 'ext-advancedpaging']]) {
    throw new RuntimeException('FreePBX get_config did not register enabled routes.');
}
$steps = array_column($ext->contexts['app-advancedpaging']['21'], 1);
$rendered = implode("\n", $steps);
foreach (['AdvancedPagingStart', 'AP_ADMITTED', 'Hangup(41)', 'ConfBridge(${AP_CONF}', 'CONFBRIDGE(user,dsp_drop_silence)=no'] as $expected) {
    if (strpos($rendered, $expected) === false) {
        throw new RuntimeException('Runtime admission route lacks ' . $expected);
    }
}
echo "FreePBX get_config registered enabled dialable route with fail-closed admission.\n";
