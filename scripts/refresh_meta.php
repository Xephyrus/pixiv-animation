<?php

declare(strict_types=1);

/**
 * 重新抓取动图作品的 ugoira_meta，并只更新 works.meta_json。
 *
 * 用法：
 *   php scripts/refresh_meta.php [--dry-run] [--cookie-file=path] [--only=id1,id2] [--proxy-port=7897]
 *
 * 只写 meta_json 一列，不改动 name / title / character / path / size / page_count / tags /
 * created_at / modified_at 等其它字段。
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

$options = parse_options(array_slice($argv, 1));
$dryRun = isset($options['dry-run']);
$cookieFile = $options['cookie-file'] ?? (app_root() . '/data/.pixiv-cookie.tmp');
$only = [];
if (isset($options['only']) && $options['only'] !== '') {
    $only = array_values(array_filter(array_map('trim', explode(',', (string) $options['only']))));
}
$proxyPort = isset($options['proxy-port']) ? (int) $options['proxy-port'] : 0;

$cookie = '';
if (is_file($cookieFile)) {
    $cookie = trim((string) file_get_contents($cookieFile));
}
if ($cookie === '') {
    fwrite(STDERR, "缺少 Cookie：未在 {$cookieFile} 找到内容\n");
    exit(2);
}

$config = app_config();
$db = app_db();
$http = new HttpClient($config, $proxyPort);
$api = new PixivApi($http);

$ids = array_map(
    static fn(array $row): string => (string) $row['id'],
    $db->pdo()->query('SELECT id FROM works WHERE illust_type = 2 ORDER BY id')->fetchAll()
);
if ($only !== []) {
    $ids = array_values(array_intersect($ids, $only));
}
if ($ids === []) {
    fwrite(STDERR, "没有需要处理的作品\n");
    exit(0);
}

$update = $db->pdo()->prepare('UPDATE works SET meta_json = :meta WHERE id = :id');

$report = [
    'startedAt' => date('c'),
    'dryRun' => $dryRun,
    'cookieFile' => $cookieFile,
    'items' => [],
];

printf("待处理 %d 个动图作品（%s）\n", count($ids), $dryRun ? 'dry-run' : '写入');

$backupPath = '';
if (!$dryRun) {
    $backupPath = $config->dbPath . '.bak-' . date('Ymd-His');
    if (!copy($config->dbPath, $backupPath)) {
        fwrite(STDERR, "无法备份数据库到 {$backupPath}，已中止\n");
        exit(2);
    }
    echo '数据库备份：' . $backupPath . "\n";
}

$ok = 0;
$failed = 0;
$changed = 0;
$warnings = 0;

$written = [];

foreach ($ids as $index => $id) {
    if ($index > 0 && $config->delayMs > 0) {
        usleep($config->delayMs * 1000);
    }

    $work = $db->getWork($id);
    if ($work === null) {
        continue;
    }

    $entry = [
        'id' => $id,
        'status' => 'ok',
        'issues' => [],
    ];

    try {
        $response = $api->fetchUgoiraMeta($id, $cookie);
        $body = $response['body'] ?? null;
        if (!is_array($body)) {
            throw new RuntimeException('ugoira_meta 响应缺少 body');
        }

        $meta = [
            'src' => (string) ($body['src'] ?? ''),
            'originalSrc' => (string) ($body['originalSrc'] ?? ''),
            'mime_type' => (string) ($body['mime_type'] ?? 'image/jpeg'),
            'frames' => is_array($body['frames'] ?? null) ? array_values($body['frames']) : [],
        ];

        if ($meta['src'] === '' && $meta['originalSrc'] === '') {
            throw new RuntimeException('ugoira_meta 缺少 src / originalSrc');
        }
        if ($meta['frames'] === []) {
            throw new RuntimeException('ugoira_meta 没有 frames');
        }

        $metaJson = (string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 与目录/旧数据比对，只报告，不据此修改其它字段
        $dir = (string) $work['path'];
        $localFiles = [];
        if ($dir !== '' && is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $name) {
                if (preg_match('/^\d+\.(?:jpe?g|png|gif|webp)$/i', $name) && is_file($dir . DIRECTORY_SEPARATOR . $name)) {
                    $localFiles[$name] = true;
                }
            }
        } else {
            $entry['issues'][] = '本地目录不存在: ' . $dir;
            $warnings++;
        }

        $missing = [];
        $delays = [];
        foreach ($meta['frames'] as $frame) {
            $file = (string) ($frame['file'] ?? '');
            $delays[] = (int) ($frame['delay'] ?? 0);
            if ($file !== '' && $localFiles !== [] && !isset($localFiles[$file])) {
                $missing[] = $file;
            }
        }
        if ($missing !== []) {
            $entry['issues'][] = sprintf('本地缺少 %d 帧（例：%s）', count($missing), implode(', ', array_slice($missing, 0, 3)));
            $warnings++;
        }
        if (count($meta['frames']) !== (int) $work['pageCount']) {
            $entry['issues'][] = sprintf('帧数 %d 与 page_count %d 不一致', count($meta['frames']), (int) $work['pageCount']);
            $warnings++;
        }

        $oldMeta = is_array($work['meta'] ?? null) ? $work['meta'] : [];
        $oldFrames = is_array($oldMeta['frames'] ?? null) ? $oldMeta['frames'] : [];
        $oldDelays = array_map(static fn($f): int => (int) (is_array($f) ? ($f['delay'] ?? 0) : 0), $oldFrames);
        $oldJson = (string) json_encode($oldMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $entry['old'] = [
            'bytes' => strlen($oldJson),
            'keys' => array_keys($oldMeta),
            'frameCount' => count($oldFrames),
            'distinctDelays' => count(array_unique($oldDelays)),
            'delaysSample' => array_slice($oldDelays, 0, 8),
            'src' => (string) ($oldMeta['src'] ?? ''),
            'originalSrc' => (string) ($oldMeta['originalSrc'] ?? ''),
            'mime_type' => (string) ($oldMeta['mime_type'] ?? ''),
        ];
        $entry['new'] = [
            'bytes' => strlen($metaJson),
            'keys' => array_keys($meta),
            'frameCount' => count($meta['frames']),
            'distinctDelays' => count(array_unique($delays)),
            'delaysSample' => array_slice($delays, 0, 8),
            'totalDurationMs' => array_sum($delays),
            'src' => $meta['src'],
            'originalSrc' => $meta['originalSrc'],
            'mime_type' => $meta['mime_type'],
        ];

        $same = $oldJson === $metaJson;
        $entry['changed'] = !$same;

        if ($same) {
            $entry['status'] = 'unchanged';
        } else {
            $changed++;
            if (!$dryRun) {
                $update->execute([':meta' => $metaJson, ':id' => $id]);
                $written[$id] = $metaJson;
            }
        }
        $ok++;
    } catch (Throwable $e) {
        $entry['status'] = 'error';
        $entry['error'] = $e->getMessage();
        $failed++;
    }

    $report['items'][] = $entry;

    $line = sprintf(
        '%-12s %-10s frames=%s changed=%s',
        $id,
        $entry['status'],
        isset($entry['new']) ? (string) $entry['new']['frameCount'] : '-',
        isset($entry['changed']) ? ($entry['changed'] ? 'yes' : 'no') : '-'
    );
    if (($entry['issues'] ?? []) !== []) {
        $line .= ' | ' . implode('; ', $entry['issues']);
    }
    if (($entry['error'] ?? '') !== '') {
        $line .= ' | ' . $entry['error'];
    }
    echo $line . "\n";
}

$verifyMismatch = 0;
if ($written !== []) {
    $select = $db->pdo()->prepare('SELECT meta_json FROM works WHERE id = ?');
    foreach ($written as $id => $expected) {
        $select->execute([$id]);
        $actual = (string) $select->fetchColumn();
        if ($actual !== $expected) {
            $verifyMismatch++;
            echo "校验失败：{$id} 写入值与库内值不一致\n";
        }
    }
}

$report['finishedAt'] = date('c');
$report['summary'] = [
    'total' => count($ids),
    'ok' => $ok,
    'failed' => $failed,
    'changed' => $changed,
    'warnings' => $warnings,
];
$report['verification'] = [
    'backup' => $backupPath,
    'checked' => count($written),
    'mismatch' => $verifyMismatch,
];

$reportPath = app_root() . '/data/meta-refresh-' . date('Ymd-His') . '.json';
$reportJson = (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (@file_put_contents($reportPath, $reportJson) === false) {
    $reportPath = app_root() . '/meta-refresh-' . date('Ymd-His') . '.json';
    if (@file_put_contents($reportPath, $reportJson) === false) {
        $reportPath = '(报告写入失败)';
    }
}

printf(
    "\n完成：总计 %d，成功 %d，失败 %d，需更新 %d，警告 %d，校验不一致 %d\n报告：%s\n",
    count($ids),
    $ok,
    $failed,
    $changed,
    $warnings,
    $verifyMismatch,
    $reportPath
);

exit(($failed > 0 || $verifyMismatch > 0) ? 1 : 0);

/** @return array<string,string|true> */
function parse_options(array $args): array
{
    $options = [];
    foreach ($args as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $options[$key] = $value;
            continue;
        }
        $options[$arg] = true;
    }
    return $options;
}
