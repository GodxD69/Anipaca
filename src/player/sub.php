<?php
/**
 * VidHawk embed shim — SUB
 * https://vidhawk.buzz/embed/{ani|mal}/{id}/{ep}/sub?server=kari|flow|zuri|gojo
 */
require_once($_SERVER['DOCUMENT_ROOT'] . '/_config.php');

header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self';");

$malId = preg_replace('/[^0-9]/', '', (string)($_GET['mal'] ?? $_GET['id'] ?? ''));
$anilistId = preg_replace('/[^0-9]/', '', (string)($_GET['anilist'] ?? ''));
$ep = max(1, (int)($_GET['ep'] ?? 1));
$server = strtolower((string)($_GET['server'] ?? 'kari'));
$allowed = ['vidlink', 'kari', 'flow', 'zuri', 'gojo'];
if (!in_array($server, $allowed, true)) {
    $server = 'vidlink';
}

$embedUrl = '';
if ($server === 'vidlink') {
    if ($malId !== '') {
        $embedUrl = "https://vidlink.pro/anime/{$malId}/{$ep}/sub?fallback=true&primaryColor=ff5c8a&secondaryColor=1f1f2b&icons=vid";
    } elseif ($anilistId !== '') {
        $embedUrl = "https://vidlink.pro/anime/{$anilistId}/{$ep}/sub?fallback=true&primaryColor=ff5c8a&secondaryColor=1f1f2b&icons=vid";
    }
} elseif (in_array($server, ['flow', 'gojo'], true)) {
    if ($malId !== '') {
        $embedUrl = "https://vidhawk.buzz/embed/mal/{$malId}/{$ep}/sub?server={$server}";
    } elseif ($anilistId !== '') {
        $embedUrl = "https://vidhawk.buzz/embed/ani/{$anilistId}/{$ep}/sub?server={$server}";
    }
} elseif (in_array($server, ['kari', 'zuri'], true)) {
    if ($anilistId !== '') {
        $embedUrl = "https://vidhawk.buzz/embed/ani/{$anilistId}/{$ep}/sub?server={$server}";
    } elseif ($malId !== '') {
        $embedUrl = "https://vidhawk.buzz/embed/mal/{$malId}/{$ep}/sub?server={$server}";
    }
}

if ($embedUrl === '') {
    if ($malId !== '') {
        $embedUrl = "https://vidlink.pro/anime/{$malId}/{$ep}/sub?fallback=true&primaryColor=ff5c8a";
    } elseif ($anilistId !== '') {
        $embedUrl = "https://vidhawk.buzz/embed/ani/{$anilistId}/{$ep}/sub?server=kari";
    } else {
        http_response_code(400);
        echo 'Missing anime id';
        exit;
    }
}

$embedUrl = htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        html, body { margin: 0; padding: 0; width: 100%; height: 100%; background: #000; overflow: hidden; }
        iframe { border: 0; width: 100%; height: 100%; }
    </style>
</head>
<body>
    <iframe src="<?= $embedUrl ?>" allowfullscreen allow="autoplay; fullscreen; picture-in-picture" referrerpolicy="no-referrer"></iframe>
</body>
</html>
