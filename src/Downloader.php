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

    /**
     * @param callable(array):void|null $emit 进度事件回调，传 null 时只返回最终结果
     */
    public function downloadById(
        string $id,
        string $cookie,
        bool $redownload = false,
        string $character = '',
        ?callable $emit = null
    ): array {
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
            0, 1 => $this->downloadStill($id, $body, $cookie, $redownload, $emit),
            2 => $this->downloadUgoira($id, $body, $cookie, $redownload, $character, $emit),
            default => throw new RuntimeException('未知的 illustType: ' . $type),
        };
    }

    private function downloadStill(string $id, array $body, string $cookie, bool $redownload, ?callable $emit = null): array
    {
        $urls = PixivApi::buildOriginalUrls($body);
        $taskDir = $this->config->downloadDir . DIRECTORY_SEPARATOR . $id;
        $title = (string) ($body['title'] ?? $id);
        $illustType = (int) ($body['illustType'] ?? 0);
        $total = count($urls);

        if ($redownload && is_dir($taskDir)) {
            $this->clearDirectory($taskDir, false);
        }
        if (!is_dir($taskDir) && !mkdir($taskDir, 0755, true) && !is_dir($taskDir)) {
            throw new RuntimeException('无法创建下载目录: ' . $taskDir);
        }

        $this->emit($emit, [
            'type' => 'start',
            'id' => $id,
            'title' => $title,
            'illustType' => $illustType,
            'total' => $total,
        ]);

        $logs = [];
        $files = [];
        $success = 0;
        $fail = 0;
        $totalSize = 0;
        /** @var array<int, array{url:string, path:string, filename:string}> $pending */
        $pending = [];

        foreach ($urls as $i => $url) {
            $filename = $this->filenameFromUrl($url);
            $savePath = $taskDir . DIRECTORY_SEPARATOR . $filename;
            $base = pathinfo($filename, PATHINFO_FILENAME);

            $existing = $this->findExistingImage($taskDir, $base);
            if ($existing !== null && !$redownload) {
                $size = (int) filesize($taskDir . DIRECTORY_SEPARATOR . $existing);
                $files[$i] = ['filename' => $existing, 'size' => $size];
                $totalSize += $size;
                $success++;
                $logs[] = ['type' => 'skipped', 'filename' => $existing, 'current' => $i + 1, 'total' => $total];
                $this->emit($emit, [
                    'type' => 'file',
                    'status' => 'skipped',
                    'filename' => $existing,
                    'index' => $i + 1,
                    'total' => $total,
                ]);
                continue;
            }

            $pending[$i] = ['url' => $url, 'path' => $savePath, 'filename' => $filename];
            $logs[] = ['type' => 'start', 'filename' => $filename, 'url' => $url, 'current' => $i + 1, 'total' => $total];
            $this->emit($emit, [
                'type' => 'file',
                'status' => 'start',
                'filename' => $filename,
                'index' => $i + 1,
                'total' => $total,
            ]);
        }

        if ($pending !== []) {
            $results = $this->downloadBatches($pending, $cookie, $total, $emit);

            $retry = [];
            foreach ($results as $i => $result) {
                if ($result['error'] !== null) {
                    $task = $pending[$i];
                    $ext = strtolower(pathinfo($task['path'], PATHINFO_EXTENSION));
                    $altExt = $ext === 'png' ? 'jpg' : 'png';
                    $retry[$i] = [
                        'url' => preg_replace('/\.(jpg|png)(\?|$)/i', '.' . $altExt . '$2', $task['url']) ?? $task['url'],
                        'path' => preg_replace('/\.[^.]+$/', '.' . $altExt, $task['path']) ?? $task['path'],
                        'filename' => preg_replace('/\.[^.]+$/', '.' . $altExt, $task['filename']) ?? $task['filename'],
                    ];
                }
            }
            if ($retry !== []) {
                foreach ($this->downloadBatches($retry, $cookie, $total, $emit) as $i => $result) {
                    $results[$i] = $result;
                }
            }

            ksort($results);
            foreach ($results as $i => $result) {
                $index = $i + 1;
                if ($result['error'] !== null) {
                    $fail++;
                    $filename = $pending[$i]['filename'];
                    $logs[] = ['type' => 'error', 'filename' => $filename, 'error' => $result['error'], 'current' => $index, 'total' => $total];
                    $this->emit($emit, [
                        'type' => 'file',
                        'status' => 'error',
                        'filename' => $filename,
                        'index' => $index,
                        'total' => $total,
                        'error' => $result['error'],
                    ]);
                    continue;
                }

                $finalName = basename((string) $result['path']);
                $size = (int) $result['size'];
                $files[$i] = ['filename' => $finalName, 'size' => $size];
                $totalSize += $size;
                $success++;
                $logs[] = ['type' => 'success', 'filename' => $finalName, 'current' => $index, 'total' => $total];
                $this->emit($emit, [
                    'type' => 'file',
                    'status' => 'success',
                    'filename' => $finalName,
                    'index' => $index,
                    'total' => $total,
                    'size' => $size,
                ]);
            }
        }

        ksort($files);
        $files = array_values($files);

        $tags = PixivApi::extractTags($body);
        $this->persistWork([
            'id' => $id,
            'name' => $id,
            'title' => $title,
            'character' => '',
            'illust_type' => $illustType,
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
            'illustType' => $illustType,
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

    private function downloadUgoira(string $id, array $body, string $cookie, bool $redownload, string $character, ?callable $emit = null): array
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

        $this->emit($emit, [
            'type' => 'start',
            'id' => $id,
            'title' => (string) ($body['title'] ?? $id),
            'illustType' => 2,
            'total' => 1,
        ]);

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
        $this->emit($emit, ['type' => 'info', 'message' => '使用代理 ' . $this->config->proxyHost . ':' . $this->http->resolvedProxyPort()]);
        $logs[] = ['type' => 'info', 'message' => '使用代理 ' . $this->config->proxyHost . ':' . $this->http->resolvedProxyPort()];
        $this->emit($emit, [
            'type' => 'file',
            'status' => 'start',
            'filename' => basename($zipPath),
            'index' => 1,
            'total' => 1,
        ]);
        $logs[] = ['type' => 'start', 'filename' => basename($zipPath), 'url' => $originalSrc];

        $lastEmit = 0.0;
        $this->http->download($originalSrc, $zipPath, $cookie, $emit === null ? null : function (int $downloaded, int $totalBytes) use ($emit, $folderName, &$lastEmit): void {
            $now = microtime(true);
            if ($now - $lastEmit < 0.15) {
                return;
            }
            $lastEmit = $now;
            $this->emit($emit, [
                'type' => 'progress',
                'filename' => $folderName . '.zip',
                'index' => 1,
                'total' => 1,
                'bytes' => $downloaded,
                'totalBytes' => $totalBytes,
            ]);
        });
        $this->emit($emit, [
            'type' => 'file',
            'status' => 'success',
            'filename' => basename($zipPath),
            'index' => 1,
            'total' => 1,
            'size' => (int) (is_file($zipPath) ? filesize($zipPath) : 0),
        ]);
        $logs[] = ['type' => 'success', 'filename' => basename($zipPath), 'message' => '压缩包下载完成'];

        $this->extractZip($zipPath, $taskDir);
        $this->emit($emit, ['type' => 'info', 'message' => '解压完成']);
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

    /**
     * 按并发数分批下载，批内并行。
     *
     * @param array<int, array{url:string, path:string, filename:string}> $tasks
     * @return array<int, array{path:?string, size:int, error:?string}>
     */
    private function downloadBatches(array $tasks, string $cookie, int $total, ?callable $emit = null): array
    {
        $concurrency = max(1, $this->config->concurrency);
        $results = [];

        foreach (array_chunk($tasks, $concurrency, true) as $batchIndex => $batch) {
            if ($batchIndex > 0 && $concurrency === 1 && $this->config->delayMs > 0) {
                usleep($this->config->delayMs * 1000);
            }

            $onProgress = $emit === null ? null : function (int $index, int $downloaded, int $totalBytes, array $task) use ($emit, $total): void {
                $this->emit($emit, [
                    'type' => 'progress',
                    'filename' => (string) ($task['filename'] ?? ''),
                    'index' => $index + 1,
                    'total' => $total,
                    'bytes' => $downloaded,
                    'totalBytes' => $totalBytes,
                ]);
            };

            foreach ($this->http->downloadMany($batch, $cookie, $concurrency, $onProgress) as $index => $result) {
                $results[$index] = $result;
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $event */
    private function emit(?callable $emit, array $event): void
    {
        if ($emit !== null) {
            $emit($event);
        }
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
