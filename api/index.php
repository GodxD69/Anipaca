<?php
/**
 * Vercel Serverless Function Entry Point
 */
if (empty($_SERVER['DOCUMENT_ROOT']) || !file_exists($_SERVER['DOCUMENT_ROOT'] . '/router.php')) {
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
}
@chdir($_SERVER['DOCUMENT_ROOT']);
require_once __DIR__ . '/../router.php';
