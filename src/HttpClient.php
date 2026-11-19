<?php

declare(strict_types=1);

final class HttpClient
{
    /** @var list<int> */
    private array $allowedPorts;

    public function __construct(
        private readonly Config $config,
        private readonly int $proxyPort = 0,
    ) {
        $this->allowedPorts = $config->allowedProxyPorts();
    }

    public function resolvedProxyPort(): int
    {
        return $this->resolveProxyPort($this->proxyPort);
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

    public function download(string $url, string $savePath, string $cookie = ''): void
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

        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://www.pixiv.net/',
            'Accept: */*',
        ];
        if ($cookie !== '') {
            $headers[] = 'Cookie: ' . $cookie;
        }

        $port = $this->resolveProxyPort($this->proxyPort);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $this->config->timeoutSec,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROXY => $this->config->proxyHost,
            CURLOPT_PROXYPORT => $port,
            CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
            CURLOPT_FAILONERROR => false,
        ]);
        $this->applySslOptions($ch);

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
            throw new RuntimeException('Pixiv 返回 HTTP ' . $status);
        }
        return $body;
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
