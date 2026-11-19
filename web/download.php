<?php

declare(strict_types=1);

$pageTitle = '下载 · Pixiv Animation';
$navActive = 'download';

ob_start();
?>
<header class="download-page-heading">
    <h1 class="download-page-title">抓取下载</h1>
    <p class="download-page-description">填写 Pixiv 作品 ID 或链接与登录 Cookie。图片、漫画下载原图；动图会自动解压。</p>
</header>

<div class="row g-4 download-layout mb-5">
    <section class="col-6" aria-labelledby="downloadFormTitle">
        <div class="download-panel download-form-panel">
            <div class="download-panel-heading">
                <h2 class="download-panel-title" id="downloadFormTitle">下载设置</h2>
            </div>
    
            <form id="downloadForm" class="download-form">
                <div class="download-field mb-4">
                    <label class="form-label" for="id">作品 ID / 链接</label>
                    <input type="text" id="id" name="id" class="form-control" placeholder="例如：12345678"
                        aria-describedby="idHint" autocomplete="off" required />
                    <div class="form-text download-field-hint" id="idHint">支持作品 ID 或 Pixiv 作品链接。</div>
                </div>
                <div class="download-field mb-3">
                    <label class="form-label" for="cookie">Pixiv Cookie</label>
                    <textarea class="form-control" id="cookie" name="cookie" rows="4"
                        aria-describedby="cookieHint" autocomplete="off" spellcheck="false" required></textarea>
                </div>
                <p class="download-privacy-note" id="cookieHint">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <span>仅保存在当前浏览器，下载时随请求发送。</span>
                </p>
    
                <div class="download-options">
                    <div class="download-field">
                        <label class="form-label" for="proxyPort">代理端口</label>
                        <select id="proxyPort" name="proxyPort" class="form-control">
                            <option value="0">自动检测（7897 / 7890）</option>
                            <option value="7897">127.0.0.1:7897</option>
                            <option value="7890">127.0.0.1:7890</option>
                        </select>
                    </div>
                    <div class="download-field">
                        <label class="form-label" for="character">人物名称（仅动图，可选）</label>
                        <input type="text" id="character" name="character" class="form-control"
                            placeholder="例如：角色名" autocomplete="off" />
                    </div>
                </div>
    
                <label class="form-field download-redownload" for="redownload">
                    <input class="check" type="checkbox" id="redownload" name="redownload" />
                    <span class="download-redownload-content">
                        <strong>重新下载</strong>
                        <small class="form-text">清空该作品目录后重新下载</small>
                    </span>
                </label>
    
                <div class="download-actions">
                    <button type="submit" class="btn-solid theme-primary download-action-primary" id="startBtn">
                        <i class="fa-solid fa-download" aria-hidden="true"></i>
                        开始下载
                    </button>
                    <button type="button" class="btn-outline theme-primary download-action-secondary" id="fetchMetaBtn">
                        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                        查询作品信息
                    </button>
                </div>
            </form>
        </div>
    </section>

    <section class="col-6" aria-labelledby="downloadLogTitle">
        <div class="download-panel download-log-panel">
            <div class="download-panel-heading download-log-heading">
                <div>
                    <h2 class="download-panel-title" id="downloadLogTitle">任务日志</h2>
                    <p class="download-panel-description">下载进度与接口查询结果会显示在这里。</p>
                </div>
                <span class="download-status" id="downloadStatus" data-state="idle" aria-hidden="true">等待任务</span>
            </div>
            <div id="downloadLog" role="log" aria-labelledby="downloadLogTitle" aria-relevant="additions text">
                <div class="download-log-empty">
                    <span class="download-log-empty-icon"><i class="fa-regular fa-clipboard" aria-hidden="true"></i></span>
                    <strong>还没有任务</strong>
                    <span>填写作品信息后，开始下载或查询作品信息。</span>
                </div>
            </div>
        </div>
    </section>
</div>
<?php
$content = ob_get_clean();
$extraHead = '<script src="/web/js/download.js" type="module"></script>';
require __DIR__ . '/layout.php';
