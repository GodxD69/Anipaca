<?php
require_once('../../_config.php');
require_once(__DIR__ . '/../api/jikan_client.php');
header('Content-Type: application/json');

if (isset($_GET['keyword'])) {
    $keyword = trim($_GET['keyword']);
    $cacheKey = md5($keyword);
    $cachePath = __DIR__ . '/../../cache/search/';
    $cacheFile = $cachePath . $cacheKey . '.json';
    $cacheTime = 300;

    if (!is_dir($cachePath)) {
        mkdir($cachePath, 0777, true);
    }

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $cacheTime)) {
        $cached = file_get_contents($cacheFile);
        $decoded = json_decode($cached, true);
        if (is_array($decoded) && !empty($decoded['success'])) {
            echo $cached;
            exit;
        }
        @unlink($cacheFile);
    }

    try {
        $data = jikan_search($keyword);

        if ($data && !empty($data['success'])) {
            $response = json_encode($data);
            file_put_contents($cacheFile, $response);
            echo $response;
        } else {
            $errorResponse = json_encode([
                'success' => false,
                'message' => 'No results found'
            ]);
            echo $errorResponse;
        }
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'No keyword provided'
    ]);
}
