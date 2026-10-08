<?php
/**
 * AniPaca - Universal Entry Point (Local + Vercel)
 */
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = __DIR__;
}
require_once __DIR__ . '/router.php';
