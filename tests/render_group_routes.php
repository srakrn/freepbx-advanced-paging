<?php

define('FREEPBX_IS_AUTH', true);
require '/var/www/html/admin/libraries/extensions.class.php';
require __DIR__ . '/../functions.inc.php';

final class RouteCollector
{
    public array $contexts = [];
    public array $includes = [];

    public function add($context, $extension, $label, $command): void
    {
        $this->contexts[$context][$extension][] = [$label, $command->output()];
    }

    public function addInclude($context, $include): void
    {
        $this->includes[] = [$context, $include];
    }
}

$ext = new RouteCollector();
advancedpaging_generate_group_routes([
    ['id' => 11, 'extension' => '8555', 'enabled' => 1],
    ['id' => 12, 'extension' => '8556', 'enabled' => 0],
]);
if ($ext->includes !== [['from-internal-noxfer-additional', 'ext-advancedpaging']]
    || isset($ext->contexts['ext-advancedpaging']['8556'])
    || !isset($ext->contexts['ext-advancedpaging']['8555'])) {
    throw new RuntimeException('Generated routes were not filtered correctly.');
}
try {
    advancedpaging_generate_group_routes([['id' => 7, 'extension' => '1${SHELL(x)}', 'enabled' => 1]]);
    throw new RuntimeException('Invalid dialplan extension was accepted.');
} catch (InvalidArgumentException $expected) {
}
foreach ($ext->contexts as $context => $extensions) {
    echo '[' . $context . "]\n";
    foreach ($extensions as $extension => $commands) {
        foreach ($commands as $index => [$label, $command]) {
            $priority = $index === 0 ? '1' : 'n';
            if ($label !== '') {
                $priority .= '(' . $label . ')';
            }
            echo ($index === 0 ? 'exten => ' . $extension . ',' : ' same => ') . $priority . ',' . $command . "\n";
        }
    }
}
