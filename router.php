<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rawurldecode($uri);

if (str_starts_with($uri, '/api/') || $uri === '/api') {
    require __DIR__ . '/api/index.php';
    return true;
}

if ($uri === '/download' || $uri === '/download/') {
    require __DIR__ . '/web/download.php';
    return true;
}

if (preg_match('#^/artworks/([^/]+)/?$#', $uri, $m)) {
    $_GET['id'] = rawurldecode($m[1]);
    require __DIR__ . '/web/artwork.php';
    return true;
}

if (preg_match('#^/play/([^/]+)/?$#', $uri, $m)) {
    $_GET['id'] = rawurldecode($m[1]);
    require __DIR__ . '/web/player.php';
    return true;
}

if (preg_match('#^/edit/([^/]+)/?$#', $uri, $m)) {
    $_GET['id'] = rawurldecode($m[1]);
    require __DIR__ . '/web/edit.php';
    return true;
}

if (preg_match('#^/thumb/([^/]+)/([^/]+)$#', $uri, $m)) {
    $_GET['id'] = rawurldecode($m[1]);
    $_GET['file'] = rawurldecode($m[2]);
    require __DIR__ . '/web/thumb.php';
    return true;
}

if (preg_match('#^/downloads/(\d+)_ugoira1920x1080/index\.html$#', $uri, $m)) {
    header('Location: /play/' . rawurlencode($m[1]), true, 302);
    return true;
}

if ($uri === '/downloads' || str_starts_with($uri, '/downloads/')) {
    $rel = substr($uri, strlen('/downloads'));
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'downloads' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if ($rel !== '' && is_file($file)) {
        return false;
    }
    http_response_code(404);
    echo 'Not Found';
    return true;
}

$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $uri);
if ($uri !== '/' && is_file($file)) {
    return false;
}

if ($uri === '/' || $uri === '/index.html') {
    require __DIR__ . '/web/home.php';
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not Found';
return true;
