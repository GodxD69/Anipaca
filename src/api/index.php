<?php
/**
 * Local catalog API — Jikan/MAL metadata replacing zen-api.
 * Base URL: /src/api  (set as $zpi in _config.php)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/jikan_client.php';

$path = $_GET['__path'] ?? '';
if ($path === '' || $path === null) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    // Strip /src/api prefix
    if (preg_match('#/src/api(?:/(.*))?$#', $uri, $m)) {
        $path = $m[1] ?? '';
    } elseif (preg_match('#/api(?:/(.*))?$#', $uri, $m)) {
        $path = $m[1] ?? '';
    }
}
$path = trim((string)$path, '/');
$parts = $path === '' ? [] : explode('/', $path);
$head = $parts[0] ?? '';
$tail = $parts[1] ?? null;
$tail2 = $parts[2] ?? null;

$page = max(1, (int)($_GET['page'] ?? 1));

try {
    // Home composite: GET /src/api or /src/api/
    if ($head === '') {
        echo json_encode(jikan_home());
        exit;
    }

    switch ($head) {
        case 'search':
            $keyword = trim((string)($_GET['keyword'] ?? $_GET['q'] ?? ''));
            echo json_encode(jikan_search($keyword, $page));
            break;

        case 'filter':
            echo json_encode(jikan_filter($_GET));
            break;

        case 'info':
            $id = (string)($_GET['id'] ?? '');
            echo json_encode($id === '' ? jikan_fail('Missing id') : jikan_info($id));
            break;

        case 'top-ten':
            echo json_encode(jikan_top_ten());
            break;

        case 'recently-updated':
        case 'recently-added':
        case 'top-upcoming':
        case 'top-airing':
        case 'most-popular':
        case 'most-favorite':
        case 'completed':
        case 'movie':
        case 'movies':
        case 'tv':
        case 'ova':
        case 'ona':
        case 'special':
            $cfg = jikan_category_query($head, $page);
            echo json_encode(jikan_list_response(jikan_request($cfg['path'], $cfg['query'])));
            break;

        case 'genre':
            if ($tail === null) {
                echo json_encode(jikan_fail('Missing genre'));
            } else {
                echo json_encode(jikan_genre($tail, $page));
            }
            break;

        case 'producer':
            if ($tail === null) {
                echo json_encode(jikan_fail('Missing producer'));
            } else {
                echo json_encode(jikan_producer($tail, $page));
            }
            break;

        case 'az-list':
            echo json_encode(jikan_az($tail, $page));
            break;

        case 'episodes':
            if ($tail === null) {
                echo json_encode(jikan_fail('Missing anime id'));
            } else {
                echo json_encode(jikan_episodes($tail));
            }
            break;

        case 'servers':
            // No streaming source — empty list so UI fails gracefully
            echo json_encode(jikan_ok([]));
            break;

        case 'stream':
            echo json_encode(jikan_fail('Streaming is not available with the MAL/Jikan metadata API'));
            break;

        case 'schedule':
            if ($tail !== null) {
                echo json_encode(jikan_anime_schedule($tail));
            } else {
                echo json_encode(jikan_schedule($_GET['date'] ?? null, $_GET['tzOffset'] ?? 0));
            }
            break;

        case 'random':
            echo json_encode(jikan_random());
            break;

        case 'character':
            if ($tail === 'list' && $tail2 !== null) {
                echo json_encode(jikan_character_list($tail2));
            } elseif ($tail !== null) {
                echo json_encode(jikan_character($tail));
            } else {
                echo json_encode(jikan_fail('Missing character id'));
            }
            break;

        case 'actors':
            if ($tail === null) {
                echo json_encode(jikan_fail('Missing actor id'));
            } else {
                echo json_encode(jikan_actor($tail));
            }
            break;

        default:
            // Category passthrough: /src/api/{category}?page=
            $cfg = jikan_category_query($head, $page);
            echo json_encode(jikan_list_response(jikan_request($cfg['path'], $cfg['query'])));
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(jikan_fail('API error: ' . $e->getMessage()));
}
