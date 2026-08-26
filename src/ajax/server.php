<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// VidHawk servers — same set for Sub and Dub
$vidhawkServers = [
    ['serverName' => 'Kari', 'serverId' => 'kari'],
    ['serverName' => 'Flow', 'serverId' => 'flow'],
    ['serverName' => 'Zuri', 'serverId' => 'zuri'],
    ['serverName' => 'Gojo', 'serverId' => 'gojo'],
];

echo json_encode([
    'success' => true,
    'sub' => $vidhawkServers,
    'dub' => $vidhawkServers,
]);
