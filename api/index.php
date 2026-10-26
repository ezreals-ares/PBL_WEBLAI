<?php

/**
 * Vercel entrypoint.
 *
 * Vercel sends every request that is not a static asset here. The original
 * path is passed in the "__p" query parameter by the route defined in
 * vercel.json; this script resolves it to the matching page under public/ or
 * admin/ and runs it, reproducing the layout the app expects on Apache.
 */

$root = dirname(__DIR__);

$relative = $_GET['__p'] ?? null;

if ($relative === null || $relative === '') {
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $uriPath = preg_replace('#^/api/index\.php#', '', $uriPath);
    $relative = $uriPath;
}

// Never let the routing parameter leak into the page.
unset($_GET['__p'], $_REQUEST['__p']);

$relative = ltrim(str_replace('\\', '/', (string) $relative), '/');

if ($relative === '' || $relative === 'index.php') {
    $candidate = $root . '/public/index.php';
    $publicPath = '/index.php';
} elseif (preg_match('#^admin/[A-Za-z0-9_-]+\.php$#', $relative)) {
    $candidate = $root . '/' . $relative;
    $publicPath = '/' . $relative;
} elseif (preg_match('#^[A-Za-z0-9_-]+\.php$#', $relative)) {
    $candidate = $root . '/public/' . $relative;
    $publicPath = '/' . $relative;
} else {
    $candidate = null;
    $publicPath = null;
}

if ($candidate === null || !is_file($candidate)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found';
    exit;
}

// Rebuild the request metadata so the included page sees the public URL
// instead of the internal /api/index.php entrypoint.
$_SERVER['SCRIPT_NAME'] = $publicPath;
$_SERVER['PHP_SELF'] = $publicPath;
$_SERVER['SCRIPT_FILENAME'] = $candidate;
$_SERVER['DOCUMENT_ROOT'] = $root;

$query = http_build_query($_GET);
$_SERVER['QUERY_STRING'] = $query;
$_SERVER['REQUEST_URI'] = $publicPath . ($query === '' ? '' : '?' . $query);

// Apache runs a script with its own directory as the working directory.
chdir(dirname($candidate));

require $candidate;
