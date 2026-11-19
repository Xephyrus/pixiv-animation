<?php

declare(strict_types=1);

final class Database
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建数据库目录: ' . $dir);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->migrate();
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS works (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    character TEXT NOT NULL DEFAULT '',
    illust_type INTEGER NOT NULL DEFAULT 0,
    path TEXT NOT NULL DEFAULT '',
    file_count INTEGER NOT NULL DEFAULT 0,
    size INTEGER NOT NULL DEFAULT 0,
    page_count INTEGER NOT NULL DEFAULT 0,
    tags TEXT NOT NULL DEFAULT '[]',
    created_at INTEGER NOT NULL DEFAULT 0,
    modified_at INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS files (
    work_id TEXT NOT NULL,
    filename TEXT NOT NULL,
    size INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (work_id, filename),
    FOREIGN KEY (work_id) REFERENCES works(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_works_modified ON works(modified_at DESC);
CREATE INDEX IF NOT EXISTS idx_works_type ON works(illust_type);
SQL);
        $this->ensureColumn('works', 'meta_json', "TEXT NOT NULL DEFAULT '{}'");
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        $stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
        foreach ($stmt->fetchAll() as $row) {
            if (($row['name'] ?? '') === $column) {
                return;
            }
        }
        $this->pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }

    public function upsertWork(array $work): void
    {
        $now = time();
        $hasMeta = array_key_exists('meta', $work) || array_key_exists('meta_json', $work);
        $metaJson = '{}';
        if (array_key_exists('meta', $work)) {
            $metaJson = json_encode($work['meta'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        } elseif (is_string($work['meta_json'] ?? null)) {
            $metaJson = (string) $work['meta_json'];
        }

        if (!$hasMeta) {
            $existing = $this->getWork((string) $work['id']);
            if ($existing !== null) {
                $metaJson = json_encode($existing['meta'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            }
        }

        $stmt = $this->pdo->prepare(<<<'SQL'
INSERT INTO works (
    id, name, title, character, illust_type, path, file_count, size, page_count, tags, meta_json, created_at, modified_at
) VALUES (
    :id, :name, :title, :character, :illust_type, :path, :file_count, :size, :page_count, :tags, :meta_json, :created_at, :modified_at
)
ON CONFLICT(id) DO UPDATE SET
    name = excluded.name,
    title = excluded.title,
    character = CASE WHEN excluded.character != '' THEN excluded.character ELSE works.character END,
    illust_type = excluded.illust_type,
    path = excluded.path,
    file_count = excluded.file_count,
    size = excluded.size,
    page_count = excluded.page_count,
    tags = excluded.tags,
    meta_json = excluded.meta_json,
    modified_at = excluded.modified_at
SQL);
        $stmt->execute([
            ':id' => $work['id'],
            ':name' => $work['name'] ?? $work['id'],
            ':title' => $work['title'] ?? '',
            ':character' => $work['character'] ?? '',
            ':illust_type' => (int) ($work['illust_type'] ?? 0),
            ':path' => $work['path'] ?? '',
            ':file_count' => (int) ($work['file_count'] ?? 0),
            ':size' => (int) ($work['size'] ?? 0),
            ':page_count' => (int) ($work['page_count'] ?? 0),
            ':tags' => is_string($work['tags'] ?? null)
                ? $work['tags']
                : json_encode($work['tags'] ?? [], JSON_UNESCAPED_UNICODE),
            ':meta_json' => $metaJson,
            ':created_at' => (int) ($work['created_at'] ?? $now),
            ':modified_at' => (int) ($work['modified_at'] ?? $now),
        ]);
    }

    public function replaceFiles(string $workId, array $files): void
    {
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM files WHERE work_id = ?');
            $del->execute([$workId]);
            $ins = $this->pdo->prepare('INSERT INTO files (work_id, filename, size) VALUES (?, ?, ?)');
            foreach ($files as $file) {
                $ins->execute([
                    $workId,
                    $file['filename'],
                    (int) ($file['size'] ?? 0),
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getWork(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM works WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $this->normalizeWork($row);
    }

    /** @return list<array> */
    public function listWorks(array $filter = []): array
    {
        $sql = 'SELECT * FROM works WHERE 1=1';
        $params = [];

        if (isset($filter['illust_type']) && $filter['illust_type'] !== '' && $filter['illust_type'] !== null) {
            $sql .= ' AND illust_type = ?';
            $params[] = (int) $filter['illust_type'];
        }
        if (!empty($filter['search'])) {
            $sql .= ' AND (name LIKE ? OR title LIKE ? OR character LIKE ? OR id LIKE ? OR tags LIKE ?)';
            $q = '%' . $filter['search'] . '%';
            array_push($params, $q, $q, $q, $q, $q);
        }

        $sort = $filter['sort'] ?? 'modified_at';
        $allowed = ['modified_at', 'created_at', 'name', 'title', 'file_count', 'size'];
        if (!in_array($sort, $allowed, true)) {
            $sort = 'modified_at';
        }
        $order = strtolower((string) ($filter['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $sql .= " ORDER BY {$sort} {$order}";

        $page = max(1, (int) ($filter['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($filter['page_size'] ?? 20)));
        $offset = ($page - 1) * $pageSize;
        $sql .= ' LIMIT ' . $pageSize . ' OFFSET ' . $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return array_map([$this, 'normalizeWork'], $rows);
    }

    public function countWorks(array $filter = []): int
    {
        $sql = 'SELECT COUNT(*) FROM works WHERE 1=1';
        $params = [];
        if (isset($filter['illust_type']) && $filter['illust_type'] !== '' && $filter['illust_type'] !== null) {
            $sql .= ' AND illust_type = ?';
            $params[] = (int) $filter['illust_type'];
        }
        if (!empty($filter['search'])) {
            $sql .= ' AND (name LIKE ? OR title LIKE ? OR character LIKE ? OR id LIKE ? OR tags LIKE ?)';
            $q = '%' . $filter['search'] . '%';
            array_push($params, $q, $q, $q, $q, $q);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function setCharacter(string $id, string $character): void
    {
        $stmt = $this->pdo->prepare('UPDATE works SET character = ?, modified_at = ? WHERE id = ?');
        $stmt->execute([$character, time(), $id]);
    }

    public function deleteWork(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM works WHERE id = ?');
        $stmt->execute([$id]);
    }

    /** @return list<string> */
    public function listFiles(string $workId): array
    {
        $stmt = $this->pdo->prepare('SELECT filename FROM files WHERE work_id = ? ORDER BY filename');
        $stmt->execute([$workId]);
        return array_column($stmt->fetchAll(), 'filename');
    }

    private function normalizeWork(array $row): array
    {
        $tags = json_decode((string) $row['tags'], true);
        if (!is_array($tags)) {
            $tags = [];
        }
        $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
        if (!is_array($meta)) {
            $meta = [];
        }

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'title' => (string) $row['title'],
            'character' => (string) $row['character'],
            'illustType' => (int) $row['illust_type'],
            'path' => (string) $row['path'],
            'fileCount' => (int) $row['file_count'],
            'size' => (int) $row['size'],
            'pageCount' => (int) $row['page_count'],
            'tags' => $tags,
            'meta' => $meta,
            'createdAt' => (int) $row['created_at'],
            'modifiedAt' => (int) $row['modified_at'],
        ];
    }
}
