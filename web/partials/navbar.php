<?php
/** @var string $navActive */
$navActive = $navActive ?? '';
?>
<nav class="navbar fixed-top bg-1 fg-2 px-3">
    <div class="container container-mc site-navbar-inner">
        <a class="navbar-brand" href="/">
            <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0iI0ZGNzgwMCI+PHBhdGggZD0iTTQuOTM1IDBBNC45MjQgNC45MjQgMCAwIDAgMCA0LjkzNXYxNC4xM0E0LjkyNCA0LjkyNCAwIDAgMCA0LjkzNSAyNGgxNC4xM0E0LjkyNCA0LjkyNCAwIDAgMCAyNCAxOS4wNjVWNC45MzVBNC45MjQgNC45MjQgMCAwIDAgMTkuMDY1IDB6bTcuODEgNC41NDdjMi4xODEgMCA0LjA1OC42NzYgNS4zOTkgMS44NDdhNi4xMTggNi4xMTggMCAwIDEgMi4xMTYgNC42NmMuMDA1IDEuODU0LS44OCAzLjQ3Ni0yLjI1NyA0LjU2My0xLjM3NSAxLjA5Mi0zLjIyNSAxLjY5Ny01LjI1OCAxLjY5Ny0yLjMxNCAwLTQuNDYtLjg0Mi00LjQ2LS44NDJ2Mi43MThjLjM5Ny4xMTYgMS4wNDguMzY1LjYzNS43NzlINS43OWMtLjQxLS40MS4xOS0uNjUuNjQ0LS43NzlWNy42NjZjLTEuMDUzLjgxLTEuNTkzIDEuNTEtMS44NjggMi4wMzEuMzIgMS4wMi0uMjg0Ljk2OS0uMjg0Ljk2OWwtMS4wOS0xLjczczMuODY4LTQuMzkgOS41NTMtNC4zOXptLS4xOS45NzFjLTEuNDIzLS4wMDMtMy4xODQuNDczLTQuMjcgMS4yNDR2OC42NDZjLjk4OC40ODcgMi40ODQuODMyIDQuMjYuODMyaC4wMWMxLjU5NiAwIDIuOTgtLjU5MyAzLjkzLTEuNTMzLjk1Mi0uOTQ4IDEuNDg2LTIuMTgzIDEuNDkyLTMuNjgzLS4wMDUtMS41NC0uNTA0LTIuODY0LTEuNDItMy44Ni0uOTE4LS45OTItMi4yNzQtMS42NDUtNC4wMDItMS42NDZ6Ii8+PC9zdmc+" height="40"
                width="40" alt="Pixiv Animation Logo" />
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
                <i class="fas fa-sun theme-icon theme-icon-light" aria-hidden="true"></i>
                <i class="fas fa-moon theme-icon theme-icon-dark" aria-hidden="true"></i>
                <i class="fas fa-circle-half-stroke theme-icon theme-icon-auto" aria-hidden="true"></i>
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
