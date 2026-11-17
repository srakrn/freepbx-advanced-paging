<?php

// Invoke FreePBX 17's actual pre-install include runner against a temporary
// module directory. No FreePBX configuration or database is loaded.
require '/var/www/html/admin/libraries/Composer/vendor/autoload.php';
require '/var/www/html/admin/libraries/modulefunctions.class.php';

$xml = simplexml_load_file(__DIR__ . '/../module.xml');
if (!$xml || (string) ($xml->fileinclude->install ?? '') !== 'preinstall.php') {
    throw new RuntimeException('Module pre-install guard is not registered.');
}
$root = sys_get_temp_dir() . '/ap-preinstall-' . bin2hex(random_bytes(8));
$moduleDir = $root . '/admin/modules/advancedpaging';
if (!mkdir($moduleDir, 0700, true) || !copy(__DIR__ . '/../preinstall.php', $moduleDir . '/preinstall.php')) {
    throw new RuntimeException('Could not create temporary module directory.');
}
$amp_conf = ['AMPWEBROOT' => $root];
try {
    $runner = new module_functions();
    try {
        $runner->_runscripts_include([
            'advancedpaging' => ['fileinclude' => ['install' => 'preinstall.php']],
        ], 'install');
        throw new RuntimeException('FreePBX pre-install guard did not reject installation.');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Advanced Paging is a development preview and cannot be installed.') {
            throw $error;
        }
    }
    echo "FreePBX 17 pre-install guard rejected before schema migration.\n";
} finally {
    unlink($moduleDir . '/preinstall.php');
    rmdir($moduleDir);
    rmdir($root . '/admin/modules');
    rmdir($root . '/admin');
    rmdir($root);
}
