<?php

declare(strict_types=1);

final class Downloader
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly PixivApi $api,
        private readonly HttpClient $http,
    ) {
    }

    public function downloadById(string $id, string $cookie, bool $redownload = false, string $character = ''): array
    {
        $id = PixivApi::normalizeId($id);
        if ($cookie === '') {
            throw new InvalidArgumentException('请输入 Pixiv Cookie');
        }

        $illust = $this->api->fetchIllust($id, $cookie);
        $body = $illust['body'] ?? null;
        if (!is_array($body)) {
            throw new RuntimeException('illust 响应缺少 body');
        }

        $type = (int) ($body['illustType'] ?? -1);
        return match ($type) {
            0, 1 => $this->downloadStill($id, $body, $cookie, $redownload),
            2 => $this->downloadUgoira($id, $body, $cookie, $redownload, $character),
            default => throw new RuntimeException('未知的 illustType: ' . $type),
        };
    }

    private function downloadStill(string $id, array $body, string $cookie, bool $redownload): array
    {
        $urls = PixivApi::buildOriginalUrls($body);
        $taskDir = $this->config->downloadDir . DIRECTORY_SEPARATOR . $id;

        if ($redownload && is_dir($taskDir)) {
            $this->clearDirectory($taskDir, false);
        }
        if (!is_dir($taskDir) && !mkdir($taskDir, 0755, true) && !is_dir($taskDir)) {
            throw new RuntimeException('无法创建下载目录: ' . $taskDir);
        }

        $logs = [];
        $files = [];
        $success = 0;
        $fail = 0;
        $totalSize = 0;

        foreach ($urls as $i => $url) {
            $filename = $this->filenameFromUrl($url);
            $savePath = $taskDir . DIRECTORY_SEPARATOR . $filename;
            $base = pathinfo($filename, PATHINFO_FILENAME);

            $existing = $this->findExistingImage($taskDir, $base);
            if ($existing !== null && !$redownload) {
                $size = (int) filesize($taskDir . DIRECTORY_SEPARATOR . $existing);
                $files[] = ['filename' => $existing, 'size' => $size];
                $totalSize += $size;
                $success++;
                $logs[] = ['type' => 'skipped', 'filename' => $existing, 'current' => $i + 1, 'total' => count($urls)];
                continue;
            }

            $logs[] = ['type' => 'start', 'filename' => $filename, 'url' => $url, 'current' => $i + 1, 'total' => count($urls)];
            try {
                $finalPath = $this->downloadImageWithFallback($url, $savePath, $cookie);
                $finalName = basename($finalPath);
                $size = (int) filesize($finalPath);
                $files[] = ['filename' => $finalName, 'size' => $size];
                $totalSize += $size;
                $success++;
                $logs[] = ['type' => 'success', 'filename' => $finalName, 'current' => $i + 1, 'total' => count($urls)];
            } catch (Throwable $e) {
                $fail++;
                $logs[] = [
                    'type' => 'error',
                    'filename' => $filename,
                    'error' => $e->getMessage(),
                    'current' => $i + 1,
                    'total' => count($urls),
                ];
            }

            if ($i < count($urls) - 1 && $this->config->delayMs > 0) {
                usleep($this->config->delayMs * 1000);
            }
        }

        $title = (string) ($body['title'] ?? $id);
        $tags = PixivApi::extractTags($body);
        $this->persistWork([
            'id' => $id,
            'name' => $id,
            'title' => $title,
            'character' => '',
            'illust_type' => (int) ($body['illustType'] ?? 0),
            'path' => $taskDir,
            'file_count' => count($files),
            'size' => $totalSize,
            'page_count' => (int) ($body['pageCount'] ?? count($urls)),
            'tags' => $tags,
            'created_at' => $this->dirCreatedAt($taskDir),
            'modified_at' => time(),
        ], $files);

        return [
            'id' => $id,
            'illustType' => (int) ($body['illustType'] ?? 0),
            'title' => $title,
            'path' => $taskDir,
            'successCount' => $success,
            'failCount' => $fail,
            'fileCount' => count($files),
            'files' => array_column($files, 'filename'),
            'logs' => $logs,
            'viewUrl' => '/artworks/' . rawurlencode($id),
        ];
    }

    private function downloadUgoira(string $id, array $body, string $cookie, bool $redownload, string $character): array
    {
        $meta = $this->api->fetchUgoiraMeta($id, $cookie);
        $metaBody = $meta['body'] ?? null;
        if (!is_array($metaBody)) {
            throw new RuntimeException('ugoira_meta 响应缺少 body');
        }

        $originalSrc = (string) ($metaBody['originalSrc'] ?? '');
        if ($originalSrc === '') {
            throw new RuntimeException('ugoira_meta 缺少 originalSrc');
        }

        $folderName = $id . '_ugoira1920x1080';
        $taskDir = rtrim($this->config->ugoiraDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folderName;
        $zipPath = rtrim($this->config->ugoiraDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $folderName . '.zip';

        if ($redownload) {
            if (is_dir($taskDir)) {
                $this->clearDirectory($taskDir, true);
            }
            if (is_file($zipPath)) {
                @unlink($zipPath);
            }
        }

        if (!is_dir($taskDir) && !mkdir($taskDir, 0755, true) && !is_dir($taskDir)) {
            throw new RuntimeException('无法创建 ugoira 目录: ' . $taskDir);
        }

        $logs = [];
        $logs[] = ['type' => 'info', 'message' => '使用代理 ' . $this->config->proxyHost . ':' . $this->http->resolvedProxyPort()];
        $logs[] = ['type' => 'start', 'filename' => basename($zipPath), 'url' => $originalSrc];

        $this->http->download($originalSrc, $zipPath, $cookie);
        $logs[] = ['type' => 'success', 'filename' => basename($zipPath), 'message' => '压缩包下载完成'];

        $this->extractZip($zipPath, $taskDir);
        $logs[] = ['type' => 'info', 'message' => '解压完成'];

        if (!@unlink($zipPath)) {
            $logs[] = ['type' => 'error', 'message' => '压缩包删除失败: ' . $zipPath];
        } else {
            $logs[] = ['type' => 'info', 'message' => '已删除压缩包'];
        }

        $existing = $this->db->getWork($id);
        if ($character === '' && $existing) {
            $character = (string) ($existing['character'] ?? '');
        }

        // 清理历史遗留的单作品页面文件，统一走 /play/{id}
        foreach (['data.js', 'index.html'] as $legacy) {
            $legacyPath = $taskDir . DIRECTORY_SEPARATOR . $legacy;
            if (is_file($legacyPath)) {
                @unlink($legacyPath);
            }
        }

        $frames = is_array($metaBody['frames'] ?? null) ? $metaBody['frames'] : [];
        $meta = [
            'src' => (string) ($metaBody['src'] ?? ''),
            'originalSrc' => $originalSrc,
            'mime_type' => (string) ($metaBody['mime_type'] ?? 'image/jpeg'),
            'frames' => $frames,
        ];

        $files = [];
        $totalSize = 0;
        foreach (scandir($taskDir) ?: [] as $name) {
            if (!preg_match('/^\d+\.(jpe?g|png|gif|webp)$/i', $name)) {
                continue;
            }
            $full = $taskDir . DIRECTORY_SEPARATOR . $name;
            if (!is_file($full)) {
                continue;
            }
            $size = (int) filesize($full);
            $files[] = ['filename' => $name, 'size' => $size];
            $totalSize += $size;
        }

        $title = (string) ($body['title'] ?? $id);
        $tags = PixivApi::extractTags($body);
        $this->persistWork([
            'id' => $id,
            'name' => $character !== '' ? $character : $id,
            'title' => $title,
            'character' => $character,
            'illust_type' => 2,
            'path' => $taskDir,
            'file_count' => count($files),
            'size' => $totalSize,
            'page_count' => count($frames),
            'tags' => $tags,
            'meta' => $meta,
            'created_at' => $this->dirCreatedAt($taskDir),
            'modified_at' => time(),
        ], $files);

        return [
            'id' => $id,
            'illustType' => 2,
            'title' => $title,
            'character' => $character,
            'path' => $taskDir,
            'folder' => $folderName,
            'successCount' => 1,
            'failCount' => 0,
            'fileCount' => count($files),
            'frameCount' => count($frames),
            'logs' => $logs,
            'viewUrl' => '/play/' . rawurlencode($id),
        ];
    }

    public function updateCharacter(string $id, string $character): array
    {
        $id = PixivApi::normalizeId($id);
        $work = $this->db->getWork($id);
        if ($work === null) {
            throw new RuntimeException('作品不存在');
        }
        if ((int) $work['illustType'] !== 2) {
            throw new RuntimeException('仅动图作品支持设置人物名称');
        }

        $this->db->setCharacter($id, $character);
        if ($character !== '') {
            $stmt = $this->db->pdo()->prepare('UPDATE works SET name = ? WHERE id = ?');
            $stmt->execute([$character, $id]);
        }

        return $this->db->getWork($id) ?? [];
    }

    public function deleteWork(string $id, bool $deleteFiles = true): void
    {
        $id = PixivApi::normalizeId($id);
        $work = $this->db->getWork($id);
        if ($work === null) {
            throw new RuntimeException('作品不存在');
        }
        if ($deleteFiles && !empty($work['path']) && is_dir($work['path'])) {
            $this->clearDirectory($work['path'], true);
        }
        $this->db->deleteWork($id);
    }

    private function persistWork(array $work, array $files): void
    {
        $this->db->upsertWork($work);
        $this->db->replaceFiles($work['id'], $files);
    }

    private function downloadImageWithFallback(string $url, string $savePath, string $cookie): string
    {
        $currentExt = strtolower(pathinfo($savePath, PATHINFO_EXTENSION));
        $tryExts = array_values(array_unique(array_filter([$currentExt, 'jpg', 'png'])));
        $lastError = null;

        foreach ($tryExts as $ext) {
            $targetUrl = $url;
            $targetPath = $savePath;
            if ($ext !== $currentExt) {
                $targetUrl = preg_replace('/\.(jpg|png)(\?|$)/i', '.' . $ext . '$2', $url) ?? $url;
                $targetPath = preg_replace('/\.[^.]+$/', '.' . $ext, $savePath) ?? $savePath;
            }
            try {
                $this->http->download($targetUrl, $targetPath, $cookie);
                return $targetPath;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        throw $lastError ?? new RuntimeException('所有格式下载失败: ' . $url);
    }

    private function extractZip(string $zipPath, string $destDir): void
    {
        $zip = new ZipArchive();
        $code = $zip->open($zipPath);
        if ($code !== true) {
            throw new RuntimeException('无法打开压缩包 (code ' . $code . ')');
        }
        if (!$zip->extractTo($destDir)) {
            $zip->close();
            throw new RuntimeException('解压失败');
        }
        $zip->close();
    }

    private function filenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $name = basename((string) $path);
        return $name !== '' ? $name : 'image.jpg';
    }

    private function findExistingImage(string $dir, string $base): ?string
    {
        foreach (['jpg', 'png', 'jpeg', 'webp'] as $ext) {
            $name = $base . '.' . $ext;
            if (is_file($dir . DIRECTORY_SEPARATOR . $name)) {
                return $name;
            }
        }
        return null;
    }

    private function clearDirectory(string $dir, bool $removeSelf): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->clearDirectory($path, true);
            } else {
                @unlink($path);
            }
        }
        if ($removeSelf) {
            @rmdir($dir);
        }
    }

    private function dirCreatedAt(string $dir): int
    {
        if (!is_dir($dir)) {
            return time();
        }
        $ctime = filectime($dir);
        return $ctime !== false ? $ctime : time();
    }
}
