<?php

declare(strict_types=1);

final class PixivApi
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    public function fetchIllust(string $id, string $cookie = ''): array
    {
        $id = self::normalizeId($id);
        $url = 'https://www.pixiv.net/ajax/illust/' . rawurlencode($id) . '?lang=zh';
        $data = $this->http->getJson($url, $cookie);
        if (!empty($data['error'])) {
            throw new RuntimeException((string) ($data['message'] ?: 'Pixiv illust 接口错误'));
        }
        return $data;
    }

    public function fetchUgoiraMeta(string $id, string $cookie = ''): array
    {
        $id = self::normalizeId($id);
        $url = 'https://www.pixiv.net/ajax/illust/' . rawurlencode($id) . '/ugoira_meta?lang=zh';
        $data = $this->http->getJson($url, $cookie);
        if (!empty($data['error'])) {
            throw new RuntimeException((string) ($data['message'] ?: 'Pixiv ugoira_meta 接口错误'));
        }
        return $data;
    }

    public static function normalizeId(string $id): string
    {
        $id = trim($id);
        if (preg_match('#(?:pixiv\.net/(?:artworks|i)/|illust_id=)(\d+)#i', $id, $m)) {
            return $m[1];
        }
        if (preg_match('#^(\d+)$#', $id, $m)) {
            return $m[1];
        }
        throw new InvalidArgumentException('无效的作品 ID');
    }

    /** @return list<string> */
    public static function extractTags(array $illustBody): array
    {
        $tags = [];
        $list = $illustBody['tags']['tags'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $tag = trim((string) ($item['tag'] ?? ''));
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }
        return array_values(array_unique($tags));
    }

    /** Build original image URL list from illust body (type 0 / 1). */
    public static function buildOriginalUrls(array $illustBody): array
    {
        $original = (string) ($illustBody['urls']['original'] ?? '');
        if ($original === '') {
            throw new RuntimeException('作品缺少原图 URL');
        }
        $pageCount = max(1, (int) ($illustBody['pageCount'] ?? 1));
        return self::expandPagedUrls($original, $pageCount);
    }

    /** @return list<string> */
    public static function expandPagedUrls(string $sample, int $count): array
    {
        $sample = trim($sample);
        if ($sample === '') {
            throw new InvalidArgumentException('请输入示例图片 URL');
        }
        if (preg_match('/\r|\n/', $sample)) {
            $sample = trim(explode("\n", str_replace("\r", "\n", $sample))[0]);
        }

        $lower = strtolower($sample);
        $idx = strrpos($lower, '_p');
        if ($idx === false) {
            throw new InvalidArgumentException('URL 格式不正确，应为 ..._p0.jpg 格式');
        }
        $rest = substr($sample, $idx + 2);
        $dot = strpos($rest, '.');
        if ($dot === false) {
            throw new InvalidArgumentException('URL 格式不正确，应为 ..._p0.jpg 格式');
        }
        $base = substr($sample, 0, $idx + 2);
        $ext = substr($rest, $dot + 1);
        if (($q = strcspn($ext, '?#')) < strlen($ext)) {
            $ext = substr($ext, 0, $q);
        }

        $urls = [];
        for ($i = 0; $i < $count; $i++) {
            $urls[] = $base . $i . '.' . $ext;
        }
        return $urls;
    }
}
