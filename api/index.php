<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Pixiv-Cookie, X-Proxy-Port');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = preg_replace('#^/api#', '', $uri) ?: '/';
$path = '/' . trim($path, '/');
if ($path !== '/') {
    $path = rtrim($path, '/');
}

try {
    route_api($method, $path);
} catch (InvalidArgumentException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::error($e->getMessage(), 500);
}

function route_api(string $method, string $path): void
{
    if ($path === '/health' && $method === 'GET') {
        Response::ok(['ok' => true, 'port' => app_config()->port]);
    }

    if ($path === '/list' && $method === 'GET') {
        $filter = [
            'page' => $_GET['page'] ?? 1,
            'page_size' => $_GET['pageSize'] ?? $_GET['page_size'] ?? 20,
            'search' => $_GET['search'] ?? '',
            'illust_type' => $_GET['illustType'] ?? $_GET['illust_type'] ?? null,
            'sort' => $_GET['sort'] ?? 'modified_at',
            'order' => $_GET['order'] ?? 'desc',
        ];
        $db = app_db();
        $items = decorate_works($db->listWorks($filter));
        Response::ok([
            'items' => $items,
            'total' => $db->countWorks($filter),
            'page' => max(1, (int) $filter['page']),
            'pageSize' => min(100, max(1, (int) $filter['page_size'])),
        ]);
    }

    if (preg_match('#^/illust/([^/]+)$#', $path, $m) && $method === 'GET') {
        $http = make_http_client();
        $api = new PixivApi($http);
        $data = $api->fetchIllust(rawurldecode($m[1]), request_cookie());
        Response::ok($data);
    }

    if (preg_match('#^/ugoira_meta/([^/]+)$#', $path, $m) && $method === 'GET') {
        $http = make_http_client();
        $api = new PixivApi($http);
        $data = $api->fetchUgoiraMeta(rawurldecode($m[1]), request_cookie());
        Response::ok($data);
    }

    if (preg_match('#^/works/([^/]+)$#', $path, $m) && $method === 'GET') {
        $work = app_db()->getWork(rawurldecode($m[1]));
        if ($work === null) {
            Response::error('作品不存在', 404);
        }
        $work['files'] = app_db()->listFiles($work['id']);
        $work['coverUrl'] = work_cover_url($work);
        $work['viewUrl'] = work_view_url($work);
        Response::ok($work);
    }

    if ($path === '/download' && $method === 'POST') {
        set_time_limit(0);
        $json = request_json();
        $id = (string) ($json['id'] ?? $json['taskName'] ?? '');
        $cookie = request_cookie();
        $redownload = !empty($json['redownload']);
        $character = trim((string) ($json['character'] ?? ''));
        $stream = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/event-stream');

        if (!$stream) {
            $result = make_downloader()->downloadById($id, $cookie, $redownload, $character);
            Response::ok($result);
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $send = static function (array $event): void {
            echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
        };

        $send(['type' => 'open']);

        try {
            $result = make_downloader()->downloadById($id, $cookie, $redownload, $character, $send);
            $send(['type' => 'done', 'result' => $result]);
        } catch (Throwable $e) {
            $send(['type' => 'error', 'message' => $e->getMessage()]);
        }

        exit;
    }

    if (preg_match('#^/works/([^/]+)/character$#', $path, $m) && in_array($method, ['POST', 'PUT'], true)) {
        $json = request_json();
        $character = trim((string) ($json['character'] ?? ''));
        $work = make_downloader()->updateCharacter(rawurldecode($m[1]), $character);
        Response::ok($work);
    }

    if (preg_match('#^/works/([^/]+)$#', $path, $m) && in_array($method, ['PUT', 'PATCH'], true)) {
        $id = rawurldecode($m[1]);
        $db = app_db();
        if ($db->getWork($id) === null) {
            Response::error('作品不存在', 404);
        }

        $json = request_json();
        $fields = [];
        foreach (['name', 'title', 'character'] as $key) {
            if (array_key_exists($key, $json) && is_string($json[$key])) {
                $fields[$key] = trim($json[$key]);
            }
        }
        if (array_key_exists('tags', $json)) {
            $tags = $json['tags'];
            if (is_string($tags)) {
                $tags = preg_split('/[,，]/u', $tags) ?: [];
            }
            if (is_array($tags)) {
                $clean = [];
                foreach ($tags as $tag) {
                    $tag = trim((string) $tag);
                    if ($tag !== '') {
                        $clean[] = $tag;
                    }
                }
                $fields['tags'] = array_values(array_unique($clean));
            }
        }

        if ($fields === []) {
            Response::error('没有可更新的字段', 400);
        }

        $db->updateWorkInfo($id, $fields);
        $work = $db->getWork($id) ?? [];
        $work['files'] = $db->listFiles($id);
        $work['coverUrl'] = work_cover_url($work);
        $work['viewUrl'] = work_view_url($work);
        Response::ok($work);
    }

    if (preg_match('#^/works/([^/]+)$#', $path, $m) && $method === 'DELETE') {
        $json = request_json();
        $deleteFiles = !array_key_exists('deleteFiles', $json) || !empty($json['deleteFiles']);
        make_downloader()->deleteWork(rawurldecode($m[1]), $deleteFiles);
        Response::ok(['deleted' => true]);
    }

    if ($path === '/sync' && $method === 'POST') {
        $count = sync_existing_ugoira();
        Response::ok(['synced' => $count]);
    }

    Response::error('Not Found', 404);
}

function sync_existing_ugoira(): int
{
    $root = app_config()->ugoiraDir;
    $db = app_db();
    $count = 0;
    foreach (scandir($root) ?: [] as $name) {
        if (!preg_match('/^(\d+)_ugoira1920x1080$/', $name, $m)) {
            continue;
        }
        $id = $m[1];
        $dir = $root . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($dir)) {
            continue;
        }

        $character = '';
        $meta = [];
        $data = parse_ugoira_data_js($dir . DIRECTORY_SEPARATOR . 'data.js');
        if (is_array($data)) {
            $body = is_array($data['body'] ?? null) ? $data['body'] : [];
            $character = (string) ($body['character'] ?? '');
            $meta = [
                'src' => (string) ($body['src'] ?? ''),
                'originalSrc' => (string) ($body['originalSrc'] ?? ''),
                'mime_type' => (string) ($body['mime_type'] ?? 'image/jpeg'),
                'frames' => is_array($body['frames'] ?? null) ? $body['frames'] : [],
            ];
        }
        if (($meta['frames'] ?? []) === []) {
            $meta['frames'] = work_frames_from_dir($dir);
        }

        $files = [];
        $totalSize = 0;
        foreach (scandir($dir) ?: [] as $file) {
            if (!preg_match('/^\d+\.(jpe?g|png|gif|webp)$/i', $file)) {
                continue;
            }
            $full = $dir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($full)) {
                continue;
            }
            $size = (int) filesize($full);
            $files[] = ['filename' => $file, 'size' => $size];
            $totalSize += $size;
        }

        $db->upsertWork([
            'id' => $id,
            'name' => $character !== '' ? $character : $id,
            'title' => $character !== '' ? $character : $id,
            'character' => $character,
            'illust_type' => 2,
            'path' => $dir,
            'file_count' => count($files),
            'size' => $totalSize,
            'page_count' => count($meta['frames']),
            'tags' => [],
            'meta' => $meta,
            'created_at' => filectime($dir) ?: time(),
            'modified_at' => filemtime($dir) ?: time(),
        ]);
        $db->replaceFiles($id, $files);

        if (count($meta['frames']) > 0) {
            foreach (['data.js', 'index.html'] as $legacy) {
                $legacyPath = $dir . DIRECTORY_SEPARATOR . $legacy;
                if (is_file($legacyPath)) {
                    @unlink($legacyPath);
                }
            }
        }
        $count++;
    }
    return $count;
}
