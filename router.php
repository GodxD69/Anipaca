<?php
// Include the global configuration file
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = __DIR__;
}
require_once __DIR__ . '/_config.php';

// Serve static files directly if they exist in built-in server
$rawUri = $_SERVER['REQUEST_URI'] ?? '/';
$uriPath = urldecode(parse_url($rawUri, PHP_URL_PATH) ?? '/');
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
$requestedFile = $docRoot . '/' . ltrim($uriPath, '/\\');

if ($uriPath !== '/' && $uriPath !== '' && file_exists($requestedFile) && !is_dir($requestedFile)) {
    // If it's not a php file, return false to serve it statically
    if (!preg_match('/\.php$/i', $requestedFile)) {
        return false;
    }
}

// Get the URI without query strings and leading/trailing slashes
$uri = trim($uriPath, '/');

// Define routes with regex patterns and associated files
$routes = [
    // Main Pages
    '/^$/' => 'home.php',
    '/^home$/' => 'home.php',
    '/^filter$/' => 'filter.php',
    '/^search$/' => 'search.php',
    '/^az-list(?:\/([a-zA-Z0-9-]+))?$/' => 'src/pages/anime/az-list.php',
    '/^db$/' => 'db-init.php',

    // API Routes
    '/^src\/api(?:\/(.*))?$/' => 'src/api/index.php',
    '/^api(?:\/(.*))?$/' => 'src/api/index.php',

    // Anime Pages
    '/^details$/' => 'src/pages/anime/details.php',
    '/^random$/' => 'src/component/anime/random.php', 
    '/^anime$/' => 'src/pages/anime/anime.php',
    '/^details\/([a-zA-Z0-9\-]+)$/' => 'src/pages/anime/details.php',
    '/^anime\/([a-zA-Z0-9\-]+)$/' => 'src/pages/anime/anime.php',
    '/^watch\/([a-zA-Z0-9\-]+)$/' => 'src/pages/anime/watch.php',
    '/^ajax\/comment(?:\/(.*))?$/' => 'src/ajax/comment/index.php',
    '/^src\/ajax\/comment(?:\/(.*))?$/' => 'src/ajax/comment/index.php',
    '/^genre\/([a-zA-Z0-9\-]+)$/' => 'src/pages/anime/genre.php',
    '/^producer\/([a-zA-Z0-9\-\.]+)\/?$/' => 'src/pages/anime/producer.php',
    '/^actors\/([a-zA-Z0-9\-]+)$/' => 'src/pages/anime/actors.php',
    '/^character\/([a-zA-Z0-9\-]+)\/?$/' => 'src/pages/anime/character.php',

    // Player Routes
    '/^(?:src\/)?player\/(sub|dub)(?:\.php)?$/' => 'src/player/$1.php',

    // Direct Ajax endpoints
    '/^(?:src\/)?ajax\/([a-zA-Z0-9_\-]+)(?:\.php)?$/' => 'src/ajax/$1.php',

    // User Pages
    '/^login$/' => 'src/user/login.php',
    '/^register$/' => 'src/user/register.php', 
    '/^logout$/' => 'src/user/logout.php',
    '/^profile$/' => 'src/user/profile.php',
    '/^watchlist$/' => 'src/user/watchlist.php',
    '/^watchlist\.php(?:\?.*)?$/' => 'src/user/watchlist.php',
    '/^changepass$/' => 'src/user/changepass.php',
    '/^continue-watching$/' => 'src/user/continue-watching.php',

    // Extra Pages
    '/^dmca$/' => 'src/pages/extra/dmca.php',
    '/^terms$/' => 'src/pages/extra/terms.php',

    // Sitemap Routes
    '/^sitemaps\/popular\.xml$/' => 'public/sitemap/sitemappopular.xml',
    '/^sitemaps\/movie\.xml$/' => 'public/sitemap/sitemapmovie.xml',
    '/^sitemaps\/airing\.xml$/' => 'public/sitemap/sitemapairing.xml',
    '/^sitemaps\/ongoing-sitemap\.xml$/' => 'public/sitemap/sitemapongoing.xml',
    '/^sitemaps\/allanime-sitemap\.xml$/' => 'public/sitemap/sitemapallanime.xml',
    '/^sitemaps\/sitemap\.xml$/' => 'public/sitemap/sitemap.php',
    '/^sitemap\.xml$/' => 'public/sitemap/sitemap.php',
];

// Route matching
$handled = false;

foreach ($routes as $pattern => $file) {
    if (preg_match($pattern, $uri, $matches)) {
        // Handle dynamic placeholders in target file e.g. $1
        if (strpos($file, '$1') !== false && !empty($matches[1])) {
            $file = str_replace('$1', $matches[1], $file);
        }
        
        // Pass captured groups as GET parameters
        if (!empty($matches[1])) {
            $_GET['slug'] = $matches[1];
            $_GET['__path'] = $matches[1];
        }

        $fullPath = __DIR__ . '/' . $file;
        if (file_exists($fullPath)) {
            extract($GLOBALS, EXTR_SKIP);
            include $fullPath;
            $handled = true;
            break;
        }
    }
}

// Fallback to 404 if no route matches
if (!$handled) {
    http_response_code(404);
    include __DIR__ . '/404.php';
}
?>
