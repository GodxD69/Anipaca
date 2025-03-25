<?php 

$conn = new mysqli("HOSTNAME", "USERNAME", "PASSWORD", "DATABASE");

if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    
}

$websiteTitle = "AniPaca";
$websiteUrl = "//{$_SERVER['SERVER_NAME']}";
$websiteLogo = $websiteUrl . "/public/logo/logo.png";
$contactEmail = "@gmail.com";

$version = "2";

$discord = "https://dcd.gg/anipaca";
$github = "https://github.com/PacaHat";
$telegram = "https://t.me/anipaca";
$instagram = "https://www.instagram.com/pxr15_";

// all the api you need

$api = "https://aniwatch-api-wheat.vercel.app//api/v2/hianime"; //https://github.com/ghoshRitesh12/aniwatch-api
$zpi = "https://zen-api-nu.vercel.app/api"; //https://github.com/PacaHat/zen-api
$proxy = "https://m3u8-proxy-five-nu.vercel.app/api/v1/streamingProxy?url="; //https://github.com/MetaHat/m3u8-streaming-proxy

// Cloudflare Turnstile credentials // Use Links Below 
$cloudflare_turnstile_site_key = 'your_cloudflare_site_key'; // https://www.cloudflare.com/application-services/products/turnstile/
$cloudflare_turnstile_secret_key = 'your_cloudflare_secret_key'; // https://www.cloudflare.com/application-services/products/turnstile/

$banner = $websiteUrl . "/public/images/banner.png";

    
