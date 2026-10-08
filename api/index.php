<?php
/**
 * Vercel Serverless Function Entry Point
 */
if (isset($_GET['debug']) || (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'debug') !== false)) {
    header('Content-Type: application/json');
    echo json_encode([
        'server' => $_SERVER,
        'get' => $_GET,
        'dir' => __DIR__,
        'parent_dir' => dirname(__DIR__),
        'parent_files' => is_dir(dirname(__DIR__)) ? scandir(dirname(__DIR__)) : 'not dir',
        'src_files' => is_dir(dirname(__DIR__) . '/src') ? scandir(dirname(__DIR__) . '/src') : 'no src',
        'assets_files' => is_dir(dirname(__DIR__) . '/src/assets') ? scandir(dirname(__DIR__) . '/src/assets') : 'no assets',
        'css_files' => is_dir(dirname(__DIR__) . '/src/assets/css') ? scandir(dirname(__DIR__) . '/src/assets/css') : 'no css',
    ], JSON_PRETTY_PRINT);
    exit;
}

chdir(__DIR__ . '/..');
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
}
require_once __DIR__ . '/../router.php';
