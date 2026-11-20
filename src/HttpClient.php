<?php

declare(strict_types=1);

final class HttpClient
{
    /** @var list<int> */
    private array $allowedPorts;

    private ?int $cachedProxyPort = null;

    public function __construct(
        private readonly Config $config,
        private readonly int $proxyPort = 0,
    ) {
        $this->allowedPorts = $config->allowedProxyPorts();
    }

    public function resolvedProxyPort(): int
    {
        return $this->cachedProxyPort ??= $this->resolveProxyPort($this->proxyPort);
    }

    public function getJson(string $url, string $cookie = ''): array
    {
        $body = $this->request($url, $cookie);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('响应不是有效 JSON: ' . $url);
        }
        return $data;
    }

    /**
     * @param callable(int, int):void|null $onProgress ($downloaded, $totalBytes)
     */
    public function download(string $url, string $savePath, string $cookie = '', ?callable $onProgress = null): void
    {
        $dir = dirname($savePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建目录: ' . $dir);
        }

        $tmp = $savePath . '.part';
        $fp = fopen($tmp, 'wb');
        if ($fp === false) {
            throw new RuntimeException('无法写入临时文件: ' . $tmp);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            throw new RuntimeException('无法初始化 curl');
        }

        $this->configureDownload($ch, $fp, $cookie, $onProgress);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        fclose($fp);

        if ($ok === false || $errno !== 0) {
            @unlink($tmp);
            throw new RuntimeException('下载失败: ' . ($error !== '' ? $error : 'curl error ' . $errno));
        }
        if ($status < 200 || $status >= 300) {
            @unlink($tmp);
            throw new RuntimeException('HTTP ' . $status . ' 下载失败: ' . $url);
        }
        if (!rename($tmp, $savePath)) {
            @unlink($tmp);
            throw new RuntimeException('无法保存文件: ' . $savePath);
        }
    }

    /**
     * 并发下载多个文件。失败项写入 error，调用方可按需重试。
     *
     * @param array<int, array{url:string, path:string, filename?:string}> $tasks 键名会原样保留在返回值中
     * @param callable(int, int, int, array):void|null $onProgress ($index, $downloaded, $totalBytes, $task)
     * @return array<int, array{path:?string, size:int, error:?string}>
     */
    public function downloadMany(array $tasks, string $cookie, int $concurrency, ?callable $onProgress = null): array
    {
        $results = [];
        if ($tasks === []) {
            return $results;
        }

        $concurrency = max(1, $concurrency);
        $queue = array_keys($tasks);
        /** @var array<int, array{ch: \CurlHandle, fp: resource, tmp: string, path: string}> $active */
        $active = [];
        $handleIndex = [];
        $lastEmit = [];

        $multi = curl_multi_init();
        $port = $this->resolvedProxyPort();

        while ($queue !== [] || $active !== []) {
            while (count($active) < $concurrency && $queue !== []) {
                $index = array_shift($queue);
                $task = $tasks[$index];
                $dir = dirname($task['path']);
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    $results[$index] = ['path' => null, 'size' => 0, 'error' => '无法创建目录: ' . $dir];
                    continue;
                }

                $tmp = $task['path'] . '.part';
                $fp = @fopen($tmp, 'wb');
                if ($fp === false) {
                    $results[$index] = ['path' => null, 'size' => 0, 'error' => '无法写入临时文件: ' . $tmp];
                    continue;
                }

                $ch = curl_init($task['url']);
                if ($ch === false) {
                    fclose($fp);
                    @unlink($tmp);
                    $results[$index] = ['path' => null, 'size' => 0, 'error' => '无法初始化 curl'];
                    continue;
                }

                $this->configureDownload($ch, $fp, $cookie, $onProgress === null ? null : static function (int $downloaded, int $totalBytes) use ($index, $task, &$lastEmit, $onProgress): void {
                    $now = microtime(true);
                    if ($now - ($lastEmit[$index] ?? 0.0) < 0.15) {
                        return;
                    }
                    $lastEmit[$index] = $now;
                    $onProgress($index, $downloaded, $totalBytes, $task);
                }, $port);

                curl_multi_add_handle($multi, $ch);
                $active[$index] = ['ch' => $ch, 'fp' => $fp, 'tmp' => $tmp, 'path' => $task['path']];
                $handleIndex[spl_object_id($ch)] = $index;
            }

            if ($active === []) {
                break;
            }

            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            if ($running > 0) {
                curl_multi_select($multi, 0.2);
            }

            while (($info = curl_multi_info_read($multi)) !== false) {
                $ch = $info['handle'];
                $key = spl_object_id($ch);
                $index = $handleIndex[$key] ?? null;
                curl_multi_remove_handle($multi, $ch);
                if ($index === null) {
                    unset($ch);
                    continue;
                }

                $entry = $active[$index];
                $errno = (int) $info['result'];
                $error = curl_error($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                unset($ch, $handleIndex[$key]);

                fclose($entry['fp']);
                unset($active[$index]);

                if ($errno !== 0 || $httpCode < 200 || $httpCode >= 300) {
                    @unlink($entry['tmp']);
                    $results[$index] = [
                        'path' => null,
                        'size' => 0,
                        'error' => $errno !== 0 ? ('curl error ' . $errno . ($error !== '' ? ' ' . $error : '')) : ('HTTP ' . $httpCode),
                    ];
                    continue;
                }

                $size = (int) (filesize($entry['tmp']) ?: 0);
                if (!rename($entry['tmp'], $entry['path'])) {
                    @unlink($entry['tmp']);
                    $results[$index] = ['path' => null, 'size' => 0, 'error' => '无法保存文件: ' . $entry['path']];
                    continue;
                }
                $results[$index] = ['path' => $entry['path'], 'size' => $size, 'error' => null];
            }
        }

        unset($multi);

        return $results;
    }

    /**
     * @param \CurlHandle|resource $ch
     * @param resource $fp
     * @param callable(int, int):void|null $onProgress
     * @param int|null $port 已解析的代理端口，避免每个文件都探测一次
     */
    private function configureDownload($ch, $fp, string $cookie, ?callable $onProgress, ?int $port = null): void
    {
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://www.pixiv.net/',
            'Accept: */*',
        ];
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->config->timeoutSec,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROXY => $this->config->proxyHost,
            CURLOPT_PROXYPORT => $port ?? $this->resolvedProxyPort(),
            CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
            CURLOPT_FAILONERROR => false,
        ]);

        if ($onProgress !== null) {
            curl_setopt($ch, CURLOPT_NOPROGRESS, false);
            curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static function ($ch, int $downloadTotal, int $downloaded) use ($onProgress): int {
                $onProgress($downloaded, $downloadTotal);
                return 0;
            });
        }

        $this->applySslOptions($ch);
    }

    private function request(string $url, string $cookie = ''): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('无法初始化 curl');
        }

        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://www.pixiv.net/',
            'Accept: application/json',
        ];
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }

        $port = $this->resolveProxyPort($this->proxyPort);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->config->timeoutSec,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROXY => $this->config->proxyHost,
            CURLOPT_PROXYPORT => $port,
            CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
        ]);
        $this->applySslOptions($ch);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($body === false || $errno !== 0) {
            throw new RuntimeException('请求失败: ' . ($error !== '' ? $error : 'curl error ' . $errno));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(self::describeHttpError($status, $body));
        }
        return $body;
    }

    private static function describeHttpError(int $status, string $body): string
    {
        $message = 'Pixiv 返回 HTTP ' . $status;
        $decoded = json_decode($body, true);
        if (is_array($decoded) && is_string($decoded['message'] ?? null) && trim($decoded['message']) !== '') {
            $message .= '：' . trim($decoded['message']);
        }
        if ($status === 404) {
            $message .= '（作品不存在，或需要登录 Cookie；Cookie 缺 HttpOnly 的 PHPSESSID 时会这样，document.cookie 读不到它）';
        }
        return $message;
    }

    /** @param \CurlHandle|resource $ch */
    private function applySslOptions($ch): void
    {
        if (!$this->config->sslVerify) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            return;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if ($this->config->caFile !== '' && is_file($this->config->caFile)) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->config->caFile);
        }
    }

    private function resolveProxyPort(int $want): int
    {
        if ($want !== 0) {
            if (!in_array($want, $this->allowedPorts, true)) {
                throw new RuntimeException('不支持的代理端口 ' . $want . '（可选 7897 / 7890）');
            }
            if (!$this->proxyReachable($want)) {
                throw new RuntimeException(sprintf('代理 %s:%d 不可用', $this->config->proxyHost, $want));
            }
            return $want;
        }

        $tried = [];
        $candidates = array_values(array_unique(array_merge([$this->config->proxyPort], $this->allowedPorts)));
        foreach ($candidates as $port) {
            if ($port <= 0 || !in_array($port, $this->allowedPorts, true)) {
                continue;
            }
            $tried[] = $port;
            if ($this->proxyReachable($port)) {
                return $port;
            }
        }
        throw new RuntimeException('未检测到可用代理（已试 ' . implode(', ', $tried) . '）');
    }

    private function proxyReachable(int $port): bool
    {
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($this->config->proxyHost, $port, $errno, $errstr, 0.4);
        if ($fp === false) {
            return false;
        }
        fclose($fp);
        return true;
    }
}
