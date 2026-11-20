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
        <p class="gallery-description" id="meta"></p>
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
$extraScripts = <<<'HTML'
<script type="module">
const id = new URLSearchParams(location.search).get('id')
    || location.pathname.split('/').filter(Boolean).pop();
const titleEl = document.getElementById('title');
const metaEl = document.getElementById('meta');
const grid = document.getElementById('grid');
try {
    const res = await fetch('/api/works/' + encodeURIComponent(id));
    const json = await res.json();
    if (!res.ok || json.success === false) throw new Error(json.error || '加载失败');
    const work = json.data;
    titleEl.textContent = work.title || work.name || work.id;
    metaEl.textContent = `ID ${work.id} · ${work.illustType === 1 ? '漫画' : '图片'} · ${work.fileCount} 张`;
    const editLink = document.getElementById('editLink');
    if (editLink) {
        editLink.href = '/edit/' + encodeURIComponent(work.id);
        editLink.hidden = false;
    }
    grid.innerHTML = (work.files || []).map((file) => {
        const src = `/downloads/${encodeURIComponent(work.id)}/${encodeURIComponent(file)}`;
        return `<a href="${src}" target="_blank" rel="noopener"><img src="${src}" alt="${file}" loading="lazy"></a>`;
    }).join('');
} catch (e) {
    titleEl.textContent = '无法加载作品';
    metaEl.textContent = String(e.message || e);
}
</script>
HTML;
require __DIR__ . '/layout.php';
