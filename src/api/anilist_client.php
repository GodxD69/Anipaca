<?php
/**
 * AniList GraphQL client — fallback when Jikan/MAL is down.
 * Cards use MAL id when available so /details/{malId} keeps working.
 */

define('ANILIST_URL', 'https://graphql.anilist.co');
define('ANILIST_CACHE_TTL', 3600);
define('ANILIST_TIMEOUT', 15);

function anilist_cache_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;

    $base = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'anilist';
    if (!is_dir($base)) {
        @mkdir($base, 0777, true);
    }
    if (!is_dir($base) || !is_writable($base)) {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'anipaca_cache' . DIRECTORY_SEPARATOR . 'anilist';
        if (!is_dir($base)) {
            @mkdir($base, 0777, true);
        }
    }
    $dir = $base;
    return $dir;
}

function anilist_cache_get(string $key, int $ttl = ANILIST_CACHE_TTL)
{
    $file = anilist_cache_dir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
    if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }
    return null;
}

function anilist_cache_get_stale(string $key)
{
    $file = anilist_cache_dir() . DIRECTORY_SEPARATOR . md5($key) . '.json';
    if (!file_exists($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function anilist_cache_set(string $key, $data): void
{
    $dir = anilist_cache_dir();
    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($dir . DIRECTORY_SEPARATOR . md5($key) . '.json', json_encode($data));
    }
}

function anilist_graphql(string $query, array $variables = []): ?array
{
    $cacheKey = 'gql:' . md5($query . json_encode($variables));
    $fresh = anilist_cache_get($cacheKey);
    if ($fresh !== null) {
        return $fresh;
    }

    $payload = json_encode(['query' => $query, 'variables' => (object)$variables]);
    $ch = curl_init(ANILIST_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: AniPaca-AniListAdapter/1.0',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => ANILIST_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        return anilist_cache_get_stale($cacheKey);
    }

    $data = json_decode($body, true);
    if (!is_array($data) || !empty($data['errors'])) {
        return anilist_cache_get_stale($cacheKey);
    }

    anilist_cache_set($cacheKey, $data);
    return $data;
}

function anilist_genre_name(string $slug): ?string
{
    static $map = [
        'action' => 'Action',
        'adventure' => 'Adventure',
        'comedy' => 'Comedy',
        'drama' => 'Drama',
        'ecchi' => 'Ecchi',
        'fantasy' => 'Fantasy',
        'horror' => 'Horror',
        'mahou-shoujo' => 'Mahou Shoujo',
        'mecha' => 'Mecha',
        'music' => 'Music',
        'mystery' => 'Mystery',
        'psychological' => 'Psychological',
        'romance' => 'Romance',
        'sci-fi' => 'Sci-Fi',
        'slice-of-life' => 'Slice of Life',
        'sports' => 'Sports',
        'supernatural' => 'Supernatural',
        'thriller' => 'Thriller',
        'hentai' => 'Hentai',
        // Approximate mappings for older HiAnime genre slugs
        'cars' => 'Sports',
        'dementia' => 'Psychological',
        'demons' => 'Supernatural',
        'game' => 'Action',
        'harem' => 'Romance',
        'historical' => 'Drama',
        'josei' => 'Drama',
        'kids' => 'Adventure',
        'magic' => 'Fantasy',
        'martial-arts' => 'Action',
        'military' => 'Action',
        'parody' => 'Comedy',
        'police' => 'Mystery',
        'samurai' => 'Action',
        'school' => 'Slice of Life',
        'seinen' => 'Drama',
        'shoujo' => 'Romance',
        'shoujo-ai' => 'Romance',
        'shounen' => 'Action',
        'shounen-ai' => 'Romance',
        'space' => 'Sci-Fi',
        'super-power' => 'Action',
        'vampire' => 'Supernatural',
        'yaoi' => 'Romance',
        'yuri' => 'Romance',
    ];
    $key = strtolower(str_replace(['_', ' '], '-', $slug));
    return $map[$key] ?? null;
}

function anilist_format_to_type(?string $format): string
{
    $map = [
        'TV' => 'TV',
        'TV_SHORT' => 'TV',
        'MOVIE' => 'Movie',
        'SPECIAL' => 'Special',
        'OVA' => 'OVA',
        'ONA' => 'ONA',
        'MUSIC' => 'Music',
    ];
    return $map[$format ?? ''] ?? ($format ?: 'TV');
}

function anilist_resolve_episode_count(?int $episodes, ?array $nextAiring): int|string
{
    if (!empty($episodes) && (int)$episodes > 0) {
        return (int)$episodes;
    }
    $next = (int)($nextAiring['episode'] ?? 0);
    if ($next > 1) {
        return $next - 1; // last aired episode
    }
    if ($next === 1) {
        return '?';
    }
    return $episodes === 0 ? 0 : '?';
}

function anilist_map_card(array $m): ?array
{
    $malId = $m['idMal'] ?? null;
    if (!$malId) {
        // Keep /details/{id} on MAL ids; skip entries AniList can't map
        return null;
    }

    $title = $m['title']['english'] ?? $m['title']['romaji'] ?? 'Unknown';
    $jname = $m['title']['native'] ?? $m['title']['romaji'] ?? $title;
    $eps = anilist_resolve_episode_count(
        isset($m['episodes']) ? (int)$m['episodes'] : null,
        $m['nextAiringEpisode'] ?? null
    );
    $duration = !empty($m['duration']) ? ($m['duration'] . 'm') : '';
    $type = anilist_format_to_type($m['format'] ?? null);
    $poster = $m['coverImage']['large'] ?? ($m['coverImage']['medium'] ?? '');
    $adult = !empty($m['isAdult']);
    $start = $m['startDate'] ?? [];
    $releaseDate = '';
    if (!empty($start['year'])) {
        $releaseDate = sprintf(
            '%04d-%02d-%02d',
            (int)$start['year'],
            (int)($start['month'] ?? 1),
            (int)($start['day'] ?? 1)
        );
    }

    return [
        'id' => (string)$malId,
        'data_id' => (string)$malId,
        'malId' => (int)$malId,
        'anilistId' => $m['id'] ?? null,
        'title' => $title,
        'jname' => $jname,
        'poster' => $poster,
        'description' => strip_tags($m['description'] ?? ''),
        'adultContent' => $adult,
        'duration' => $duration,
        'releaseDate' => $releaseDate,
        'number' => null,
        'tvInfo' => [
            'showType' => $type,
            'duration' => $duration,
            'releaseDate' => $releaseDate,
            'quality' => 'HD',
            'rating' => $adult ? '18+' : '',
            'sub' => $eps,
            'dub' => null,
            'eps' => $eps,
            'episodeInfo' => ['sub' => $eps, 'dub' => null],
        ],
    ];
}

/** Batch episode counts for MAL ids via AniList (ongoing uses nextAiringEpisode). */
function anilist_episode_counts_by_mal(array $malIds): array
{
    $malIds = array_values(array_unique(array_filter(array_map('intval', $malIds))));
    if (!$malIds) {
        return [];
    }

    $out = [];
    foreach (array_chunk($malIds, 50) as $chunk) {
        $cacheKey = 'epcounts:' . md5(implode(',', $chunk));
        $cached = anilist_cache_get($cacheKey, 1800);
        if (is_array($cached)) {
            foreach ($cached as $k => $v) {
                $out[$k] = $v;
            }
            continue;
        }

        $query = <<<'GQL'
query ($ids: [Int]) {
  Page(page: 1, perPage: 50) {
    media(type: ANIME, idMal_in: $ids) {
      idMal
      episodes
      nextAiringEpisode { episode }
    }
  }
}
GQL;
        $data = anilist_graphql($query, ['ids' => $chunk]);
        $partial = [];
        foreach ($data['data']['Page']['media'] ?? [] as $m) {
            $id = (int)($m['idMal'] ?? 0);
            if (!$id) {
                continue;
            }
            $count = anilist_resolve_episode_count(
                isset($m['episodes']) ? (int)$m['episodes'] : null,
                $m['nextAiringEpisode'] ?? null
            );
            if ($count !== '?' && (int)$count > 0) {
                $partial[$id] = (int)$count;
                $out[$id] = (int)$count;
            }
        }
        anilist_cache_set($cacheKey, $partial);
    }
    return $out;
}

function anilist_map_list(?array $media): array
{
    $out = [];
    foreach ($media ?? [] as $m) {
        if (!is_array($m)) {
            continue;
        }
        $card = anilist_map_card($m);
        if ($card) {
            $out[] = $card;
        }
    }
    return $out;
}

function anilist_page_response(?array $pageData): array
{
    if (!$pageData) {
        return ['success' => false, 'message' => 'AniList unavailable', 'results' => null];
    }
    $info = $pageData['pageInfo'] ?? [];
    $last = (int)($info['lastPage'] ?? 1);
    $current = (int)($info['currentPage'] ?? 1);
    $total = (int)($info['total'] ?? 0);
    return [
        'success' => true,
        'results' => [
            'data' => anilist_map_list($pageData['media'] ?? []),
            'currentPage' => $current,
            'totalPage' => $last,
            'totalPages' => $last,
            'hasNextPage' => !empty($info['hasNextPage']),
            'total' => $total,
        ],
    ];
}

function anilist_browse(array $vars, int $page = 1, int $perPage = 24): array
{
    $query = <<<'GQL'
query ($page: Int, $perPage: Int, $search: String, $genre: String, $sort: [MediaSort], $format: MediaFormat, $status: MediaStatus) {
  Page(page: $page, perPage: $perPage) {
    pageInfo { total currentPage lastPage hasNextPage }
    media(type: ANIME, search: $search, genre: $genre, sort: $sort, format: $format, status: $status, isAdult: false) {
      id
      idMal
      title { romaji english native }
      coverImage { large medium }
      episodes
      nextAiringEpisode { episode }
      duration
      format
      status
      seasonYear
      description(asHtml: false)
      isAdult
      startDate { year month day }
    }
  }
}
GQL;

    $variables = array_merge([
        'page' => $page,
        'perPage' => $perPage,
        'sort' => ['POPULARITY_DESC'],
    ], $vars);

    // Remove nulls so GraphQL doesn't choke
    $variables = array_filter($variables, static fn($v) => $v !== null && $v !== '');

    $data = anilist_graphql($query, $variables);
    return anilist_page_response($data['data']['Page'] ?? null);
}

function anilist_genre(string $slug, int $page = 1): array
{
    $name = anilist_genre_name($slug);
    if (!$name) {
        return anilist_browse(['search' => str_replace('-', ' ', $slug), 'sort' => ['POPULARITY_DESC']], $page);
    }
    return anilist_browse(['genre' => $name, 'sort' => ['POPULARITY_DESC']], $page);
}

function anilist_az(?string $letter, int $page = 1): array
{
    $rawLetter = trim((string)$letter);
    $upper = strtoupper($rawLetter);

    if ($rawLetter === '' || strcasecmp($rawLetter, 'all') === 0 || strcasecmp($rawLetter, 'az-list') === 0) {
        return anilist_browse(['sort' => ['POPULARITY_DESC']], $page);
    }
    if ($rawLetter === '0-9' || $rawLetter === '#' || ctype_digit($rawLetter)) {
        return anilist_browse(['search' => '2', 'sort' => ['POPULARITY_DESC']], $page);
    }

    $letterChar = strtoupper(substr($upper, 0, 1));
    $raw = anilist_browse(['search' => $letterChar, 'sort' => ['POPULARITY_DESC']], $page, 50);
    if (empty($raw['success']) || empty($raw['results']['data'])) {
        return anilist_browse(['sort' => ['POPULARITY_DESC']], $page);
    }

    $filtered = [];
    foreach ($raw['results']['data'] as $card) {
        $t = ltrim((string)($card['title'] ?? ''));
        $j = ltrim((string)($card['jname'] ?? ''));
        $start = strtoupper(substr($t !== '' ? $t : $j, 0, 1));
        if ($start === $letterChar) {
            $filtered[] = $card;
        }
    }
    $raw['results']['data'] = array_slice($filtered ?: $raw['results']['data'], 0, 24);
    return $raw;
}

function anilist_category(string $category, int $page = 1): array
{
    $category = strtolower($category);
    switch ($category) {
        case 'top-airing':
        case 'airing':
        case 'recently-updated':
            return anilist_browse(['status' => 'RELEASING', 'sort' => ['POPULARITY_DESC']], $page);
        case 'recently-added':
        case 'new-on':
            return anilist_browse(['status' => 'RELEASING', 'sort' => ['START_DATE_DESC']], $page);
        case 'top-upcoming':
        case 'upcoming':
            return anilist_browse(['status' => 'NOT_YET_RELEASED', 'sort' => ['POPULARITY_DESC']], $page);
        case 'most-popular':
        case 'popular':
        case 'subbed-anime':
        case 'dubbed-anime':
            return anilist_browse(['sort' => ['POPULARITY_DESC']], $page);
        case 'most-favorite':
        case 'favorite':
        case 'favorites':
            return anilist_browse(['sort' => ['FAVOURITES_DESC']], $page);
        case 'completed':
        case 'latest-completed':
            return anilist_browse(['status' => 'FINISHED', 'sort' => ['END_DATE_DESC']], $page);
        case 'movie':
        case 'movies':
            return anilist_browse(['format' => 'MOVIE', 'sort' => ['POPULARITY_DESC']], $page);
        case 'tv':
            return anilist_browse(['format' => 'TV', 'sort' => ['POPULARITY_DESC']], $page);
        case 'ova':
            return anilist_browse(['format' => 'OVA', 'sort' => ['POPULARITY_DESC']], $page);
        case 'ona':
            return anilist_browse(['format' => 'ONA', 'sort' => ['POPULARITY_DESC']], $page);
        case 'special':
            return anilist_browse(['format' => 'SPECIAL', 'sort' => ['POPULARITY_DESC']], $page);
        default:
            return anilist_browse(['sort' => ['SCORE_DESC']], $page);
    }
}

function anilist_search(string $keyword, int $page = 1): array
{
    return anilist_browse(['search' => $keyword, 'sort' => ['SEARCH_MATCH']], $page);
}

function anilist_top_ten(): array
{
    $day = anilist_browse(['status' => 'RELEASING', 'sort' => ['TRENDING_DESC']], 1, 10);
    $week = anilist_browse(['sort' => ['POPULARITY_DESC']], 1, 10);
    $month = anilist_browse(['sort' => ['FAVOURITES_DESC']], 1, 10);

    $number = static function (array $list): array {
        foreach ($list as $i => &$item) {
            $item['number'] = $i + 1;
        }
        unset($item);
        return $list;
    };

    $today = $number($day['results']['data'] ?? []);
    $weekList = $number($week['results']['data'] ?? []);
    $monthList = $number($month['results']['data'] ?? []);

    return [
        'success' => !empty($today) || !empty($weekList),
        'results' => [
            'today' => $today ?: $weekList,
            'week' => $weekList ?: $today,
            'month' => $monthList ?: $weekList,
        ],
    ];
}

function anilist_recommendations_by_mal(int $malId): array
{
    $query = <<<'GQL'
query ($idMal: Int) {
  Media(idMal: $idMal, type: ANIME) {
    id
    idMal
    recommendations(page: 1, perPage: 16) {
      nodes {
        mediaRecommendation {
          id
          idMal
          title { romaji english native }
          coverImage { large medium }
          episodes
          nextAiringEpisode { episode }
          duration
          format
          isAdult
          startDate { year month day }
          description(asHtml: false)
        }
      }
    }
    relations {
      edges {
        relationType
        node {
          id
          idMal
          type
          title { romaji english native }
          coverImage { large medium }
          episodes
          nextAiringEpisode { episode }
          duration
          format
          isAdult
          startDate { year month day }
          description(asHtml: false)
        }
      }
    }
  }
}
GQL;

    $data = anilist_graphql($query, ['idMal' => $malId]);
    $media = $data['data']['Media'] ?? null;
    if (!$media) {
        return ['anilistId' => null, 'recommended' => [], 'related' => []];
    }

    $recommended = [];
    foreach ($media['recommendations']['nodes'] ?? [] as $node) {
        $rec = $node['mediaRecommendation'] ?? null;
        if (is_array($rec)) {
            $card = anilist_map_card($rec);
            if ($card) {
                $recommended[] = $card;
            }
        }
    }

    $related = [];
    foreach ($media['relations']['edges'] ?? [] as $edge) {
        $node = $edge['node'] ?? null;
        if (!is_array($node) || ($node['type'] ?? '') !== 'ANIME') {
            continue;
        }
        $card = anilist_map_card($node);
        if ($card) {
            $related[] = $card;
        }
    }

    return [
        'anilistId' => $media['id'] ?? null,
        'recommended' => $recommended,
        'related' => $related,
    ];
}

function anilist_characters_for_mal(int $malId): array
{
    $cacheKey = 'chars:mal:' . $malId;
    $cached = anilist_cache_get($cacheKey, ANILIST_CACHE_TTL);
    if (is_array($cached)) {
        return $cached;
    }

    $query = <<<'GQL'
query ($idMal: Int) {
  Media(idMal: $idMal, type: ANIME) {
    characters(page: 1, perPage: 25, sort: [ROLE, FAVOURITES_DESC]) {
      edges {
        role
        node {
          id
          name { full }
          image { large medium }
        }
        voiceActors(language: JAPANESE, sort: [RELEVANCE]) {
          id
          name { full }
          image { large medium }
          languageV2
        }
      }
    }
  }
}
GQL;
    $data = anilist_graphql($query, ['idMal' => $malId]);
    $edges = $data['data']['Media']['characters']['edges'] ?? [];
    $out = [];

    foreach ($edges as $edge) {
        $node = $edge['node'] ?? [];
        $charId = $node['id'] ?? null;
        if (!$charId) {
            continue;
        }

        $vas = [];
        foreach ($edge['voiceActors'] ?? [] as $va) {
            $vaId = $va['id'] ?? null;
            if (!$vaId) {
                continue;
            }
            $vas[] = [
                'id' => (string)$vaId,
                'name' => $va['name']['full'] ?? 'Unknown',
                'poster' => $va['image']['large'] ?? ($va['image']['medium'] ?? ''),
                'language' => $va['languageV2'] ?? 'Japanese',
            ];
        }

        $out[] = [
            'character' => [
                'id' => (string)$charId,
                'name' => $node['name']['full'] ?? 'Unknown',
                'poster' => $node['image']['large'] ?? ($node['image']['medium'] ?? ''),
                'cast' => $edge['role'] ?? '',
            ],
            'voiceActors' => $vas,
        ];
    }

    anilist_cache_set($cacheKey, $out);
    return $out;
}

/** Character detail page payload (AniList id). */
function anilist_character_by_id(int $id): array
{
    $cacheKey = 'char:detail:' . $id;
    $cached = anilist_cache_get($cacheKey, ANILIST_CACHE_TTL);
    if (is_array($cached) && !empty($cached['success'])) {
        return $cached;
    }

    $query = <<<'GQL'
query ($id: Int) {
  Character(id: $id) {
    id
    name { full native }
    image { large medium }
    description(asHtml: true)
    media(page: 1, perPage: 25, type: ANIME, sort: [POPULARITY_DESC]) {
      edges {
        characterRole
        node {
          id
          idMal
          title { romaji english }
          coverImage { large medium }
          format
        }
        voiceActors(language: JAPANESE, sort: [RELEVANCE]) {
          id
          name { full }
          image { large medium }
          languageV2
        }
      }
    }
  }
}
GQL;
    $data = anilist_graphql($query, ['id' => $id]);
    $c = $data['data']['Character'] ?? null;
    if (!$c) {
        return ['success' => false, 'message' => 'Character not found', 'results' => null];
    }

    $animeography = [];
    $voiceActors = [];
    $seenVa = [];
    foreach ($c['media']['edges'] ?? [] as $edge) {
        $node = $edge['node'] ?? [];
        $malId = $node['idMal'] ?? null;
        if ($malId) {
            $animeography[] = [
                'id' => (string)$malId,
                'poster' => $node['coverImage']['large'] ?? ($node['coverImage']['medium'] ?? ''),
                'title' => $node['title']['english'] ?? ($node['title']['romaji'] ?? 'Unknown'),
                'role' => $edge['characterRole'] ?? '',
                'type' => anilist_format_to_type($node['format'] ?? null),
            ];
        }
        foreach ($edge['voiceActors'] ?? [] as $va) {
            $vaId = (string)($va['id'] ?? '');
            if ($vaId === '' || isset($seenVa[$vaId])) {
                continue;
            }
            $seenVa[$vaId] = true;
            $voiceActors[] = [
                'id' => $vaId,
                'profile' => $va['image']['large'] ?? ($va['image']['medium'] ?? ''),
                'name' => $va['name']['full'] ?? 'Unknown',
                'language' => $va['languageV2'] ?? 'Japanese',
            ];
        }
    }

    $desc = (string)($c['description'] ?? '');
    $payload = [
        'success' => true,
        'results' => [
            'data' => [[
                'name' => $c['name']['full'] ?? 'Unknown',
                'profile' => $c['image']['large'] ?? ($c['image']['medium'] ?? ''),
                'japaneseName' => $c['name']['native'] ?? '',
                'about' => [
                    'style' => $desc,
                    'description' => strip_tags($desc),
                ],
                'voiceActors' => $voiceActors,
                'animeography' => $animeography,
            ]],
        ],
    ];
    anilist_cache_set($cacheKey, $payload);
    return $payload;
}

/** Voice actor / staff detail page payload (AniList id). */
function anilist_staff_by_id(int $id): array
{
    $cacheKey = 'staff:detail:' . $id;
    $cached = anilist_cache_get($cacheKey, ANILIST_CACHE_TTL);
    if (is_array($cached) && !empty($cached['success'])) {
        return $cached;
    }

    $query = <<<'GQL'
query ($id: Int) {
  Staff(id: $id) {
    id
    name { full native }
    image { large medium }
    description(asHtml: true)
    characterMedia(page: 1, perPage: 25, sort: [POPULARITY_DESC]) {
      edges {
        characterRole
        node {
          id
          idMal
          title { romaji english }
          coverImage { large medium }
          format
          startDate { year }
        }
        characters {
          id
          name { full }
          image { large medium }
        }
      }
    }
  }
}
GQL;
    $data = anilist_graphql($query, ['id' => $id]);
    $s = $data['data']['Staff'] ?? null;
    if (!$s) {
        return ['success' => false, 'message' => 'Actor not found', 'results' => null];
    }

    $roles = [];
    foreach ($s['characterMedia']['edges'] ?? [] as $edge) {
        $node = $edge['node'] ?? [];
        $malId = $node['idMal'] ?? null;
        if (!$malId) {
            continue;
        }
        $chars = $edge['characters'] ?? [];
        $ch = $chars[0] ?? null;
        $roles[] = [
            'anime' => [
                'id' => (string)$malId,
                'poster' => $node['coverImage']['large'] ?? ($node['coverImage']['medium'] ?? ''),
                'title' => $node['title']['english'] ?? ($node['title']['romaji'] ?? 'Unknown'),
                'type' => anilist_format_to_type($node['format'] ?? null),
                'year' => $node['startDate']['year'] ?? null,
            ],
            'character' => [
                'id' => (string)($ch['id'] ?? ''),
                'profile' => $ch['image']['large'] ?? ($ch['image']['medium'] ?? ''),
                'name' => $ch['name']['full'] ?? 'Unknown',
                'role' => $edge['characterRole'] ?? '',
            ],
        ];
    }

    $desc = (string)($s['description'] ?? '');
    $payload = [
        'success' => true,
        'results' => [
            'data' => [[
                'name' => $s['name']['full'] ?? 'Unknown',
                'profile' => $s['image']['large'] ?? ($s['image']['medium'] ?? ''),
                'japaneseName' => $s['name']['native'] ?? '',
                'about' => ['style' => $desc, 'description' => strip_tags($desc)],
                'roles' => $roles,
            ]],
        ],
    ];
    anilist_cache_set($cacheKey, $payload);
    return $payload;
}

function anilist_media_by_mal(int $malId): ?array
{
    $query = <<<'GQL'
query ($idMal: Int) {
  Media(idMal: $idMal, type: ANIME) {
    id
    idMal
    title { romaji english native }
    synonyms
    coverImage { large medium }
    episodes
    duration
    format
    status
    season
    seasonYear
    averageScore
    description(asHtml: false)
    genres
    isAdult
    nextAiringEpisode { episode }
    startDate { year month day }
    endDate { year month day }
    studios { nodes { name } }
  }
}
GQL;
    $data = anilist_graphql($query, ['idMal' => $malId]);
    return $data['data']['Media'] ?? null;
}

function anilist_status_label(?string $status): string
{
    $map = [
        'RELEASING' => 'Currently Airing',
        'FINISHED' => 'Finished Airing',
        'NOT_YET_RELEASED' => 'Not yet aired',
        'CANCELLED' => 'Cancelled',
        'HIATUS' => 'On Hiatus',
    ];
    return $map[$status ?? ''] ?? ($status ?: 'Unknown');
}

function anilist_date_string(?array $date): string
{
    if (empty($date['year'])) {
        return '';
    }
    return sprintf(
        '%04d-%02d-%02d',
        (int)$date['year'],
        (int)($date['month'] ?? 1),
        (int)($date['day'] ?? 1)
    );
}

/** Full /info payload when Jikan/MAL is unavailable. */
function anilist_info_by_mal(int $malId): array
{
    $cacheKey = 'mapped:anilist-info:v2:' . $malId;
    $cached = anilist_cache_get($cacheKey);
    if (is_array($cached) && !empty($cached['success'])) {
        return $cached;
    }

    $m = anilist_media_by_mal($malId);
    if (!$m) {
        return ['success' => false, 'message' => 'Anime not found', 'results' => null];
    }

    $card = anilist_map_card([
        'idMal' => $m['idMal'] ?? $malId,
        'id' => $m['id'] ?? null,
        'title' => [
            'romaji' => $m['title']['romaji'] ?? '',
            'english' => $m['title']['english'] ?? null,
            'native' => $m['title']['native'] ?? null,
        ],
        'coverImage' => $m['coverImage'] ?? [],
        'episodes' => $m['episodes'] ?? null,
        'nextAiringEpisode' => $m['nextAiringEpisode'] ?? null,
        'duration' => $m['duration'] ?? null,
        'format' => $m['format'] ?? null,
        'description' => $m['description'] ?? '',
        'isAdult' => $m['isAdult'] ?? false,
        'startDate' => $m['startDate'] ?? [],
    ]);
    if (!$card) {
        return ['success' => false, 'message' => 'Anime not found', 'results' => null];
    }

    $start = anilist_date_string($m['startDate'] ?? null);
    $end = anilist_date_string($m['endDate'] ?? null);
    $aired = trim($start . ($end ? " to $end" : ''));

    $studios = [];
    foreach ($m['studios']['nodes'] ?? [] as $s) {
        if (!empty($s['name'])) {
            $studios[] = $s['name'];
        }
    }

    $seasonName = trim(($m['season'] ?? '') . ' ' . ($m['seasonYear'] ?? ''));
    $al = anilist_recommendations_by_mal($malId);
    $recommended = $al['recommended'] ?? [];
    $relations = $al['related'] ?? [];
    $charactersVoiceActors = anilist_characters_for_mal($malId);
    if (!$recommended && $relations) {
        $recommended = $relations;
    }

    $payload = [
        'success' => true,
        'results' => [
            'data' => [
                'id' => $card['id'],
                'data_id' => $card['id'],
                'malId' => $m['idMal'] ?? $malId,
                'anilistId' => $m['id'] ?? ($al['anilistId'] ?? null),
                'title' => $card['title'],
                'jname' => $card['jname'],
                'poster' => $card['poster'],
                'synonyms' => implode(', ', $m['synonyms'] ?? []),
                'adultContent' => $card['adultContent'],
                'animeInfo' => [
                    'Overview' => strip_tags($m['description'] ?? 'No description'),
                    'Aired' => $aired,
                    'Premiered' => $seasonName ?: $start,
                    'MAL Score' => isset($m['averageScore']) ? ($m['averageScore'] / 10) : 'N/A',
                    'Status' => anilist_status_label($m['status'] ?? null),
                    'Genres' => $m['genres'] ?? [],
                    'Studios' => $studios[0] ?? null,
                    'Producers' => [],
                    'Duration' => $card['tvInfo']['duration'] ?? '',
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
        ],
    ];

    anilist_cache_set($cacheKey, $payload);
    return $payload;
}

/**
 * Super-fast unified home data query via AniList GraphQL
 * Fetches all categories in ONE single request (~200ms) with MAL ID mapping
 */
function anilist_home(): array
{
    $cacheKey = 'home:composite:v3';
    $cached = anilist_cache_get($cacheKey, 1800);
    if (is_array($cached) && !empty($cached['success']) && !empty($cached['results']['spotlights'])) {
        return $cached;
    }

    $query = <<<'GQL'
query {
  trending: Page(page: 1, perPage: 14) {
    media(type: ANIME, sort: TRENDING_DESC, isAdult: false) {
      id idMal title { english romaji native } coverImage { large medium }
      episodes nextAiringEpisode { episode } duration format description isAdult startDate { year month day }
    }
  }
  popular: Page(page: 1, perPage: 14) {
    media(type: ANIME, sort: POPULARITY_DESC, isAdult: false) {
      id idMal title { english romaji native } coverImage { large medium }
      episodes nextAiringEpisode { episode } duration format description isAdult startDate { year month day }
    }
  }
  airing: Page(page: 1, perPage: 14) {
    media(type: ANIME, status: RELEASING, sort: POPULARITY_DESC, isAdult: false) {
      id idMal title { english romaji native } coverImage { large medium }
      episodes nextAiringEpisode { episode } duration format description isAdult startDate { year month day }
    }
  }
  favorite: Page(page: 1, perPage: 14) {
    media(type: ANIME, sort: FAVOURITES_DESC, isAdult: false) {
      id idMal title { english romaji native } coverImage { large medium }
      episodes nextAiringEpisode { episode } duration format description isAdult startDate { year month day }
    }
  }
  completed: Page(page: 1, perPage: 14) {
    media(type: ANIME, status: FINISHED, sort: SCORE_DESC, isAdult: false) {
      id idMal title { english romaji native } coverImage { large medium }
      episodes nextAiringEpisode { episode } duration format description isAdult startDate { year month day }
    }
  }
}
GQL;

    $res = anilist_graphql($query);
    $data = $res['data'] ?? [];

    $mapList = function (?array $mediaList) {
        $cards = [];
        if (!is_array($mediaList)) return $cards;
        foreach ($mediaList as $m) {
            $c = anilist_map_card($m);
            if ($c !== null) {
                $cards[] = $c;
            }
        }
        return $cards;
    };

    $trendingList = $mapList($data['trending']['media'] ?? []);
    $popularList = $mapList($data['popular']['media'] ?? []);
    $airingList = $mapList($data['airing']['media'] ?? []);
    $favoriteList = $mapList($data['favorite']['media'] ?? []);
    $completedList = $mapList($data['completed']['media'] ?? []);

    if (!$trendingList && !$popularList) {
        $stale = anilist_cache_get_stale($cacheKey);
        if ($stale) return $stale;
    }

    $spotlights = array_slice($popularList ?: $trendingList, 0, 8);
    $trending = [];
    foreach (array_slice($trendingList ?: $popularList, 0, 10) as $i => $item) {
        $item['number'] = $i + 1;
        $trending[] = $item;
    }

    $payload = [
        'success' => true,
        'results' => [
            'spotlights' => $spotlights,
            'trending' => $trending,
            'topAiring' => $airingList ?: $trendingList,
            'mostPopular' => $popularList ?: $trendingList,
            'mostFavorite' => $favoriteList ?: $popularList,
            'latestCompleted' => $completedList ?: array_slice($popularList, 0, 10),
        ]
    ];

    if ($spotlights) {
        anilist_cache_set($cacheKey, $payload);
    }
    return $payload;
}

