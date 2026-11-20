<?php

declare(strict_types=1);

$pageTitle = '作品 · Pixiv Animation';
$navActive = 'home';

ob_start();
?>
<header class="gallery-heading">
    <div>
        <p class="gallery-kicker"><i class="fa-solid fa-image" aria-hidden="true"></i> Artwork</p>
        <h1 class="gallery-title" id="title">加载中…</h1>
        <p class="gallery-description" id="meta">点击缩略图查看大图：支持缩放、1:1、旋转、全屏、缩略图与左右切换。</p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn-outline theme-primary" id="editLink" href="#" hidden
            style="color: var(--gallery-accent); border-color: var(--gallery-accent-border);">编辑信息</a>
        <a class="btn-outline theme-primary" href="/"
            style="color: var(--gallery-accent); border-color: var(--gallery-accent-border);">返回图库</a>
    </div>
</header>
<div class="artwork-grid mb-5" id="grid"></div>
<?php
$content = ob_get_clean();
$extraHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5/dist/fancybox/fancybox.css">'
    . '<link rel="stylesheet" href="/assets/css/artwork.css">';
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/@fancyapps/ui@5/dist/fancybox/fancybox.umd.js" defer></script>'
    . '<script src="/web/js/artwork.js" type="module"></script>';
require __DIR__ . '/layout.php';
