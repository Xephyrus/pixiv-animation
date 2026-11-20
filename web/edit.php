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

$typeLabel = match ((int) $work['illustType']) {
    2 => '动图',
    1 => '漫画',
    default => '插画',
};
$displayName = (string) ($work['character'] ?: $work['title'] ?: $work['name'] ?: $id);
$coverUrl = work_cover_url($work);
$viewUrl = work_view_url($work);

$pageTitle = '编辑作品 · ' . $displayName;
$navActive = 'home';

ob_start();
?>
<header class="gallery-heading">
    <div>
        <p class="gallery-kicker"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Edit Work</p>
        <h1 class="gallery-title">编辑作品信息</h1>
        <p class="gallery-description">
            ID <?= h($id) ?> · <?= h($typeLabel) ?> · <?= h((string) $work['fileCount']) ?> 个文件。
            这里只改本地数据库，不会影响 Pixiv 上的作品。
        </p>
    </div>
    <a class="btn-outline theme-primary" href="<?= h($viewUrl) ?>"
        style="color: var(--gallery-accent); border-color: var(--gallery-accent-border);">返回作品</a>
</header>

<div class="row g-4 download-layout mb-5">
    <section class="col-lg-7" aria-labelledby="editFormTitle">
        <div class="download-panel download-form-panel">
            <div class="download-panel-heading">
                <h2 class="download-panel-title" id="editFormTitle">作品信息</h2>
            </div>

            <form id="editForm" class="download-form" data-id="<?= h($id) ?>">
                <div class="download-field mb-4">
                    <label class="form-label" for="character">人物名称</label>
                    <input type="text" id="character" name="character" class="form-control"
                        value="<?= h((string) $work['character']) ?>" placeholder="例如：上原燐" autocomplete="off" />
                    <div class="form-text download-field-hint">留空时图库会回退显示标题或作品 ID。</div>
                </div>

                <div class="download-field mb-4">
                    <label class="form-label" for="title">标题</label>
                    <input type="text" id="title" name="title" class="form-control"
                        value="<?= h((string) $work['title']) ?>" autocomplete="off" />
                </div>

                <div class="download-field mb-4">
                    <label class="form-label" for="name">显示名</label>
                    <input type="text" id="name" name="name" class="form-control"
                        value="<?= h((string) $work['name']) ?>" autocomplete="off" />
                    <div class="form-text download-field-hint">卡片顺序：人物名称 → 标题 → 显示名。</div>
                </div>

                <div class="download-field mb-4">
                    <label class="form-label" for="tags">标签</label>
                    <input type="text" id="tags" name="tags" class="form-control"
                        value="<?= h(implode(', ', array_map('strval', $work['tags']))) ?>"
                        placeholder="用逗号分隔，例如：うごイラ, オリジナル" autocomplete="off" />
                </div>

                <div class="download-actions">
                    <button type="submit" class="btn-solid theme-primary download-action-primary" id="saveBtn">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        保存
                    </button>
                    <a class="btn-outline theme-primary download-action-secondary" href="<?= h($viewUrl) ?>">取消</a>
                </div>

                <p id="editStatus" class="download-field-hint mt-3" role="status" aria-live="polite"></p>
            </form>
        </div>
    </section>

    <section class="col-lg-5" aria-label="作品预览">
        <div class="download-panel">
            <div class="download-panel-heading">
                <h2 class="download-panel-title">预览</h2>
            </div>
            <?php if ($coverUrl !== ''): ?>
                <img src="<?= h($coverUrl) ?>" alt="<?= h($displayName) ?> 预览"
                    style="width: 100%; border-radius: .75rem; display: block;">
            <?php endif; ?>
            <dl class="mt-3 mb-0">
                <dt>显示名</dt>
                <dd id="previewName"><?= h($displayName) ?></dd>
                <dt>作品 ID</dt>
                <dd><?= h($id) ?></dd>
                <dt>类型 / 帧数</dt>
                <dd><?= h($typeLabel) ?> · <?= h((string) $work['pageCount']) ?></dd>
            </dl>
        </div>
    </section>
</div>
<?php
$content = ob_get_clean();
$extraScripts = '<script src="/web/js/edit.js" type="module"></script>';
require __DIR__ . '/layout.php';
