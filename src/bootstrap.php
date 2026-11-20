<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/PixivApi.php';
require_once __DIR__ . '/Downloader.php';

function app_root(): string
{
    return dirname(__DIR__);
}

function app_config(): Config
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = Config::load(app_root());
    }
    return $cfg;
}

function app_db(): Database
{
    static $db = null;
    if ($db === null) {
        $db = new Database(app_config()->dbPath);
    }
    return $db;
}

function request_json(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $cached = [];
    }
    $data = json_decode($raw, true);
    return $cached = is_array($data) ? $data : [];
}

function request_cookie(): string
{
    $header = $_SERVER['HTTP_X_PIXIV_COOKIE'] ?? '';
    if (is_string($header) && trim($header) !== '') {
        return trim($header);
    }
    $json = request_json();
    if (!empty($json['cookie']) && is_string($json['cookie'])) {
        return trim($json['cookie']);
    }
    $param = trim((string) ($_POST['cookie'] ?? $_GET['cookie'] ?? ''));
    if ($param !== '') {
        return $param;
    }
    // 兼容直接从浏览器 / curl 复制过来的请求：Cookie 走标准请求头时也要能转发给 Pixiv
    $cookie = $_SERVER['HTTP_COOKIE'] ?? '';
    if (is_string($cookie) && trim($cookie) !== '') {
        return trim($cookie);
    }
    return '';
}

function request_proxy_port(): int
{
    $header = $_SERVER['HTTP_X_PROXY_PORT'] ?? '';
    if (is_string($header) && trim($header) !== '') {
        return (int) trim($header);
    }
    $json = request_json();
    if (isset($json['proxyPort'])) {
        return (int) $json['proxyPort'];
    }
    return (int) ($_POST['proxyPort'] ?? $_GET['proxyPort'] ?? 0);
}

function make_http_client(?int $proxyPort = null): HttpClient
{
    return new HttpClient(app_config(), $proxyPort ?? request_proxy_port());
}

function make_downloader(?int $proxyPort = null): Downloader
{
    $http = make_http_client($proxyPort);
    return new Downloader(app_config(), app_db(), new PixivApi($http), $http);
}

function work_view_url(array $work): string
{
    if ((int) $work['illustType'] === 2) {
        return '/play/' . rawurlencode((string) $work['id']);
    }
    return '/artworks/' . rawurlencode((string) $work['id']);
}

function work_cover_url(array $work): string
{
    $id = (string) $work['id'];
    if ((int) $work['illustType'] === 2) {
        $folder = basename((string) $work['path']);
        return '/downloads/' . rawurlencode($folder) . '/000000.jpg';
    }

    $files = app_db()->listFiles($id);
    if ($files) {
        return '/downloads/' . rawurlencode($id) . '/' . rawurlencode($files[0]);
    }
    return '';
}

function work_frame_base(array $work): string
{
    $folder = basename((string) ($work['path'] ?? ''));
    if ($folder === '') {
        return '';
    }
    return '/downloads/' . rawurlencode($folder) . '/';
}

/** @return list<array{file:string,delay:int}> */
function work_frames_from_dir(string $dir, int $defaultDelay = 50): array
{
    $frames = [];
    foreach (scandir($dir) ?: [] as $file) {
        if (!preg_match('/^\d+\.(jpe?g|png|gif|webp)$/i', $file)) {
            continue;
        }
        $frames[] = ['file' => $file, 'delay' => $defaultDelay];
    }
    usort($frames, static fn(array $a, array $b): int => strcmp($a['file'], $b['file']));
    return $frames;
}

function build_ugoira_animation_data(array $work): array
{
    $meta = is_array($work['meta'] ?? null) ? $work['meta'] : [];
    $frames = $meta['frames'] ?? null;
    if (!is_array($frames) || $frames === []) {
        $frames = work_frames_from_dir((string) ($work['path'] ?? ''));
    }

    return [
        'error' => false,
        'message' => '',
        'body' => [
            'src' => (string) ($meta['src'] ?? ''),
            'originalSrc' => (string) ($meta['originalSrc'] ?? ''),
            'mime_type' => (string) ($meta['mime_type'] ?? 'image/jpeg'),
            'character' => (string) ($work['character'] ?? ''),
            'frameBase' => work_frame_base($work),
            'frames' => array_values($frames),
        ],
    ];
}

function parse_ugoira_data_js(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    if (!preg_match('/window\.animationData\s*=\s*(\{.*\})\s*;?\s*$/s', $raw, $m)) {
        return null;
    }

    $json = $m[1];
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    // 兼容未加引号的 JS object 字面量
    $normalized = preg_replace('/([\{,]\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:/', '$1"$2":', $json);
    if (!is_string($normalized)) {
        return null;
    }
    $decoded = json_decode($normalized, true);
    return is_array($decoded) ? $decoded : null;
}

/** @param list<array> $items */
function decorate_works(array $items): array
{
    foreach ($items as &$item) {
        $item['coverUrl'] = work_cover_url($item);
        $item['viewUrl'] = work_view_url($item);
    }
    unset($item);
    return $items;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
