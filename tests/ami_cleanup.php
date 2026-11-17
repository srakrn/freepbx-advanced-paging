<?php

require_once __DIR__ . '/../src/SessionController.php';
require_once __DIR__ . '/../src/AsteriskEffects.php';
require_once __DIR__ . '/../src/AmiConnection.php';
require_once __DIR__ . '/../src/AmiSessionResources.php';

use AdvancedPaging\AmiConnection;
use AdvancedPaging\AmiSessionResources;

$port = getenv('AP_LAB_AMI_PORT');
$secret = getenv('AP_LAB_AMI_SECRET');
$channel = getenv('AP_LAB_CHANNEL');
$uniqueId = getenv('AP_LAB_UNIQUEID');
if (!$port || !$secret || !$channel || !$uniqueId) {
    throw new RuntimeException('Missing isolated test settings.');
}
$socket = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
if (!$socket) {
    throw new RuntimeException('Cannot connect to isolated AMI: ' . $errstr);
}
stream_set_timeout($socket, 6);
if (strpos((string) fgets($socket), 'Asterisk Call Manager/') !== 0) {
    throw new RuntimeException('Invalid AMI banner.');
}
$ami = new AmiConnection($socket);
$ami->request(['Action' => 'Login', 'Username' => 'aplab', 'Secret' => $secret, 'Events' => 'on']);
$released = false;
$resources = new AmiSessionResources($ami, static function () use (&$released): void { $released = true; });
$resources->track($channel, $uniqueId, false);
$resources->disconnectAndWait($channel);
$resources->release();
if (!$released) {
    throw new RuntimeException('Resource release was not called.');
}
fclose($socket);
echo "AMI resource cleanup confirmed channel hangup.\n";
