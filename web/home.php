<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$pageTitle = 'Pixiv Animation | 动画作品集';
$navActive = 'home';
$pageSize = 20;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$search = trim((string) ($_GET['search'] ?? ''));

$filter = [
    'page' => $currentPage,
    'page_size' => $pageSize,
    'search' => $search,
    'sort' => 'modified_at',
    'order' => 'desc',
];

$db = app_db();
$totalWorks = $db->countWorks($filter);
$totalPages = max(1, (int) ceil($totalWorks / $pageSize));
$currentPage = min($currentPage, $totalPages);
$filter['page'] = $currentPage;
$works = decorate_works($db->listWorks($filter));
$start = $totalWorks === 0 ? 0 : ($currentPage - 1) * $pageSize;
$end = $totalWorks === 0 ? 0 : min($start + count($works), $totalWorks);

function gallery_type_label(int $type): string
{
    return match ($type) {
        2 => 'UGOIRA',
        1 => 'MANGA',
        default => 'ILLUST',
    };
}

function gallery_type_icon(int $type): string
{
    return match ($type) {
        2 => 'fa-film',
        1 => 'fa-book-open',
        default => 'fa-image',
    };
}

function gallery_type_kind(int $type): string
{
    return match ($type) {
        2 => '动态插画',
        1 => '漫画',
        default => '插画',
    };
}

function render_work_card(array $work, int $index): string
{
    $type = (int) ($work['illustType'] ?? 0);
    $title = h((string) ($work['character'] ?: $work['title'] ?: $work['name'] ?: $work['id']));
    $cover = h((string) ($work['coverUrl'] ?? ''));
    $href = h((string) ($work['viewUrl'] ?? '#'));
    $id = h((string) ($work['id'] ?? ''));
    $badge = gallery_type_label($type);
    $icon = gallery_type_icon($type);
    $action = $type === 2 ? '查看动画' : '查看作品';
    $kind = gallery_type_kind($type);
    $eager = $index < 8;
    $coverAttr = $cover !== '' ? ' style="--work-cover: url(\'' . $cover . '\')"' : '';
    $play = $type === 2
        ? '<span class="work-card-play" aria-hidden="true"><i class="fa-solid fa-play"></i></span>'
        : '';
    $loading = $eager ? 'eager' : 'lazy';
    $priority = $eager ? ' fetchpriority="high"' : '';

    return <<<HTML
<article class="work-card">
    <a class="work-card-link" href="{$href}">
        <div class="work-card-media"{$coverAttr}>
            <img src="{$cover}"
                alt="{$title} 预览"
                width="400" height="600"
                loading="{$loading}"
                decoding="async"{$priority}>
            <span class="work-card-badge"><i class="fa-solid {$icon}" aria-hidden="true"></i> {$badge}</span>
            {$play}
        </div>
        <div class="work-card-content">
            <h2 class="work-card-title">{$title}</h2>
            <p class="work-card-meta"><i class="fa-solid fa-hashtag" aria-hidden="true"></i><span>Pixiv {$kind} · {$id}</span></p>
            <span class="work-card-action">
                <span class="work-card-action-label"><i class="fa-regular fa-circle-play" aria-hidden="true"></i> {$action}</span>
                <i class="fa-solid fa-arrow-right work-card-arrow" aria-hidden="true"></i>
            </span>
        </div>
    </a>
</article>
HTML;
}

/** @return list<int|string> */
function gallery_visible_pages(int $currentPage, int $totalPages): array
{
    if ($totalPages <= 7) {
        return range(1, $totalPages);
    }

    $start = max(2, $currentPage - 2);
    $end = min($totalPages - 1, $currentPage + 2);
    if ($currentPage <= 4) {
        $end = 5;
    }
    if ($currentPage >= $totalPages - 3) {
        $start = $totalPages - 4;
    }

    $pages = [1];
    if ($start > 2) {
        $pages[] = 'ellipsis';
    }
    for ($page = $start; $page <= $end; $page++) {
        $pages[] = $page;
    }
    if ($end < $totalPages - 1) {
        $pages[] = 'ellipsis';
    }
    $pages[] = $totalPages;
    return $pages;
}

