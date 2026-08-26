<?php 

$conn = new mysqli("localhost", "root", "", "anipaca");


if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    echo("Database connection failed.");
}

$websiteTitle = "AniPaca";
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
// Installed at http://localhost/ (XAMPP htdocs root)
$websiteUrl = "{$protocol}://{$_SERVER['SERVER_NAME']}";
$websiteLogo = $websiteUrl . "/public/logo/logo.png";
$contactEmail = "raisulentertainment@gmail.com";

$version = "1.0.3";

$discord = "https://dcd.gg/anipaca";
$github = "https://github.com/PacaHat";
$telegram = "https://t.me/anipaca";
$instagram = "https://www.instagram.com/pxr15_"; 

// Catalog API: local Jikan/MAL adapter (zen-api removed — metadata only, no streams)
$zpi = $websiteUrl . "/src/api";

// Built-in PHP proxy (unused without streams; kept for player stubs)
$proxy = $websiteUrl . "/src/ajax/proxy.php?url=";


$banner = $websiteUrl . "/public/images/banner.png";

    
