<?php
/**
 * Vercel Serverless Function Entry Point
 */
chdir(__DIR__ . '/..');
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
}
require_once __DIR__ . '/../router.php';
