<?php

declare(strict_types=1);

/**
 * 缩略图端点：/thumb/{folder}/{file}?w=420
 *
 * 参照 download-widget 的 /thumb 实现：按宽度生成 JPEG，缓存到 downloads/.thumbs/{folder}/。
 * 没有 GD、或者源文件不是可处理的图片时，直接返回原图。
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

$folder = (string) ($_GET['id'] ?? '');
$file = (string) ($_GET['file'] ?? '');
$width = (int) ($_GET['w'] ?? 420);

$valid = static fn(string $value): bool => $value !== ''
    && !str_contains($value, '..')
    && preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1;

if (!$valid($folder) || !$valid($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '缩略图不存在';
    return;
}

$width = max(64, min(960, $width));

$config = app_config();
$roots = array_values(array_unique([$config->downloadDir, $config->ugoiraDir]));

$srcPath = null;
$folderPath = null;
foreach ($roots as $root) {
    $candidateFolder = realpath($root . DIRECTORY_SEPARATOR . $folder);
    if ($candidateFolder === false || !is_dir($candidateFolder)) {
        continue;
    }
    $candidate = realpath($candidateFolder . DIRECTORY_SEPARATOR . $file);
    if ($candidate === false || !is_file($candidate)) {
        continue;
    }
    if (!str_starts_with($candidate, $candidateFolder . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $srcPath = $candidate;
    $folderPath = $candidateFolder;
    break;
}

if ($srcPath === null || $folderPath === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '缩略图不存在';
    return;
}

$isHead = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD';

function serve_file(string $path, string $contentType, bool $isHead): never
{
    $size = (int) (filesize($path) ?: 0);
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $size);
    header('Cache-Control: public, max-age=604800');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($path)) . ' GMT');
    if (!$isHead) {
        readfile($path);
    }
    exit;
}

$allowCache = static function (string $cachePath, string $sourcePath) use ($isHead): void {
    $cacheTime = filemtime($cachePath);
    $sourceTime = filemtime($sourcePath);
    if ($cacheTime !== false && $sourceTime !== false && $cacheTime >= $sourceTime) {
        serve_file($cachePath, 'image/jpeg', $isHead);
    }
};

$extension = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
$sourceType = match ($extension) {
    'jpg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    default => 'application/octet-stream',
};

$gifOrWebp = in_array($extension, ['gif', 'webp'], true);
$canResize = function_exists('imagecreatetruecolor')
    && in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)
    && (!$gifOrWebp || function_exists('imagecreatefrom' . $extension));

$cacheDir = $config->downloadDir . DIRECTORY_SEPARATOR . '.thumbs' . DIRECTORY_SEPARATOR . $folder;
$cachePath = $cacheDir . DIRECTORY_SEPARATOR . 'w' . $width . '_' . pathinfo($srcPath, PATHINFO_FILENAME) . '.jpg';

if (!$canResize) {
    serve_file($srcPath, $sourceType, $isHead);
}

if (is_file($cachePath)) {
    $allowCache($cachePath, $srcPath);
}

$info = @getimagesize($srcPath);
if ($info === false || $info[0] < 1 || $info[1] < 1) {
    serve_file($srcPath, $sourceType, $isHead);
}

[$srcWidth, $srcHeight] = $info;
if ($srcWidth <= $width) {
    serve_file($srcPath, $sourceType, $isHead);
}

$targetWidth = $width;
$targetHeight = max(1, (int) round($srcHeight * $targetWidth / $srcWidth));

$create = match ($extension) {
    'jpg', 'jpeg' => 'imagecreatefromjpeg',
    'png' => 'imagecreatefrompng',
    'gif' => 'imagecreatefromgif',
    'webp' => 'imagecreatefromwebp',
    default => null,
};

$sourceImage = $create !== null && function_exists($create) ? @$create($srcPath) : false;
if ($sourceImage === false) {
    serve_file($srcPath, $sourceType, $isHead);
}

$thumb = imagecreatetruecolor($targetWidth, $targetHeight);
if ($thumb === false) {
    imagedestroy($sourceImage);
    serve_file($srcPath, $sourceType, $isHead);
}

// PNG / GIF 的透明区域铺白底，输出统一为 JPEG
$white = imagecolorallocate($thumb, 255, 255, 255);
imagefill($thumb, 0, 0, $white);
imagecopyresampled($thumb, $sourceImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcWidth, $srcHeight);
imagedestroy($sourceImage);

if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
    imagedestroy($thumb);
    serve_file($srcPath, $sourceType, $isHead);
}

$tmpPath = $cachePath . '.' . getmypid() . '.tmp';
$saved = imagejpeg($thumb, $tmpPath, 82);
imagedestroy($thumb);

if (!$saved || !rename($tmpPath, $cachePath)) {
    @unlink($tmpPath);
    serve_file($srcPath, $sourceType, $isHead);
}

serve_file($cachePath, 'image/jpeg', $isHead);
