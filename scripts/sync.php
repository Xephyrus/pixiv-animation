<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

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

    // 仅在帧元数据已入库后清理历史单作品页面文件
    if (count($meta['frames']) > 0) {
        foreach (['data.js', 'index.html'] as $legacy) {
            $legacyPath = $dir . DIRECTORY_SEPARATOR . $legacy;
            if (is_file($legacyPath)) {
                @unlink($legacyPath);
            }
        }
    }

    $count++;
    echo "synced {$id}" . ($character !== '' ? " ({$character})" : '') . ' frames=' . count($meta['frames']) . PHP_EOL;
}

echo "done: {$count} works" . PHP_EOL;
