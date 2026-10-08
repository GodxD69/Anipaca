<?php
/**
 * Vercel Serverless Function Entry Point
 */
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
}
require_once dirname(__DIR__) . '/router.php';
