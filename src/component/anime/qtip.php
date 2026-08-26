<?php

function fetchAnimeData($animeId) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once($_SERVER['DOCUMENT_ROOT'] . '/_config.php');
    require_once($_SERVER['DOCUMENT_ROOT'] . '/src/api/jikan_client.php');

    $animeResponse = jikan_info((string)$animeId);

    if (empty($animeResponse['success']) || !isset($animeResponse['results']['data'])) {
        return false;
    }

    $data = $animeResponse['results']['data'];
    $animeInfo = $data['animeInfo'] ?? [];
    $tvInfo = $animeInfo['tvInfo'] ?? [];
    $seasons = $animeResponse['results']['seasons'] ?? [];

    $seasonList = [];
    foreach ($seasons as $season) {
        $seasonList[] = [
            'id' => $season['id'] ?? null,
            'name' => $season['season'] ?? null,
            'title' => $season['title'] ?? null,
            'poster' => $season['season_poster'] ?? null,
            'isCurrent' => (($season['id'] ?? null) === $animeId)
        ];
    }

    $chList = [];
    foreach ($data['charactersVoiceActors'] ?? [] as $character) {
        $voiceActors = [];
        foreach ($character['voiceActors'] ?? [] as $actor) {
            $voiceActors[] = [
                'id' => $actor['id'] ?? null,
                'name' => $actor['name'] ?? 'Unknown voice actor',
                'poster' => $actor['poster'] ?? null,
                'language' => $actor['language'] ?? 'Japanese',
            ];
        }

        $chList[] = [
            'character' => [
                'id' => $character['character']['id'] ?? null,
                'name' => $character['character']['name'] ?? 'Unknown character',
                'poster' => $character['character']['poster'] ?? null,
                'cast' => $character['character']['cast'] ?? null
            ],
            'voiceActors' => $voiceActors
        ];
    }

    $recommendedAnimeList = [];
    foreach ($data['recommended_data'] ?? [] as $recommendedAnime) {
        $recommendedAnimeList[] = [
            'id' => $recommendedAnime['id'] ?? null,
            'data_id' => $recommendedAnime['data_id'] ?? null,
            'name' => $recommendedAnime['title'] ?? $recommendedAnime['jname'] ?? null,
            'japanese' => $recommendedAnime['jname'] ?? null,
            'poster' => $recommendedAnime['poster'] ?? null,
            'duration' => $recommendedAnime['tvInfo']['duration'] ?? null,
            'type' => $recommendedAnime['tvInfo']['showType'] ?? null,
            'adultContent' => $recommendedAnime['adultContent'] ?? false,
            'episodes' => [
                'sub' => $recommendedAnime['tvInfo']['sub'] ?? $recommendedAnime['tvInfo']['eps'] ?? null,
                'dub' => $recommendedAnime['tvInfo']['dub'] ?? null
            ]
        ];
    }

    $relatedAnimeList = [];
    foreach ($data['related_data'] ?? [] as $relatedAnime) {
        $relatedAnimeList[] = [
            'id' => $relatedAnime['id'] ?? null,
            'data_id' => $relatedAnime['data_id'] ?? null,
            'name' => $relatedAnime['title'] ?? $relatedAnime['jname'] ?? null,
            'japanese' => $relatedAnime['jname'] ?? null,
            'poster' => $relatedAnime['poster'] ?? null,
            'type' => $relatedAnime['tvInfo']['showType'] ?? null,
            'adultContent' => $relatedAnime['adultContent'] ?? false,
            'episodes' => [
                'sub' => $relatedAnime['tvInfo']['sub'] ?? $relatedAnime['tvInfo']['eps'] ?? null,
                'dub' => $relatedAnime['tvInfo']['dub'] ?? null
            ]
        ];
    }

    $subEp = $tvInfo['sub'] ?? $tvInfo['eps'] ?? 0;
    if ($subEp === null || $subEp === '' || $subEp === '?' || (int)$subEp <= 0) {
        $resolved = jikan_episode_total((string)$animeId);
        if ($resolved > 0) {
            $subEp = $resolved;
        }
    } else {
        $subEp = (int)$subEp;
    }

    $recIds = [];
    foreach ($recommendedAnimeList as $item) {
        $sub = $item['episodes']['sub'] ?? null;
        if (!empty($item['id']) && ($sub === null || $sub === '' || $sub === '?' || (int)$sub <= 0)) {
            $recIds[] = (int)$item['id'];
        }
    }
    foreach ($relatedAnimeList as $item) {
        $sub = $item['episodes']['sub'] ?? null;
        if (!empty($item['id']) && ($sub === null || $sub === '' || $sub === '?' || (int)$sub <= 0)) {
            $recIds[] = (int)$item['id'];
        }
    }
    if ($recIds) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/src/api/anilist_client.php';
        $counts = anilist_episode_counts_by_mal($recIds);
        foreach ($recommendedAnimeList as $i => $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id && !empty($counts[$id])) {
                $recommendedAnimeList[$i]['episodes']['sub'] = $counts[$id];
            }
        }
        foreach ($relatedAnimeList as $i => $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id && !empty($counts[$id])) {
                $relatedAnimeList[$i]['episodes']['sub'] = $counts[$id];
            }
        }
    }

    return [
        'poster' => $data['poster'] ?? 'default_poster.jpg',
        'id' => (string)($data['id'] ?? $animeId),
        'malId' => $data['malId'] ?? $animeId,
        'anilistId' => $data['anilistId'] ?? null,
        'data_id' => (string)($data['data_id'] ?? $data['id'] ?? $animeId),
        'title' => $data['title'] ?? $data['jname'] ?? 'Unknown',
        'jname' => $data['jname'] ?? $data['title'] ?? 'Unknown',
        'japanese' => $data['jname'] ?? $data['title'] ?? '',
        'synonyms' => $data['synonyms'] ?? '',
        'overview' => (string)($animeInfo['Overview'] ?? 'No description'),
        'showType' => (string)($tvInfo['showType'] ?? ''),
        'rating' => (string)($tvInfo['rating'] ?? ''),
        'subEp' => $subEp,
        'dubEp' => $tvInfo['dub'] ?? 0,
        'aired' => (string)($animeInfo['Aired'] ?? ''),
        'premiered' => (string)($animeInfo['Premiered'] ?? ''),
        'malscore' => (string)($animeInfo['MAL Score'] ?? ''),
        'status' => (string)($animeInfo['Status'] ?? ''),
        'genres' => $animeInfo['Genres'] ?? [],
        'quality' => (string)($tvInfo['quality'] ?? 'HD'),
        'duration' => (string)($tvInfo['duration'] ?? $animeInfo['Duration'] ?? ''),
        'actors' => $chList,
        'studio' => (string)($animeInfo['Studios'] ?? ''),
        'producer' => $animeInfo['Producers'] ?? [],
        'season' => $seasonList,
        'relatedAnimes' => $relatedAnimeList,
        'recommendedAnimes' => $recommendedAnimeList,
        'adultContent' => $data['adultContent'] ?? false
    ];
}
