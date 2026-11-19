<?php

declare(strict_types=1);

final class Config
{
    public string $host;
    public int $port;
    public string $proxyHost;
    public int $proxyPort;
    public int $delayMs;
    public int $timeoutSec;
    public string $downloadDir;
    public string $ugoiraDir;
    public string $dbPath;
    public string $caFile;
    public bool $sslVerify;
    public string $rootDir;

    public static function load(string $rootDir): self
    {
        $envFile = $rootDir . DIRECTORY_SEPARATOR . 'config.env';
        if (is_file($envFile)) {
            self::loadEnvFile($envFile);
        }

        $cfg = new self();
        $cfg->rootDir = $rootDir;
        $cfg->host = self::env('HOST', '0.0.0.0');
        $cfg->port = (int) self::env('PORT', '5400');
        $cfg->proxyHost = self::env('PROXY_HOST', '127.0.0.1');
        $cfg->proxyPort = (int) self::env('PROXY_PORT', '7897');
        $cfg->delayMs = (int) self::env('DOWNLOAD_DELAY_MS', '1000');
        $cfg->timeoutSec = (int) self::env('DOWNLOAD_TIMEOUT_SEC', '60');
        $cfg->downloadDir = self::resolvePath($rootDir, self::env('DOWNLOAD_DIR', './downloads'));
        $cfg->ugoiraDir = self::resolvePath($rootDir, self::env('UGOIRA_DIR', './downloads'));
        $cfg->dbPath = self::resolvePath($rootDir, self::env('DB_PATH', './data/pixiv.db'));
        $cfg->caFile = self::resolveCaFile($rootDir, self::env('CURL_CA_FILE', './data/cacert.pem'));
        $cfg->sslVerify = !in_array(strtolower(self::env('SSL_VERIFY', '1')), ['0', 'false', 'off', 'no'], true);
        return $cfg;
    }

    private static function resolveCaFile(string $rootDir, string $path): string
    {
        $resolved = self::resolvePath($rootDir, $path);
        if (is_file($resolved)) {
            return $resolved;
        }
        $iniCa = (string) (ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: '');
        if ($iniCa !== '' && is_file($iniCa)) {
            return $iniCa;
        }
        return $resolved;
    }

    /** @return list<int> */
    public function allowedProxyPorts(): array
    {
        return [7897, 7890];
    }

    private static function env(string $key, string $fallback): string
    {
        $v = getenv($key);
        if ($v === false || $v === '') {
            return $fallback;
        }
        return $v;
    }

    private static function resolvePath(string $root, string $path): string
    {
        if ($path === '.' || $path === './') {
            return $root;
        }
        if (preg_match('#^[A-Za-z]:[\\\\/]#', $path) || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        }
        $path = preg_replace('#^\./#', '', $path) ?? $path;
        return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private static function loadEnvFile(string $file): void
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }
}
