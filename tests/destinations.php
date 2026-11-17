<?php

define('FREEPBX_IS_AUTH', true);

final class DestinationTestModule
{
    private array $groups = [
        1 => ['id' => 1, 'name' => '<Office>', 'extension' => '8555', 'enabled' => 1],
        2 => ['id' => 2, 'name' => 'Disabled', 'extension' => '8556', 'enabled' => 0],
    ];
    public function listGroups(): array { return array_values($this->groups); }
    public function getGroup(int $id): ?array { return $this->groups[$id] ?? null; }
}
class FreePBX
{
    public static function Advancedpaging(): DestinationTestModule { static $m; return $m ??= new DestinationTestModule(); }
}
require __DIR__ . '/../functions.inc.php';

$checked = advancedpaging_check_extensions(true);
if (!isset($checked['8555'], $checked['8556']) || $checked['8555']['status'] !== 'INUSE') {
    throw new RuntimeException('Extension usage hook omitted reserved group numbers.');
}
if (array_keys(advancedpaging_check_extensions(['8556'])) !== [8556]
    || advancedpaging_check_extensions([]) !== []) {
    throw new RuntimeException('Extension usage filter failed.');
}
$destinations = advancedpaging_destinations();
if (count($destinations) !== 1 || $destinations[0]['destination'] !== 'app-advancedpaging,1,1'
    || strpos($destinations[0]['description'], '<Office>') === false) {
    throw new RuntimeException('Destination listing failed.');
}
if (advancedpaging_getdest('1') !== ['app-advancedpaging,1,1']
    || advancedpaging_getdest('2') !== []
    || advancedpaging_getdest('../1') !== []) {
    throw new RuntimeException('Destination resolver validation failed.');
}
if (!advancedpaging_getdestinfo('app-advancedpaging,1,1')
    || advancedpaging_getdestinfo('app-advancedpaging,2,1') !== []
    || advancedpaging_getdestinfo('app-advancedpaging,1,1,${SHELL(x)}') !== false) {
    throw new RuntimeException('Destination information validation failed.');
}
echo "FreePBX extension-usage and destination hooks passed.\n";
