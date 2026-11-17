<?php

require_once __DIR__ . '/../src/RuntimeConfig.php';
require_once __DIR__ . '/../src/RuntimeProvisioner.php';

use AdvancedPaging\RuntimeConfig;
use AdvancedPaging\RuntimeProvisioner;

$etc = $argv[1] ?? '';
$run = $argv[2] ?? '';
$port = (int) ($argv[3] ?? 5038);
if ($etc === '' || $run === '') {
    throw new RuntimeException('Temporary provisioning paths are required.');
}
(new RuntimeProvisioner($etc, $run, null, $etc . '/test.lock', null, null, $port))->install();
echo json_encode(RuntimeConfig::read($etc . '/advancedpaging-runtime.ini'), JSON_THROW_ON_ERROR) . "\n";
