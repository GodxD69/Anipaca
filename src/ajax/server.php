<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Streaming servers: High-speed primary VidLink + VidHawk cluster
$servers = [
    ['serverName' => 'VidLink', 'serverId' => 'vidlink'],
    ['serverName' => 'Kari', 'serverId' => 'kari'],
    ['serverName' => 'Flow', 'serverId' => 'flow'],
    ['serverName' => 'Zuri', 'serverId' => 'zuri'],
    ['serverName' => 'Gojo', 'serverId' => 'gojo'],
];

echo json_encode([
    'success' => true,
    'sub' => $servers,
    'dub' => $servers,
]);
