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
<main class="edit-page-main">
<header class="gallery-heading edit-page-heading">
    <div class="edit-page-intro">
        <p class="gallery-kicker"><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i> Edit Work</p>
        <h1 class="gallery-title">编辑作品信息</h1>
        <div class="edit-page-meta" aria-label="作品概览">
            <span><i class="fa-solid fa-hashtag" aria-hidden="true"></i>ID <?= h($id) ?></span>
            <span><i class="fa-regular fa-images" aria-hidden="true"></i><?= h($typeLabel) ?></span>
            <span><i class="fa-regular fa-file-image" aria-hidden="true"></i><?= h((string) $work['fileCount']) ?> 个文件</span>
        </div>
        <p class="edit-page-note">
            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
            <span>仅修改本地数据库，不会影响 <bdi>Pixiv</bdi> 上的作品。</span>
        </p>
    </div>
    <a class="btn-outline theme-primary btn-styled edit-return-link" href="<?= h($viewUrl) ?>">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
        返回作品
    </a>
</header>

<div class="row g-4 download-layout edit-layout mb-5">
    <section class="col-lg-7" aria-labelledby="editFormTitle">
        <div class="download-panel download-form-panel edit-form-panel">
            <div class="download-panel-heading edit-panel-heading">
                <div>
                    <h2 class="download-panel-title" id="editFormTitle">作品信息</h2>
                    <p class="download-panel-description">这些信息会显示在图库作品卡片中。</p>
                </div>
            </div>

            <form id="editForm" class="download-form edit-form" data-id="<?= h($id) ?>">
                <div class="download-field edit-field">
                    <label class="form-label" for="character">人物名称</label>
                    <input type="text" id="character" name="character" class="form-control"
                        value="<?= h((string) $work['character']) ?>" placeholder="例如：上原燐"
                        aria-describedby="characterHelp" autocomplete="off" />
                    <div class="form-text download-field-hint" id="characterHelp">
                        留空时会回退到标题；标题也为空时再回退到显示名或作品 ID。
                    </div>
                </div>

                <div class="download-field edit-field">
                    <label class="form-label" for="title">标题</label>
                    <input type="text" id="title" name="title" class="form-control"
                        value="<?= h((string) $work['title']) ?>" autocomplete="off" />
                </div>

                <div class="download-field edit-field">
                    <label class="form-label" for="name">显示名</label>
                    <input type="text" id="name" name="name" class="form-control"
                        value="<?= h((string) $work['name']) ?>" aria-describedby="nameHelp" autocomplete="off" />
                    <div class="form-text download-field-hint" id="nameHelp">
                        仅当人物名称和标题均为空时，图库才会显示此名称。
                    </div>
                </div>

                <div class="download-field edit-field">
                    <label class="form-label" for="tags">标签</label>
                    <input type="text" id="tags" name="tags" class="form-control"
                        value="<?= h(implode(', ', array_map('strval', $work['tags']))) ?>"
                        placeholder="用逗号分隔，例如：うごイラ, オリジナル" autocomplete="off" />
                </div>

                <div class="download-actions edit-actions">
                    <button type="submit" class="btn-solid theme-primary download-action-primary w-6" id="saveBtn">
                        <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                        保存
                    </button>
                    <a role="button" class="btn-outline theme-primary download-action-secondary w-6" href="<?= h($viewUrl) ?>">取消编辑</a>
                </div>

                <p id="editStatus" class="edit-status" role="status" aria-live="polite"></p>
            </form>
        </div>
    </section>

    <section class="col-lg-5 edit-preview-column" aria-labelledby="editPreviewTitle">
        <div class="download-panel edit-preview-panel">
            <div class="download-panel-heading edit-panel-heading">
                <div>
                    <h2 class="download-panel-title" id="editPreviewTitle">作品预览</h2>
                    <p class="download-panel-description">名称会随左侧填写内容即时更新。</p>
                </div>
                <span class="edit-preview-live">实时</span>
            </div>
            <?php if ($coverUrl !== ''): ?>
                <div class="edit-preview-media">
                    <img class="edit-preview-image" src="<?= h($coverUrl) ?>" alt="<?= h($displayName) ?> 预览">
                </div>
            <?php else: ?>
                <p class="edit-preview-empty">暂无预览图</p>
            <?php endif; ?>
            <dl class="edit-preview-details">
                <div>
                    <dt>图库显示名</dt>
                    <dd id="previewName"><?= h($displayName) ?></dd>
                </div>
                <div>
                    <dt>作品 ID</dt>
                    <dd><?= h($id) ?></dd>
                </div>
                <div>
                    <dt>类型 / 帧数</dt>
                    <dd><?= h($typeLabel) ?> · <?= h((string) $work['pageCount']) ?></dd>
                </div>
            </dl>
        </div>
    </section>
</div>
</main>
<?php
$content = ob_get_clean();
$extraScripts = '<script src="/web/js/edit.js" type="module"></script>';
require __DIR__ . '/layout.php';
