<?php

// Test-only renderer using the actual FreePBX 17 dialplan command classes.
define('FREEPBX_IS_AUTH', true);
require '/var/www/html/admin/libraries/extensions.class.php';
require __DIR__ . '/../functions.inc.php';

final class TestDialplanCollector
{
    private array $contexts = [];

    public function add($context, $extension, $label, $command): void
    {
        if ($extension !== 's' || $label !== '') {
            throw new RuntimeException('Unexpected generated extension.');
        }
        $this->contexts[$context][] = $command->output();
    }

    public function render(): string
    {
        $output = '';
        foreach ($this->contexts as $context => $commands) {
            $output .= '[' . $context . "]\n";
            foreach ($commands as $index => $command) {
                $output .= ($index === 0 ? 'exten => s,1,' : ' same => n,') . $command . "\n";
            }
        }
        return $output;
    }
}

$ext = new TestDialplanCollector();
advancedpaging_get_config('asterisk');
$output = $ext->render();
foreach (['advancedpaging-receiver', 'advancedpaging-inject', 'advancedpaging-player', 'advancedpaging-predial'] as $context) {
    if (strpos($output, '[' . $context . ']') === false) {
        throw new RuntimeException('Missing generated context: ' . $context);
    }
}
echo $output;
