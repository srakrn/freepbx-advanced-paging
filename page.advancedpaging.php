<?php

if (!defined('FREEPBX_IS_AUTH')) {
    die('No direct script access allowed');
}
if (!isset($_SESSION['AMP_user']) || !is_object($_SESSION['AMP_user'])
    || !$_SESSION['AMP_user']->checkSection('advancedpaging')) {
    http_response_code(403);
    echo 'Access denied.';
    return;
}
require_once __DIR__ . '/src/AdminPage.php';
$page = new \AdvancedPaging\AdminPage(\FreePBX::Advancedpaging(), \FreePBX::Core(), \FreePBX::Recordings());
$result = $page->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_GET, $_POST, $_SESSION);
http_response_code($result['status']);
echo $result['html'];
