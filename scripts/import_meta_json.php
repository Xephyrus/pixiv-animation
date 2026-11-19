<?php

declare(strict_types=1);

/**
 * 从浏览器里复制出来的 ugoira_meta 响应写入 works.meta_json（只改这一列）。
 *
 * 用法：
 *   php scripts/import_meta_json.php <文件.json> [更多文件.json ...] [--dry-run]
 *
 * 每个文件可以是下面任意一种形式：
 *   1) 完整接口响应：{"error":false,"message":"","body":{"src":...}}
 *   2) 只含 body：{"body":{"src":...}}
 *   3) 只含 body 的内容：{"src":...,"originalSrc":...,"mime_type":...,"frames":[...]}
 *   4) 作品 ID → 上面任意一种 的映射：{"151545941":{"src":...}, "151992301":{...}}
 *
 * 作品 ID 优先取映射的键，其次取文件名里的数字，最后取 JSON 里的 illustId / id。
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

$args = array_slice($argv, 1);
$dryRun = false;
$files = [];
foreach ($args as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    $files[] = $arg;
}

if ($files === []) {
    fwrite(STDERR, "用法: php scripts/import_meta_json.php <文件.json> [...] [--dry-run]\n");
    exit(2);
}

$db = app_db();
$select = $db->pdo()->prepare('SELECT meta_json FROM works WHERE id = ?');
$update = $db->pdo()->prepare('UPDATE works SET meta_json = :meta WHERE id = :id');

$backupPath = '';
if (!$dryRun) {
    $backupPath = app_config()->dbPath . '.bak-' . date('Ymd-His');
    if (!copy(app_config()->dbPath, $backupPath)) {
        fwrite(STDERR, "无法备份数据库到 {$backupPath}，已中止\n");
        exit(2);
    }
    echo '数据库备份：' . $backupPath . "\n";
}

$report = [];
$ok = 0;
$failed = 0;
$changed = 0;

/** @return array{id:string,body:array}|null */
function pick_candidate(string $id, mixed $payload): ?array
{
    if (!is_array($payload)) {
        return null;
    }
    if (isset($payload['body']) && is_array($payload['body'])) {
        $payload = $payload['body'];
    }
    if (!isset($payload['frames']) || !is_array($payload['frames'])) {
        return null;
    }
    $id = $id !== '' ? $id : (string) ($payload['illustId'] ?? $payload['id'] ?? '');
    return ['id' => $id, 'body' => $payload];
}

foreach ($files as $file) {
    if (!is_file($file)) {
        echo "跳过（文件不存在）：{$file}\n";
        $failed++;
        continue;
    }
    $raw = (string) file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo "跳过（不是有效 JSON）：{$file}\n";
        $failed++;
        continue;
    }

    $fileId = '';
    if (preg_match('/(\d{5,})/', basename($file), $m)) {
        $fileId = $m[1];
    }

    $candidates = [];
    $direct = pick_candidate($fileId, $data);
    if ($direct !== null) {
        $candidates[] = $direct;
    } else {
        foreach ($data as $key => $value) {
            $cand = pick_candidate((string) $key, $value);
            if ($cand !== null) {
                $candidates[] = $cand;
            }
        }
    }

    if ($candidates === []) {
        echo "跳过（没有找到 src/frames 结构）：{$file}\n";
        $failed++;
        continue;
    }

    foreach ($candidates as $candidate) {
        $id = $candidate['id'];
        $body = $candidate['body'];
        if ($id === '') {
            echo "跳过（无法确定作品 ID）：{$file}\n";
            $failed++;
            continue;
        }

        $meta = [
            'src' => (string) ($body['src'] ?? ''),
            'originalSrc' => (string) ($body['originalSrc'] ?? ''),
            'mime_type' => (string) ($body['mime_type'] ?? 'image/jpeg'),
            'frames' => array_values($body['frames']),
        ];

        $issues = [];
        if ($meta['src'] === '' && $meta['originalSrc'] === '') {
            $issues[] = '缺少 src / originalSrc';
        }
        if ($meta['frames'] === []) {
            $issues[] = 'frames 为空';
        }
        foreach ($meta['frames'] as $frame) {
            if (!is_array($frame) || !isset($frame['file'])) {
                $issues[] = 'frames 元素格式不对';
                break;
            }
        }

        $work = $db->getWork($id);
        if ($work === null) {
            $issues[] = '数据库里没有这个作品';
        }

        $metaJson = (string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $entry = [
            'id' => $id,
            'file' => $file,
            'frameCount' => count($meta['frames']),
            'issues' => $issues,
            'status' => 'ok',
        ];

        if ($work !== null && $issues === []) {
            $dir = (string) $work['path'];
            $local = [];
            if ($dir !== '' && is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $name) {
                    if (preg_match('/^\d+\.(?:jpe?g|png|gif|webp)$/i', $name) && is_file($dir . DIRECTORY_SEPARATOR . $name)) {
                        $local[$name] = true;
                    }
                }
            }
            $missing = [];
            $delays = [];
            foreach ($meta['frames'] as $frame) {
                $delays[] = (int) ($frame['delay'] ?? 0);
                $name = (string) ($frame['file'] ?? '');
                if ($name !== '' && $local !== [] && !isset($local[$name])) {
                    $missing[] = $name;
                }
            }
            if ($missing !== []) {
                $entry['issues'][] = sprintf('本地缺少 %d 帧', count($missing));
            }
            if (count($meta['frames']) !== (int) $work['pageCount']) {
                $entry['issues'][] = sprintf('帧数 %d 与 page_count %d 不一致', count($meta['frames']), (int) $work['pageCount']);
            }
            $entry['totalDurationMs'] = array_sum($delays);
            $entry['distinctDelays'] = count(array_unique($delays));

            $oldJson = (string) ($select->execute([$id]) ? $select->fetchColumn() : '');
            $entry['changed'] = $oldJson !== $metaJson;

            if ($entry['changed']) {
                $changed++;
                if (!$dryRun) {
                    $update->execute([':meta' => $metaJson, ':id' => $id]);
                }
            }
        } else {
            $entry['status'] = 'error';
            $failed++;
        }

        $report[] = $entry;
        printf(
            "%-10s %-6s frames=%-4s duration=%-6s changed=%-3s %s%s\n",
            $id,
            $entry['status'],
            (string) $entry['frameCount'],
            isset($entry['totalDurationMs']) ? (string) $entry['totalDurationMs'] : '-',
            isset($entry['changed']) ? ($entry['changed'] ? 'yes' : 'no') : '-',
            $report[count($report) - 1]['issues'] === [] ? '' : ('| ' . implode('; ', $entry['issues'])),
            ''
        );
        if ($entry['status'] === 'ok') {
            $ok++;
        }
    }
}

$reportPath = app_root() . '/data/meta-import-' . date('Ymd-His') . '.json';
$out = (string) json_encode(['backup' => $backupPath, 'dryRun' => $dryRun, 'items' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (@file_put_contents($reportPath, $out) === false) {
    $reportPath = app_root() . '/meta-import-' . date('Ymd-His') . '.json';
    if (@file_put_contents($reportPath, $out) === false) {
        $reportPath = '(报告写入失败)';
    }
}

printf("\n完成：成功 %d，失败 %d，需更新 %d\n报告：%s\n", $ok, $failed, $changed, $reportPath);
exit($failed > 0 ? 1 : 0);
