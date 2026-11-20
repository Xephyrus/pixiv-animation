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
if ($work === null || (int) $work['illustType'] !== 2) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '作品不存在或不是动图';
    return;
}

$animation = build_ugoira_animation_data($work);
$character = (string) ($work['character'] ?: $work['title'] ?: $work['id']);
$frameBase = (string) ($animation['body']['frameBase'] ?? '');
$firstFrame = (string) (($animation['body']['frames'][0]['file'] ?? '000000.jpg'));
$firstFrameSrc = $frameBase !== '' ? $frameBase . rawurlencode($firstFrame) : '';

$pageTitle = 'Ugoira Player · ' . $character;
$navActive = 'home';

ob_start();
?>
<main class="player-page d-flex align-items-center">
    <div class="player-shell">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <button id="playerBackButton" class="btn-outline theme-primary btn-styled" type="button">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                返回上一页
            </button>
            <a role="button" class="btn-outline theme-primary btn-styled" href="/edit/<?= h($id) ?>">
                <i class="fa-regular fa-edit" aria-hidden="true"></i>
                编辑信息
            </a>
        </div>
        <section class="player" aria-label="动画播放器">
            <header class="player-header">
                <h1>Ugoira Player</h1>
                <span id="character"><?= h($character) ?></span>
                <span class="frame-counter" id="frameCounter" aria-live="polite">加载中...</span>
            </header>
            <div class="stage">
                <img id="animationFrame" src="<?= h($firstFrameSrc) ?>" alt="动画帧 1" decoding="async" class="active">
                <img id="bufferFrame" alt="" aria-hidden="true" decoding="async">
            </div>
            <div class="progress-track" aria-hidden="true">
                <div class="progress" id="progress"></div>
            </div>
            <footer class="player-controls">
                <div class="control-group">
                    <button id="playPauseButton" class="btn-solid theme-primary player-action" type="button"
                        aria-pressed="false">播放</button>
                    <span class="hint">空格键播放 / 暂停</span>
                </div>
                <span class="frame-counter" id="frameDelay">45 ms / 帧</span>
            </footer>
        </section>
    </div>
</main>

<script>
    window.animationData = <?= json_encode($animation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<?php
$content = ob_get_clean();
$extraScripts = '<script src="/assets/js/player.js"></script>';
require __DIR__ . '/layout.php';
