<?php
/** @var string $navActive */
$navActive = $navActive ?? '';
?>
<nav class="navbar fixed-top bg-1 fg-2 px-3">
    <div class="container container-mc site-navbar-inner">
        <a class="navbar-brand" href="/">
            <img src="/assets/favicon/apple-touch-icon.png" height="40"
                width="40" alt="Pixiv Animation Logo" loading="lazy" />
            <span class="ms-2">Pixiv Animation</span>
        </a>
        <ul class="navbar-nav site-nav-links">
            <li class="nav-item">
                <a class="nav-link<?= $navActive === 'home' ? ' active' : '' ?>" href="/"
                    <?= $navActive === 'home' ? 'aria-current="page"' : '' ?>>图库</a>
            </li>
            <li class="nav-item">
                <a class="nav-link<?= $navActive === 'download' ? ' active' : '' ?>" href="/download"
                    <?= $navActive === 'download' ? 'aria-current="page"' : '' ?>>下载</a>
            </li>
        </ul>
        <div class="nav-item-right">
            <button class="btn-icon theme-secondary" type="button" id="navbarDropdownToggleTheme"
                data-bs-toggle="menu" data-bs-placement="bottom-end" aria-label="切换主题（自动）"
                aria-controls="themeMenu" aria-expanded="false">
                <i class="fas fa-circle-half-stroke theme-icon-active" aria-hidden="true"></i>
            </button>
            <div class="menu menu-translucent" id="themeMenu" aria-labelledby="navbarDropdownToggleTheme">
                <button class="menu-item d-flex align-items-center" data-bs-theme-value="light" data-theme="light"
                    type="button" aria-pressed="false">
                    <i class="fas fa-sun" aria-hidden="true"></i>
                    <span>浅色</span>
                    <i class="fas fa-check theme-check" hidden aria-hidden="true"></i>
                </button>
                <button class="menu-item d-flex align-items-center" data-bs-theme-value="dark" data-theme="dark"
                    type="button" aria-pressed="false">
                    <i class="fas fa-moon" aria-hidden="true"></i>
                    <span>深色</span>
                    <i class="fas fa-check theme-check" hidden aria-hidden="true"></i>
                </button>
                <button class="menu-item d-flex align-items-center active" data-bs-theme-value="auto" data-theme="auto"
                    type="button" aria-pressed="true">
                    <i class="fas fa-circle-half-stroke" aria-hidden="true"></i>
                    <span>自动</span>
                    <i class="fas fa-check theme-check" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
</nav>
