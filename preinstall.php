<?php

// FreePBX 17 invokes install includes before XML schema migration. Validate
// prerequisites here; the BMO install() provisions the runtime only after
// FreePBX has created the module's tables.
if (PHP_VERSION_ID < 70400 || !extension_loaded('pdo')) {
    throw new RuntimeException('Advanced Paging requires PHP 7.4 or later with PDO.');
}
if (!is_dir('/etc/asterisk') || is_link('/etc/asterisk')) {
    throw new RuntimeException('Asterisk configuration directory is unavailable.');
}
return true;
