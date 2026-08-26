<?php
/**
 * Jikan (MAL unofficial) client + mappers for AniPaca's former zen-api shapes.
 * Metadata only — no streaming.
 */

define('JIKAN_BASE', 'https://api.jikan.moe/v4');
define('JIKAN_CACHE_DIR', rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'jikan');
define('JIKAN_CACHE_TTL', 3600);
define('JIKAN_HOME_TTL', 1800);
define('JIKAN_TIMEOUT', 6);
define('JIKAN_CONNECT_TIMEOUT', 3);
define('JIKAN_CIRCUIT_SECONDS', 90);

function jikan_ensure_cache_dir(): void
{
    if (!is_dir(JIKAN_CACHE_DIR)) {
        mkdir(JIKAN_CACHE_DIR, 0755, true);
    }
}

function jikan_cache_file(string $key): string
{
    return JIKAN_CACHE_DIR . DIRECTORY_SEPARATOR . md5($key) . '.json';
}

function jikan_cache_get(string $key, int $ttl = JIKAN_CACHE_TTL)
{
    jikan_ensure_cache_dir();
    $file = jikan_cache_file($key);
    if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
        $data = json_decode((string)file_get_contents($file), true);
        return $data === null ? null : $data;
    }
    return null;
}

/** Return cache even if expired (outage fallback). */
function jikan_cache_get_stale(string $key)
{
    jikan_ensure_cache_dir();
    $file = jikan_cache_file($key);
    if (!file_exists($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return $data === null ? null : $data;
}

function jikan_cache_set(string $key, $data): void
{
    jikan_ensure_cache_dir();
    file_put_contents(jikan_cache_file($key), json_encode($data));
}

function jikan_circuit_file(): string
{
    return JIKAN_CACHE_DIR . DIRECTORY_SEPARATOR . '_circuit.json';
}

function jikan_circuit_open(): bool
{
    $file = jikan_circuit_file();
    if (!file_exists($file)) {
        return false;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return !empty($data['until']) && time() < (int)$data['until'];
}

function jikan_circuit_trip(): void
{
    jikan_ensure_cache_dir();
    file_put_contents(jikan_circuit_file(), json_encode([
        'until' => time() + JIKAN_CIRCUIT_SECONDS,
        'at' => date('c'),
    ]));
}

function jikan_circuit_clear(): void
{
    $file = jikan_circuit_file();
    if (file_exists($file)) {
        @unlink($file);
    }
}

/**
 * Upstream GET — fail-fast. Default 1 attempt, 6s timeout, stale-cache fallback.
 */
function jikan_request(string $path, array $query = [], int $ttl = JIKAN_CACHE_TTL, int $maxAttempts = 1): ?array
{
    $url = JIKAN_BASE . $path;
    if ($query) {
        $url .= (strpos($path, '?') === false ? '?' : '&') . http_build_query($query);
    }

    $fresh = jikan_cache_get($url, $ttl);
    if ($fresh !== null) {
        return $fresh;
    }

    if (jikan_circuit_open()) {
        return jikan_cache_get_stale($url);
    }

    static $lastCall = 0.0;
    $attempts = 0;
    $body = false;
    $code = 0;

    while ($attempts < max(1, $maxAttempts)) {
        $attempts++;
        $elapsed = microtime(true) - $lastCall;
        if ($elapsed < 0.35) {
            usleep((int)((0.35 - $elapsed) * 1_000_000));
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => JIKAN_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => JIKAN_CONNECT_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: AniPaca-JikanAdapter/1.0'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $lastCall = microtime(true);

        if (($code === 429 || $code === 502 || $code === 503 || $code === 504) && $attempts < $maxAttempts) {
            usleep(400000 * $attempts);
            continue;
        }
        break;
    }

    if ($body === false || $code >= 400) {
        jikan_circuit_trip();
        return jikan_cache_get_stale($url);
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        jikan_circuit_trip();
        return jikan_cache_get_stale($url);
    }

    jikan_circuit_clear();
    jikan_cache_set($url, $data);
    return $data;
}

/** Category/list helper for home partials (no nested HTTP). */
function jikan_category_list(string $category, int $page = 1): array
{
    require_once __DIR__ . '/anilist_client.php';

    $cfg = jikan_category_query($category, $page);
    $list = jikan_list_response(jikan_request($cfg['path'], $cfg['query'], JIKAN_CACHE_TTL, 1));
    if (!empty($list['results']['data'])) {
        return $list;
    }

    $al = anilist_category($category, $page);
    if (!empty($al['results']['data'])) {
        return $al;
    }

    // Soft fallback from cached home so Latest/Upcoming never hang empty forever
    $homeKeyMap = [
        'recently-updated' => 'topAiring',
        'recently-added' => 'topAiring',
        'top-upcoming' => 'mostPopular',
        'top-airing' => 'topAiring',
        'most-popular' => 'mostPopular',
        'most-favorite' => 'mostFavorite',
        'completed' => 'latestCompleted',
        'subbed-anime' => 'mostPopular',
        'dubbed-anime' => 'mostPopular',
        'movie' => 'mostPopular',
        'tv' => 'topAiring',
    ];
    $homeKey = $homeKeyMap[$category] ?? null;
    if ($homeKey) {
        $home = jikan_cache_get_stale('home:composite:v3') ?: jikan_cache_get_stale('home:composite:v2');
        $cards = $home['results'][$homeKey] ?? [];
        if ($cards) {
            return jikan_ok([
                'data' => $cards,
                'currentPage' => 1,
                'totalPage' => 1,
                'totalPages' => 1,
                'hasNextPage' => false,
                'total' => count($cards),
            ]);
        }
    }
    return $list;
}

function jikan_ok($results): array
{
    return ['success' => true, 'results' => $results];
}

function jikan_fail(string $message = 'Request failed'): array
{
    return ['success' => false, 'message' => $message, 'results' => null];
}

function jikan_title(array $a): string
{
    return $a['title_english'] ?? $a['title'] ?? ($a['titles'][0]['title'] ?? 'Unknown');
}

function jikan_jname(array $a): string
{
    return $a['title_japanese'] ?? $a['title'] ?? jikan_title($a);
}

function jikan_poster(array $a): string
{
    return $a['images']['jpg']['large_image_url']
        ?? $a['images']['jpg']['image_url']
        ?? $a['images']['webp']['large_image_url']
        ?? '';
}

function jikan_is_adult(array $a): bool
{
    $rating = (string)($a['rating'] ?? '');
    return stripos($rating, 'R+') !== false || stripos($rating, 'Rx') !== false;
}

function jikan_map_card(array $a): array
{
    // Ongoing shows often have episodes=null on MAL — keep ? until enriched
    $eps = $a['episodes'] ?? null;
    if ($eps === null || $eps === '' || (int)$eps <= 0) {
        $eps = '?';
    } else {
        $eps = (int)$eps;
    }
    $duration = $a['duration'] ?? '';
    $type = $a['type'] ?? 'TV';
    $from = $a['aired']['from'] ?? null;
    $releaseDate = $from ? substr((string)$from, 0, 10) : '';

    return [
        'id' => (string)($a['mal_id'] ?? ''),
        'data_id' => (string)($a['mal_id'] ?? ''),
        'malId' => $a['mal_id'] ?? null,
        'title' => jikan_title($a),
        'jname' => jikan_jname($a),
        'poster' => jikan_poster($a),
        'description' => $a['synopsis'] ?? '',
        'adultContent' => jikan_is_adult($a),
        'duration' => $duration,
        'releaseDate' => $releaseDate,
        'number' => null,
        'tvInfo' => [
            'showType' => $type,
            'duration' => $duration,
            'releaseDate' => $releaseDate,
            'quality' => 'HD',
            'rating' => jikan_is_adult($a) ? '18+' : '',
            'sub' => $eps,
            'dub' => null,
            'eps' => $eps,
            'episodeInfo' => [
                'sub' => $eps,
                'dub' => null,
            ],
        ],
    ];
}

/** Resolve aired episode count for ongoing titles (MAL episodes is often null). */
function jikan_episode_total(string $id): int
{
    $id = preg_replace('/[^0-9]/', '', $id);
    if ($id === '') {
        return 0;
    }

    $cacheKey = 'eptotal:v1:' . $id;
    $cached = jikan_cache_get($cacheKey, 21600); // 6h
    if (is_numeric($cached) && (int)$cached > 0) {
        return (int)$cached;
    }

    $anime = jikan_request('/anime/' . rawurlencode($id), [], JIKAN_CACHE_TTL, 1);
    $total = (int)($anime['data']['episodes'] ?? 0);
    if ($total > 0) {
        jikan_cache_set($cacheKey, $total);
        return $total;
    }

    // Prefer AniList nextAiringEpisode (accurate for long-running shows)
    require_once __DIR__ . '/anilist_client.php';
    $counts = anilist_episode_counts_by_mal([(int)$id]);
    if (!empty($counts[(int)$id])) {
        $total = (int)$counts[(int)$id];
        jikan_cache_set($cacheKey, $total);
        return $total;
    }

    // Fallback: last page of Jikan episode list
    $epPage = jikan_request('/anime/' . rawurlencode($id) . '/episodes', ['page' => 1], JIKAN_CACHE_TTL, 1);
    $lastPage = max(1, (int)($epPage['pagination']['last_visible_page'] ?? 1));
    if ($lastPage > 1) {
        $epPage = jikan_request('/anime/' . rawurlencode($id) . '/episodes', ['page' => $lastPage], JIKAN_CACHE_TTL, 1);
    }
    $max = 0;
    foreach ($epPage['data'] ?? [] as $row) {
        $max = max($max, (int)($row['mal_id'] ?? 0));
    }
    if ($max <= 0) {
        $max = count($epPage['data'] ?? []);
    }
    if ($max > 0) {
        jikan_cache_set($cacheKey, $max);
    }
    return $max;
}

function jikan_apply_episode_count(array $card, int $count): array
{
    if ($count <= 0) {
        return $card;
    }
    $card['tvInfo']['sub'] = $count;
    $card['tvInfo']['eps'] = $count;
    $card['tvInfo']['episodeInfo']['sub'] = $count;
    return $card;
}

/** Fill missing episode counts on card lists via AniList batch. */
function jikan_enrich_cards_episode_counts(array $cards): array
{
    $need = [];
    foreach ($cards as $card) {
        $sub = $card['tvInfo']['sub'] ?? $card['tvInfo']['eps'] ?? null;
        $id = (int)($card['id'] ?? $card['malId'] ?? 0);
        if ($id && ($sub === null || $sub === '' || $sub === '?' || $sub === 0 || $sub === '0')) {
            $need[] = $id;
        }
    }
    if (!$need) {
        return $cards;
    }

    require_once __DIR__ . '/anilist_client.php';
    $counts = anilist_episode_counts_by_mal($need);
    foreach ($cards as $i => $card) {
        $id = (int)($card['id'] ?? $card['malId'] ?? 0);
        if ($id && !empty($counts[$id])) {
            $cards[$i] = jikan_apply_episode_count($card, (int)$counts[$id]);
            jikan_cache_set('eptotal:v1:' . $id, (int)$counts[$id]);
        }
    }
    return $cards;
}

function jikan_map_list(?array $payload): array
{
    $items = [];
    foreach (($payload['data'] ?? []) as $row) {
        if (is_array($row)) {
            $items[] = jikan_map_card($row);
        }
    }
    return jikan_enrich_cards_episode_counts($items);
}

function jikan_pagination(?array $payload): array
{
    $pagination = $payload['pagination'] ?? [];
    $last = (int)($pagination['last_visible_page'] ?? 1);
    $current = (int)($pagination['current_page'] ?? 1);
    $hasNext = (bool)($pagination['has_next_page'] ?? false);
    $total = (int)($pagination['items']['total'] ?? ($last * 25));

    return [
        'currentPage' => $current,
        'totalPage' => $last,
        'totalPages' => $last,
        'hasNextPage' => $hasNext,
        'total' => $total,
    ];
}

function jikan_list_response(?array $payload): array
{
    if ($payload === null) {
        return jikan_fail('Upstream unavailable');
    }
    $page = jikan_pagination($payload);
    return jikan_ok(array_merge([
        'data' => jikan_map_list($payload),
    ], $page));
}

/** MAL genre name/slug → id (matches sidenav links) */
function jikan_genre_id(string $slug): ?int
{
    static $map = [
        'action' => 1,
        'adventure' => 2,
        'cars' => 3,
        'comedy' => 4,
        'dementia' => 5,
        'demons' => 6,
        'mystery' => 7,
        'drama' => 8,
        'ecchi' => 9,
        'fantasy' => 10,
        'game' => 11,
        'hentai' => 12,
        'historical' => 13,
        'horror' => 14,
        'kids' => 15,
        'magic' => 16,
        'martial-arts' => 17,
        'mecha' => 18,
        'music' => 19,
        'parody' => 20,
        'samurai' => 21,
        'romance' => 22,
        'school' => 23,
        'sci-fi' => 24,
        'shoujo' => 25,
        'shoujo-ai' => 26,
        'shounen' => 27,
        'shounen-ai' => 28,
        'space' => 29,
        'sports' => 30,
        'super-power' => 31,
        'vampire' => 32,
        'yaoi' => 33,
        'yuri' => 34,
        'harem' => 35,
        'slice-of-life' => 36,
        'supernatural' => 37,
        'military' => 38,
        'police' => 39,
        'psychological' => 40,
        'thriller' => 41,
        'seinen' => 42,
        'josei' => 43,
    ];
    $key = strtolower(str_replace(['_', ' '], '-', $slug));
    return $map[$key] ?? null;
}

function jikan_category_query(string $category, int $page = 1): array
{
    $category = strtolower($category);
    switch ($category) {
        case 'top-airing':
        case 'airing':
            return ['path' => '/top/anime', 'query' => ['filter' => 'airing', 'page' => $page]];
        case 'most-popular':
        case 'popular':
            return ['path' => '/top/anime', 'query' => ['filter' => 'bypopularity', 'page' => $page]];
        case 'most-favorite':
        case 'favorite':
        case 'favorites':
            return ['path' => '/top/anime', 'query' => ['filter' => 'favorite', 'page' => $page]];
        case 'top-upcoming':
        case 'upcoming':
            return ['path' => '/seasons/upcoming', 'query' => ['page' => $page]];
        case 'recently-added':
        case 'new-on':
            return ['path' => '/seasons/now', 'query' => ['page' => $page]];
        case 'recently-updated':
        case 'latest':
            return ['path' => '/anime', 'query' => ['status' => 'airing', 'order_by' => 'start_date', 'sort' => 'desc', 'page' => $page]];
        case 'completed':
        case 'latest-completed':
            return ['path' => '/anime', 'query' => ['status' => 'complete', 'order_by' => 'end_date', 'sort' => 'desc', 'page' => $page]];
        case 'movie':
        case 'movies':
            return ['path' => '/anime', 'query' => ['type' => 'movie', 'order_by' => 'score', 'sort' => 'desc', 'page' => $page]];
        case 'tv':
            return ['path' => '/anime', 'query' => ['type' => 'tv', 'order_by' => 'score', 'sort' => 'desc', 'page' => $page]];
        case 'ova':
            return ['path' => '/anime', 'query' => ['type' => 'ova', 'order_by' => 'score', 'sort' => 'desc', 'page' => $page]];
        case 'ona':
            return ['path' => '/anime', 'query' => ['type' => 'ona', 'order_by' => 'score', 'sort' => 'desc', 'page' => $page]];
        case 'special':
            return ['path' => '/anime', 'query' => ['type' => 'special', 'order_by' => 'score', 'sort' => 'desc', 'page' => $page]];
        default:
            return ['path' => '/top/anime', 'query' => ['page' => $page]];
    }
}

function jikan_home_complete(array $payload): bool
{
    $r = $payload['results'] ?? [];
    foreach (['spotlights', 'trending', 'topAiring', 'mostPopular', 'mostFavorite', 'latestCompleted'] as $key) {
        if (empty($r[$key]) || !is_array($r[$key])) {
            return false;
        }
    }
    return !empty($payload['success']);
}

function jikan_home(): array
{
    $cacheKey = 'home:composite:v3';
    $cached = jikan_cache_get($cacheKey, JIKAN_HOME_TTL);
    if (is_array($cached) && jikan_home_complete($cached)) {
        return $cached;
    }

    $topList = jikan_map_list(jikan_request('/top/anime', ['limit' => 25]));
    $airingList = jikan_map_list(jikan_request('/top/anime', ['filter' => 'airing', 'limit' => 25]));
    $popularList = jikan_map_list(jikan_request('/top/anime', ['filter' => 'bypopularity', 'limit' => 25]));
    $favoriteList = jikan_map_list(jikan_request('/top/anime', ['filter' => 'favorite', 'limit' => 25]));
    $completedList = jikan_map_list(jikan_request('/anime', [
        'status' => 'complete',
        'order_by' => 'score',
        'sort' => 'desc',
        'limit' => 25,
    ]));

    // Fallbacks when MAL/Jikan drops individual endpoints
    if (!$airingList) {
        $airingList = jikan_map_list(jikan_request('/seasons/now', ['limit' => 25])) ?: $topList;
    }
    if (!$popularList) {
        $popularList = $topList;
    }
    if (!$favoriteList) {
        $favoriteList = $popularList ?: $topList;
    }
    if (!$completedList) {
        $completedList = jikan_map_list(jikan_request('/anime', [
            'status' => 'complete',
            'order_by' => 'end_date',
            'sort' => 'desc',
            'limit' => 25,
        ])) ?: array_slice($topList, 0, 12);
    }
    if (!$topList) {
        $topList = $popularList ?: $airingList;
    }

    $spotlights = array_slice($topList ?: $airingList, 0, 8);
    $trending = [];
    foreach (array_slice($popularList ?: $topList, 0, 10) as $i => $item) {
        $item['number'] = $i + 1;
        $trending[] = $item;
    }

    $payload = jikan_ok([
        'spotlights' => $spotlights,
        'trending' => $trending,
        'topAiring' => $airingList,
        'mostPopular' => $popularList,
        'mostFavorite' => $favoriteList,
        'latestCompleted' => $completedList,
    ]);

    // Only cache complete payloads so empty sections get retried
    if (jikan_home_complete($payload)) {
        jikan_cache_set($cacheKey, $payload);
    }

    return $payload;
}

function jikan_top_ten(): array
{
    require_once __DIR__ . '/anilist_client.php';

    $cacheKey = 'mapped:top-ten:v2';
    $cached = jikan_cache_get($cacheKey, JIKAN_HOME_TTL);
    if (is_array($cached) && !empty($cached['success']) && !empty($cached['results']['today'])) {
        return $cached;
    }

    $top = jikan_map_list(jikan_request('/top/anime', ['limit' => 10], JIKAN_CACHE_TTL, 1));
    $airing = jikan_map_list(jikan_request('/top/anime', ['filter' => 'airing', 'limit' => 10], JIKAN_CACHE_TTL, 1));
    $popular = jikan_map_list(jikan_request('/top/anime', ['filter' => 'bypopularity', 'limit' => 10], JIKAN_CACHE_TTL, 1));

    if (!$top || !$airing || !$popular) {
        $al = anilist_top_ten();
        if (!empty($al['results']['today'])) {
            jikan_cache_set($cacheKey, $al);
            return $al;
        }
        $home = jikan_cache_get_stale('home:composite:v3') ?: jikan_cache_get_stale('home:composite:v2');
        $top = $top ?: array_slice($home['results']['spotlights'] ?? [], 0, 10);
        $airing = $airing ?: array_slice($home['results']['topAiring'] ?? [], 0, 10);
        $popular = $popular ?: array_slice($home['results']['mostPopular'] ?? [], 0, 10);
    }

    $number = function (array $list): array {
        foreach ($list as $i => &$item) {
            $item['number'] = $i + 1;
        }
        unset($item);
        return $list;
    };

    $payload = jikan_ok([
        'today' => $number($airing ?: $top),
        'week' => $number($top),
        'month' => $number($popular ?: $top),
    ]);
    if (!empty($payload['results']['today'])) {
        jikan_cache_set($cacheKey, $payload);
    }
    return $payload;
}

function jikan_info(string $id): array
{
    $cacheKey = 'mapped:info:v4:' . $id;
    $cached = jikan_cache_get($cacheKey, JIKAN_CACHE_TTL);
    if (is_array($cached) && !empty($cached['success'])) {
        return $cached;
    }

    $anime = jikan_request('/anime/' . rawurlencode($id) . '/full', [], JIKAN_CACHE_TTL, 1);
    if (!$anime || empty($anime['data'])) {
        $stale = jikan_cache_get_stale($cacheKey);
        if (is_array($stale) && !empty($stale['success'])) {
            return $stale;
        }
        require_once __DIR__ . '/anilist_client.php';
        $alPayload = anilist_info_by_mal((int)$id);
        if (!empty($alPayload['success'])) {
            jikan_cache_set($cacheKey, $alPayload);
            return $alPayload;
        }
        return jikan_fail('Anime not found');
    }
    $a = $anime['data'];
    $card = jikan_map_card($a);
    $epTotal = jikan_episode_total($id);
    if ($epTotal > 0) {
        $card = jikan_apply_episode_count($card, $epTotal);
        // Keep animeInfo tvInfo in sync
        $a['episodes'] = $epTotal;
    }

    // Optional extras — never block the details page if these fail
    $charactersVoiceActors = [];
    $charsPayload = jikan_request('/anime/' . rawurlencode($id) . '/characters', [], JIKAN_CACHE_TTL, 1);
    foreach (array_slice($charsPayload['data'] ?? [], 0, 24) as $row) {
        $ch = $row['character'] ?? [];
        $vas = [];
        foreach ($row['voice_actors'] ?? [] as $va) {
            if (($va['language'] ?? '') !== 'Japanese') {
                continue;
            }
            $person = $va['person'] ?? [];
            $vas[] = [
                'id' => (string)($person['mal_id'] ?? ''),
                'name' => $person['name'] ?? 'Unknown',
                'poster' => $person['images']['jpg']['image_url'] ?? '',
                'language' => $va['language'] ?? 'Japanese',
            ];
        }
        $charactersVoiceActors[] = [
            'character' => [
                'id' => (string)($ch['mal_id'] ?? ''),
                'name' => $ch['name'] ?? 'Unknown',
                'poster' => $ch['images']['jpg']['image_url'] ?? '',
                'cast' => $row['role'] ?? null,
            ],
            'voiceActors' => $vas,
        ];
    }

    $recommended = [];
    $recommendations = jikan_request('/anime/' . rawurlencode($id) . '/recommendations', [], JIKAN_CACHE_TTL, 1);
    foreach (array_slice($recommendations['data'] ?? [], 0, 12) as $row) {
        $entry = $row['entry'] ?? [];
        if (!$entry) {
            continue;
        }
        $recommended[] = jikan_map_card([
            'mal_id' => $entry['mal_id'] ?? null,
            'title' => $entry['title'] ?? '',
            'title_english' => $entry['title'] ?? '',
            'images' => $entry['images'] ?? [],
            'type' => 'TV',
            'episodes' => null,
            'duration' => '',
        ]);
    }

    $relations = [];
    foreach ($a['relations'] ?? [] as $rel) {
        foreach ($rel['entry'] ?? [] as $entry) {
            if (($entry['type'] ?? '') !== 'anime') {
                continue;
            }
            $relations[] = jikan_map_card([
                'mal_id' => $entry['mal_id'] ?? null,
                'title' => $entry['name'] ?? '',
                'title_english' => $entry['name'] ?? '',
                'images' => [],
                'type' => 'TV',
            ]);
        }
    }

    // AniList mappings for recommendations + anilistId (used by watchlist/watch UI)
    $anilistId = null;
    require_once __DIR__ . '/anilist_client.php';
    $al = anilist_recommendations_by_mal((int)$id);
    $anilistId = $al['anilistId'] ?? null;
    if (!$recommended && !empty($al['recommended'])) {
        $recommended = $al['recommended'];
    }
    if (!$relations && !empty($al['related'])) {
        $relations = $al['related'];
    }
    // Final fallback so "Recommended for you" is never empty when we have related/home data
    if (!$recommended && $relations) {
        $recommended = $relations;
    }
    if (!$recommended) {
        $home = jikan_cache_get_stale('home:composite:v3') ?: jikan_cache_get_stale('home:composite:v2');
        $recommended = array_slice($home['results']['mostPopular'] ?? $home['results']['topAiring'] ?? [], 0, 12);
    }
    if (!$charactersVoiceActors) {
        $charactersVoiceActors = anilist_characters_for_mal((int)$id);
    }

    $genres = [];
    foreach ($a['genres'] ?? [] as $g) {
        $genres[] = $g['name'] ?? '';
    }
    $studios = [];
    foreach ($a['studios'] ?? [] as $s) {
        $studios[] = $s['name'] ?? '';
    }
    $producers = [];
    foreach ($a['producers'] ?? [] as $p) {
        $producers[] = $p['name'] ?? '';
    }

    $seasonName = trim(($a['season'] ?? '') . ' ' . ($a['year'] ?? ''));

    $payload = jikan_ok([
        'data' => [
            'id' => $card['id'],
            'data_id' => $card['id'],
            'malId' => $a['mal_id'] ?? null,
            'anilistId' => $anilistId,
            'title' => $card['title'],
            'jname' => $card['jname'],
            'poster' => $card['poster'],
            'synonyms' => implode(', ', $a['title_synonyms'] ?? []),
            'adultContent' => $card['adultContent'],
            'animeInfo' => [
                'Overview' => $a['synopsis'] ?? 'No description',
                'Aired' => $a['aired']['string'] ?? '',
                'Premiered' => $seasonName ?: ($a['aired']['string'] ?? ''),
                'MAL Score' => $a['score'] ?? 'N/A',
                'Status' => $a['status'] ?? '',
                'Genres' => $genres,
                'Studios' => $studios[0] ?? null,
                'Producers' => $producers,
                'Duration' => $a['duration'] ?? '',
                'tvInfo' => $card['tvInfo'],
            ],
            'charactersVoiceActors' => $charactersVoiceActors,
            'recommended_data' => $recommended,
            'related_data' => $relations,
        ],
        'seasons' => [[
            'id' => $card['id'],
            'season' => $seasonName ?: 'Series',
            'title' => $card['title'],
            'season_poster' => $card['poster'],
        ]],
    ]);

    jikan_cache_set($cacheKey, $payload);
    return $payload;
}

function jikan_episodes(string $id): array
{
    $cacheKey = 'mapped:episodes:v2:' . $id;
    $cached = jikan_cache_get($cacheKey, JIKAN_CACHE_TTL);
    if (is_array($cached) && !empty($cached['success'])) {
        return $cached;
    }

    $total = jikan_episode_total($id);
    $anime = jikan_request('/anime/' . rawurlencode($id), [], JIKAN_CACHE_TTL, 1);
    $title = !empty($anime['data']) ? jikan_title($anime['data']) : 'Episode';
    if ($total <= 0) {
        $total = (int)($anime['data']['episodes'] ?? 0);
    }

    $episodes = [];
    $named = [];
    // Fetch first page of titles only (rate-limit friendly)
    $epPage = jikan_request('/anime/' . rawurlencode($id) . '/episodes', ['page' => 1], JIKAN_CACHE_TTL, 1);
    foreach ($epPage['data'] ?? [] as $i => $row) {
        $no = (int)($row['mal_id'] ?? ($i + 1));
        $named[$no] = [
            'episode_no' => $no,
            'id' => $id . '?ep=' . $no,
            'filler' => false,
            'jname' => $row['title_japanese'] ?? ($row['title'] ?? ("Episode $no")),
            'title' => $row['title'] ?? ("Episode $no"),
        ];
        $total = max($total, $no);
    }

    if ($total <= 0) {
        $total = max(count($named), 12);
    }

    // Cap extreme stubs (safety) but allow long-running shows like One Piece
    $total = min($total, 2500);
    for ($i = 1; $i <= $total; $i++) {
        $episodes[] = $named[$i] ?? [
            'episode_no' => $i,
            'id' => $id . '?ep=' . $i,
            'filler' => false,
            'jname' => "$title Episode $i",
            'title' => "Episode $i",
        ];
    }

    $payload = jikan_ok(['episodes' => $episodes]);
    jikan_cache_set($cacheKey, $payload);
    return $payload;
}

function jikan_character_list(string $animeId): array
{
    require_once __DIR__ . '/anilist_client.php';

    $cacheKey = 'mapped:characters:' . $animeId;
    $cached = jikan_cache_get($cacheKey, JIKAN_CACHE_TTL);
    if (is_array($cached) && !empty($cached['success'])) {
        return $cached;
    }

    $charsPayload = jikan_request('/anime/' . rawurlencode($animeId) . '/characters', [], JIKAN_CACHE_TTL, 1);
    $data = [];
    foreach ($charsPayload['data'] ?? [] as $row) {
        $ch = $row['character'] ?? [];
        $vas = [];
        foreach ($row['voice_actors'] ?? [] as $va) {
            $person = $va['person'] ?? [];
            $vas[] = [
                'id' => (string)($person['mal_id'] ?? ''),
                'name' => $person['name'] ?? 'Unknown',
                'poster' => $person['images']['jpg']['image_url'] ?? '',
                'language' => $va['language'] ?? 'Japanese',
            ];
        }
        $data[] = [
            'character' => [
                'id' => (string)($ch['mal_id'] ?? ''),
                'name' => $ch['name'] ?? 'Unknown',
                'poster' => $ch['images']['jpg']['image_url'] ?? '',
                'cast' => $row['role'] ?? null,
            ],
            'voiceActors' => $vas,
        ];
    }

    if (!$data) {
        $data = anilist_characters_for_mal((int)$animeId);
    }

    $payload = jikan_ok(['data' => $data]);
    if ($data) {
        jikan_cache_set($cacheKey, $payload);
    }
    return $payload;
}

function jikan_character(string $id): array
{
    require_once __DIR__ . '/anilist_client.php';

    $cacheKey = 'mapped:character:' . $id;
    $cached = jikan_cache_get($cacheKey, JIKAN_CACHE_TTL);
    if (is_array($cached) && !empty($cached['success'])) {
        return $cached;
    }

    $payload = jikan_request('/characters/' . rawurlencode($id) . '/full', [], JIKAN_CACHE_TTL, 1);
    if (!$payload || empty($payload['data'])) {
        $al = anilist_character_by_id((int)$id);
        if (!empty($al['success'])) {
            jikan_cache_set($cacheKey, $al);
            return $al;
        }
        return jikan_fail('Character not found');
    }
    $c = $payload['data'];
    $vas = [];
    foreach ($c['voices'] ?? [] as $va) {
        $person = $va['person'] ?? [];
        $vas[] = [
            'id' => (string)($person['mal_id'] ?? ''),
            'profile' => $person['images']['jpg']['image_url'] ?? '',
            'name' => $person['name'] ?? '',
            'language' => $va['language'] ?? '',
        ];
    }
    $animeography = [];
    foreach ($c['anime'] ?? [] as $row) {
        $anime = $row['anime'] ?? [];
        $animeography[] = [
            'id' => (string)($anime['mal_id'] ?? ''),
            'poster' => $anime['images']['jpg']['image_url'] ?? '',
            'title' => $anime['title'] ?? '',
            'role' => $row['role'] ?? '',
            'type' => 'Anime',
        ];
    }
    $about = (string)($c['about'] ?? '');
    $result = jikan_ok([
        'data' => [[
            'name' => $c['name'] ?? '',
            'profile' => $c['images']['jpg']['image_url'] ?? '',
            'japaneseName' => $c['name_kanji'] ?? '',
            'about' => [
                'style' => nl2br(htmlspecialchars($about)),
                'description' => $about,
            ],
            'voiceActors' => $vas,
            'animeography' => $animeography,
        ]],
    ]);
    jikan_cache_set($cacheKey, $result);
    return $result;
}

function jikan_actor(string $id): array
{
    require_once __DIR__ . '/anilist_client.php';

    $cacheKey = 'mapped:actor:' . $id;
    $cached = jikan_cache_get($cacheKey, JIKAN_CACHE_TTL);
    if (is_array($cached) && !empty($cached['success'])) {
        return $cached;
    }

    $payload = jikan_request('/people/' . rawurlencode($id) . '/full', [], JIKAN_CACHE_TTL, 1);
    if (!$payload || empty($payload['data'])) {
        $al = anilist_staff_by_id((int)$id);
        if (!empty($al['success'])) {
            jikan_cache_set($cacheKey, $al);
            return $al;
        }
        return jikan_fail('Actor not found');
    }
    $p = $payload['data'];
    $roles = [];
    foreach ($p['voices'] ?? [] as $row) {
        $anime = $row['anime'] ?? [];
        $character = $row['character'] ?? [];
        $roles[] = [
            'anime' => [
                'id' => (string)($anime['mal_id'] ?? ''),
                'poster' => $anime['images']['jpg']['image_url'] ?? '',
                'title' => $anime['title'] ?? '',
                'type' => 'Anime',
                'year' => null,
            ],
            'character' => [
                'id' => (string)($character['mal_id'] ?? ''),
                'profile' => $character['images']['jpg']['image_url'] ?? '',
                'name' => $character['name'] ?? '',
                'role' => $row['role'] ?? '',
            ],
        ];
    }
    $about = (string)($p['about'] ?? '');
    $result = jikan_ok([
        'data' => [[
            'name' => $p['name'] ?? '',
            'profile' => $p['images']['jpg']['image_url'] ?? '',
            'japaneseName' => $p['family_name'] ?? '',
            'about' => ['style' => nl2br(htmlspecialchars($about)), 'description' => $about],
            'roles' => $roles,
        ]],
    ]);
    jikan_cache_set($cacheKey, $result);
    return $result;
}

function jikan_schedule_from_home_cache(): array
{
    $home = jikan_cache_get('home:composite:v3', JIKAN_HOME_TTL * 48)
        ?: jikan_cache_get('home:composite:v2', JIKAN_HOME_TTL * 48);
    $cards = $home['results']['topAiring']
        ?? $home['results']['mostPopular']
        ?? $home['results']['spotlights']
        ?? [];
    $results = [];
    foreach (array_slice($cards, 0, 15) as $card) {
        if (!is_array($card)) {
            continue;
        }
        $results[] = [
            'id' => $card['id'] ?? '',
            'time' => $card['tvInfo']['releaseDate'] ?? 'TBA',
            'jname' => $card['jname'] ?? ($card['title'] ?? ''),
            'title' => $card['title'] ?? 'Unknown',
            'episode_no' => $card['tvInfo']['eps'] ?? ($card['tvInfo']['sub'] ?? '?'),
        ];
    }
    return $results;
}

function jikan_schedule(?string $date, $tzOffset = 0): array
{
    $ts = $date ? strtotime($date) : time();
    if ($ts === false) {
        $ts = time();
    }
    $day = strtolower(date('l', $ts));

    // One quick attempt — schedule UI should not stall on MAL outages
    $payload = jikan_request('/schedules', [
        'filter' => $day,
        'sfw' => 'true',
        'limit' => 25,
    ], JIKAN_CACHE_TTL, 1);

    $rows = $payload['data'] ?? null;
    if (!is_array($rows) || !$rows) {
        $season = jikan_request('/seasons/now', ['sfw' => 'true', 'limit' => 25], JIKAN_CACHE_TTL, 1);
        $rows = [];
        foreach ($season['data'] ?? [] as $a) {
            $bcastDay = strtolower((string)($a['broadcast']['day'] ?? ''));
            if ($bcastDay === '' || strpos($bcastDay, $day) !== false) {
                $rows[] = $a;
            }
        }
        if (!$rows) {
            $rows = $season['data'] ?? [];
        }
    }

    if (!is_array($rows) || !$rows) {
        return jikan_ok(jikan_schedule_from_home_cache());
    }

    $results = [];
    foreach ($rows as $a) {
        if (!is_array($a)) {
            continue;
        }
        $card = jikan_map_card($a);
        $broadcast = $a['broadcast']['time'] ?? ($a['broadcast']['string'] ?? 'TBA');
        if (is_string($broadcast) && preg_match('/^\d{1,2}:\d{2}/', $broadcast)) {
            $broadcast = substr($broadcast, 0, 5);
        }
        $results[] = [
            'id' => $card['id'],
            'time' => $broadcast ?: 'TBA',
            'jname' => $card['jname'],
            'title' => $card['title'],
            'episode_no' => $card['tvInfo']['eps'] ?? '?',
        ];
    }

    return jikan_ok($results);
}

function jikan_anime_schedule(string $id): array
{
    $anime = jikan_request('/anime/' . rawurlencode($id) . '/full');
    $next = $anime['data']['broadcast']['string'] ?? null;
    return jikan_ok(['nextEpisodeSchedule' => $next]);
}

function jikan_search(string $keyword, int $page = 1): array
{
    require_once __DIR__ . '/anilist_client.php';
    $list = jikan_list_response(jikan_request('/anime', [
        'q' => $keyword,
        'page' => $page,
        'sfw' => 'true',
    ], JIKAN_CACHE_TTL, 1));
    if (!empty($list['results']['data'])) {
        return $list;
    }
    return anilist_search($keyword, $page);
}

function jikan_filter(array $params): array
{
    $page = max(1, (int)($params['page'] ?? 1));
    $query = [
        'page' => $page,
        'sfw' => 'true',
        'limit' => 24,
    ];

    if (!empty($params['keyword']) || !empty($params['q'])) {
        $query['q'] = $params['keyword'] ?? $params['q'];
    }
    if (!empty($params['type']) && $params['type'] !== 'all' && $params['type'] !== 'default') {
        $query['type'] = strtolower($params['type']);
    }
    if (!empty($params['status']) && $params['status'] !== 'all' && $params['status'] !== 'default') {
        $status = strtolower($params['status']);
        $map = [
            'finished' => 'complete',
            'completed' => 'complete',
            'currently-airing' => 'airing',
            'airing' => 'airing',
            'not-yet-aired' => 'upcoming',
            'upcoming' => 'upcoming',
        ];
        $query['status'] = $map[$status] ?? $status;
    }
    if (!empty($params['score'])) {
        $query['min_score'] = $params['score'];
    }
    if (!empty($params['genres'])) {
        $ids = [];
        foreach (explode(',', (string)$params['genres']) as $g) {
            $g = trim($g);
            if (ctype_digit($g)) {
                $ids[] = $g;
            } else {
                $gid = jikan_genre_id($g);
                if ($gid) {
                    $ids[] = $gid;
                }
            }
        }
        if ($ids) {
            $query['genres'] = implode(',', $ids);
        }
    }
    if (!empty($params['genre'])) {
        $gid = ctype_digit((string)$params['genre']) ? $params['genre'] : jikan_genre_id($params['genre']);
        if ($gid) {
            $query['genres'] = $gid;
        }
    }

    $sort = strtolower((string)($params['sort'] ?? 'default'));
    if ($sort === 'score' || $sort === 'default') {
        $query['order_by'] = 'score';
        $query['sort'] = 'desc';
    } elseif ($sort === 'title') {
        $query['order_by'] = 'title';
        $query['sort'] = 'asc';
    } elseif ($sort === 'date' || $sort === 'release-date') {
        $query['order_by'] = 'start_date';
        $query['sort'] = 'desc';
    }

    return jikan_list_response(jikan_request('/anime', $query));
}

function jikan_az(?string $letter, int $page = 1): array
{
    require_once __DIR__ . '/anilist_client.php';
    $query = ['page' => $page, 'order_by' => 'title', 'sort' => 'asc', 'sfw' => 'true'];
    if ($letter && strtolower($letter) !== 'all' && $letter !== '0-9') {
        $query['letter'] = strtoupper(substr($letter, 0, 1));
    }
    $list = jikan_list_response(jikan_request('/anime', $query, JIKAN_CACHE_TTL, 1));
    if (!empty($list['results']['data'])) {
        return $list;
    }
    return anilist_az($letter, $page);
}

function jikan_genre(string $slug, int $page = 1): array
{
    require_once __DIR__ . '/anilist_client.php';
    $gid = jikan_genre_id($slug);
    if (!$gid && ctype_digit($slug)) {
        $gid = (int)$slug;
    }
    if ($gid) {
        $list = jikan_list_response(jikan_request('/anime', [
            'genres' => $gid,
            'page' => $page,
            'order_by' => 'score',
            'sort' => 'desc',
            'sfw' => 'true',
        ], JIKAN_CACHE_TTL, 1));
        if (!empty($list['results']['data'])) {
            return $list;
        }
    }
    return anilist_genre($slug, $page);
}

function jikan_producer(string $slug, int $page = 1): array
{
    require_once __DIR__ . '/anilist_client.php';
    // Producers are numeric in MAL; fall back to title search on slug
    if (ctype_digit($slug)) {
        $list = jikan_list_response(jikan_request('/anime', [
            'producers' => $slug,
            'page' => $page,
            'sfw' => 'true',
        ], JIKAN_CACHE_TTL, 1));
        if (!empty($list['results']['data'])) {
            return $list;
        }
    }
    $name = str_replace('-', ' ', $slug);
    $list = jikan_list_response(jikan_request('/anime', [
        'q' => $name,
        'page' => $page,
        'sfw' => 'true',
    ], JIKAN_CACHE_TTL, 1));
    if (!empty($list['results']['data'])) {
        return $list;
    }
    return anilist_search($name, $page);
}

function jikan_random(): array
{
    $payload = jikan_request('/random/anime', [], 60);
    if (!$payload || empty($payload['data'])) {
        return jikan_fail('Random anime unavailable');
    }
    $card = jikan_map_card($payload['data']);
    return jikan_ok(['id' => $card['id']]);
}
