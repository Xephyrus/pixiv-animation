<?php

declare(strict_types=1);

/** @var string $pageTitle */
/** @var string $navActive */
/** @var string $content */
/** @var string $extraHead */
/** @var string $extraScripts */

$pageTitle = $pageTitle ?? 'Pixiv Animation';
$navActive = $navActive ?? '';
$extraHead = $extraHead ?? '';
$extraScripts = $extraScripts ?? '';
$content = $content ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        html {
            color-scheme: light;
            background-color: #faf7f3;
        }

        html[data-bs-theme="dark"] {
            color-scheme: dark;
            background-color: #292a2d;
        }
    </style>
    <script src="/assets/js/theme.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@6.0.0-alpha.1/dist/css/bootstrap.min.css"
        integrity="sha384-B/GM4XqrwHnWXNOWMbloTmrYXZg10cakYGmpfsR/bbzQ6JAJI4ihuyADKLnBgrCe"
        crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/player.css">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/assets/favicon/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/favicon/favicon-32x32.png" type="image/png" sizes="32x32">
    <link rel="icon" href="/assets/favicon/favicon-16x16.png" type="image/png" sizes="16x16">
    <link rel="apple-touch-icon" href="/assets/favicon/apple-touch-icon.png">
    <link rel="manifest" href="/assets/favicon/site.webmanifest">
    <meta name="theme-color" content="#FF7800">
    <meta name="referrer" content="no-referrer" />
    <?= $extraHead ?>
</head>

<body>
    <a class="skip-to-content" href="#content-start">跳到主要内容</a>
    <div id="app">
        <?php require __DIR__ . '/partials/navbar.php'; ?>
        <div id="content-start" class="container container-mc pt-4" tabindex="-1">
            <?= $content ?>
        </div>
    </div>
    <script type="module"
        src="https://cdn.jsdelivr.net/npm/bootstrap@6.0.0-alpha.1/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-1a/pXj49ZQ1aHEmrJ+gMw1otqoVsYwlEnlD8mIfY2TV03r20Y0CN7uqx1tQogjPL"
        crossorigin="anonymous"></script>
    <?= $extraScripts ?>
</body>

</html>