function render_page_button(
    int $page,
    string $label,
    bool $current = false,
    bool $edge = false,
    bool $disabled = false,
    ?string $icon = null
): string {
    $classes = 'pagination-button' . ($edge ? ' pagination-edge' : '');
    $attrs = 'type="button" class="' . $classes . '" data-page="' . $page . '" aria-label="' . h($label) . '"';
    if ($disabled) {
        $attrs .= ' disabled';
    }
    if ($current) {
        $attrs .= ' aria-current="page"';
    }

    $content = '';
    if ($icon !== null) {
        $content .= '<i class="fa-solid ' . h($icon) . '" aria-hidden="true"></i>';
    }
    if ($edge) {
        $content .= '<span class="pagination-button-text">' . h($label) . '</span>';
    } else {
        $content .= h((string) $page);
    }

    return '<button ' . $attrs . '>' . $content . '</button>';
}

ob_start();
?>
<section class="gallery" aria-labelledby="galleryTitle">
    <header class="gallery-heading">
        <div>
            <p class="gallery-kicker"><i class="fa-solid fa-images" aria-hidden="true"></i> Animation Gallery</p>
            <h1 class="gallery-title" id="galleryTitle">Pixiv Animation</h1>
            <p class="gallery-description">精选动态插画，点击卡片开始播放。</p>
        </div>
        <form class="gallery-toolbar" id="gallerySearchForm" role="search">
            <label class="visually-hidden" for="gallerySearch">搜索作品</label>
            <input class="gallery-search" id="gallerySearch" name="search" type="search"
                value="<?= h($search) ?>" placeholder="搜索角色 / 标题 / ID" autocomplete="off">
            <span class="gallery-count" aria-live="polite">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <span id="workCount"><?= h((string) $totalWorks) ?> 部作品</span>
            </span>
        </form>
    </header>

    <main id="main" class="work-grid" aria-label="动画作品列表">
        <?php if ($totalWorks === 0): ?>
            <p class="gallery-description gallery-empty">
                <?= $search !== ''
                    ? '没有匹配的作品，试试其他关键词。'
                    : '暂无作品。请先到 <a href="/download">下载页</a> 抓取，或执行同步。' ?>
            </p>
        <?php else: ?>
            <?php foreach ($works as $index => $work): ?>
                <?= render_work_card($work, $index) ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</section>

<nav id="pagination" class="pagination" aria-label="作品分页" aria-controls="main"
    <?= $totalPages <= 1 ? 'hidden' : '' ?>>
    <p id="paginationSummary" class="pagination-summary" aria-live="polite">
        <?php if ($totalWorks > 0 && $totalPages > 1): ?>
            显示第 <?= $start + 1 ?>–<?= $end ?> 部，共 <?= $totalWorks ?> 部
        <?php endif; ?>
    </p>
    <div id="paginationControls" class="pagination-controls">
        <?php if ($totalPages > 1): ?>
            <?= render_page_button(1, '首页', false, true, $currentPage === 1, 'fa-angles-left') ?>
            <?= render_page_button(max(1, $currentPage - 1), '上一页', false, true, $currentPage === 1, 'fa-angle-left') ?>
            <?php foreach (gallery_visible_pages($currentPage, $totalPages) as $page): ?>
                <?php if ($page === 'ellipsis'): ?>
                    <span class="pagination-ellipsis" aria-hidden="true">…</span>
                <?php else: ?>
                    <?= render_page_button((int) $page, '第 ' . $page . ' 页', (int) $page === $currentPage) ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <?= render_page_button(min($totalPages, $currentPage + 1), '下一页', false, true, $currentPage === $totalPages, 'fa-angle-right') ?>
            <?= render_page_button($totalPages, '尾页', false, true, $currentPage === $totalPages, 'fa-angles-right') ?>
        <?php endif; ?>
    </div>
</nav>

<script id="galleryBootstrap" type="application/json"><?= json_encode([
    'page' => $currentPage,
    'pageSize' => $pageSize,
    'total' => $totalWorks,
    'search' => $search,
    'items' => $works,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php
$content = ob_get_clean();
$extraScripts = '<script src="/assets/js/gallery.js" type="module"></script>';
require __DIR__ . '/layout.php';
