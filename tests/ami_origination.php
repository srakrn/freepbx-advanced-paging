<?php

foreach (['SessionController', 'AsteriskEffects', 'AmiConnection', 'AmiOrigination'] as $file) {
    require_once __DIR__ . '/../src/' . $file . '.php';
}

use AdvancedPaging\AmiConnection;
use AdvancedPaging\AmiOrigination;

$port = getenv('AP_LAB_AMI_PORT');
$secret = getenv('AP_LAB_AMI_SECRET');
$room = getenv('AP_LAB_CONFERENCE');
if (!$port || !$secret || !$room) {
    throw new RuntimeException('Missing isolated origination settings.');
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
$ami->request(['Action' => 'Login', 'Username' => getenv('AP_LAB_AMI_USERNAME'), 'Secret' => $secret, 'Events' => 'on']);
$origin = new AmiOrigination($ami);
$origin->start('Local/unanswered@advancedpaging-lab/n', $room, 10000, true);
$origin->cancelAndWait(8.0);
if (!$origin->cancelConfirmed()) {
    throw new RuntimeException('Unanswered origination was not fully cancelled.');
}
fclose($socket);
echo "Pending Local origination cancelled with both channel legs confirmed ended.\n";
