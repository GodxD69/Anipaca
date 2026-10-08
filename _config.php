<?php 
require_once __DIR__ . '/src/component/database.php';

global $conn, $websiteTitle, $websiteUrl, $websiteLogo, $contactEmail, $version, $discord, $github, $telegram, $instagram, $zpi, $proxy, $banner;

if (!isset($conn) || !$conn) {
    $conn = new AnipacaDatabase("localhost", "root", "", "anipaca");
}
$GLOBALS['conn'] = $conn;

if ($conn->connect_error) {
    error_log("Database connection notice: " . $conn->connect_error);
}

$websiteTitle = "AniPaca";
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') 
            ? "https" : "http";
$host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost:8000'));
$websiteUrl = "{$protocol}://{$host}";
$websiteLogo = $websiteUrl . "/public/logo/logo.png";
$contactEmail = "raisulentertainment@gmail.com";

$version = "1.0.3";

$discord = "https://dcd.gg/anipaca";
$github = "https://github.com/PacaHat";
$telegram = "https://t.me/anipaca";
$instagram = "https://www.instagram.com/pxr15_"; 

// Catalog API: local Jikan/MAL adapter
$zpi = $websiteUrl . "/src/api";

// Built-in PHP proxy
$proxy = $websiteUrl . "/src/ajax/proxy.php?url=";

$banner = $websiteUrl . "/public/images/banner.png";

// Ensure all are registered in GLOBALS
$GLOBALS['websiteTitle'] = $websiteTitle;
$GLOBALS['websiteUrl'] = $websiteUrl;
$GLOBALS['websiteLogo'] = $websiteLogo;
$GLOBALS['contactEmail'] = $contactEmail;
$GLOBALS['version'] = $version;
$GLOBALS['discord'] = $discord;
$GLOBALS['github'] = $github;
$GLOBALS['telegram'] = $telegram;
$GLOBALS['instagram'] = $instagram;
$GLOBALS['zpi'] = $zpi;
$GLOBALS['proxy'] = $proxy;
$GLOBALS['banner'] = $banner;
