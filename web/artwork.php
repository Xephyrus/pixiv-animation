<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $id = PixivApi::normalizeId((string) ($_GET['id'] ?? ''));
} catch (InvalidArgumentException) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '无效的作品 ID';
    return;
}

$work = app_db()->getWork($id);
if ($work === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '作品不存在';
    return;
}

$files = app_db()->listFiles($id);
$frameBase = work_frame_base($work);
$title = (string) ($work['title'] ?: $work['name'] ?: $id);
$typeLabel = match ((int) $work['illustType']) {
    2 => '动图',
    1 => '漫画',
    default => '图片',
};
$metaText = sprintf(
    'ID %s · %s · %d 张 · 点击缩略图查看大图（缩放 / 旋转 / 全屏 / 左右切换）',
    $id,
    $typeLabel,
    count($files)
);

$pageTitle = $title . ' · Pixiv Animation';
$navActive = 'home';

ob_start();
?>
<header class="gallery-heading">
    <div>
        <p class="gallery-kicker"><i class="fa-solid fa-image" aria-hidden="true"></i> Artwork</p>
        <h1 class="gallery-title" id="title"><?= h($title) ?></h1>
        <p class="gallery-description" id="meta"><?= h($metaText) ?></p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn-outline theme-primary" id="editLink" href="/edit/<?= h($id) ?>"
            style="color: var(--gallery-accent); border-color: var(--gallery-accent-border);">编辑信息</a>
        <a class="btn-outline theme-primary" href="/"
            style="color: var(--gallery-accent); border-color: var(--gallery-accent-border);">返回图库</a>
    </div>
</header>
<div class="artwork-grid mb-5" id="grid" data-ssr="1">
    <?php if ($files === []): ?>
        <p class="artwork-empty">这个作品还没有本地文件，请先到<a href="/download">下载页</a>抓取。</p>
    <?php else: ?>
        <?php foreach ($files as $index => $file): ?>
            <?php
            $src = $frameBase . rawurlencode($file);
            $label = sprintf('第 %d / %d 张', $index + 1, count($files));
            ?>
            <a href="<?= h($src) ?>" target="_blank" rel="noopener" data-fancybox="gallery"
                data-caption="<?= h($file) ?>" aria-label="<?= h($label . '：' . $file) ?>">
                <img src="<?= h($src) ?>" alt="<?= h($file) ?>"
                    width="300" height="400"
                    loading="<?= $index < 8 ? 'eager' : 'lazy' ?>"
                    <?= $index < 4 ? 'fetchpriority="high" ' : '' ?>decoding="async">
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$extraHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5/dist/fancybox/fancybox.css">'
    . '<link rel="stylesheet" href="/assets/css/artwork.css">';
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5/dist/fancybox/fancybox.umd.js" defer></script>'
    . '<script src="/web/js/artwork.js" type="module"></script>';
require __DIR__ . '/layout.php';
