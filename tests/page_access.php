<?php

define('FREEPBX_IS_AUTH', true);
$_SESSION = ['AMP_user' => new class {
    public function checkSection($section): bool { return false; }
}];
ob_start();
include __DIR__ . '/../page.advancedpaging.php';
$output = ob_get_clean();
if (http_response_code() !== 403 || strpos($output, 'Access denied.') === false) {
    throw new RuntimeException('Unauthorized FreePBX section access was not denied.');
}
echo "FreePBX section authorization test passed.\n";
