<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? $dashboardName) ?> · Tuffer</title>
    <link rel="icon" href="<?= e(upload_asset($platformSettings['favicon_path'] ?? 'platform/favicon/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/responsive.css')) ?>">
    <style>:root{<?= platform_theme_style($platformSettings) ?>}</style>
    <script defer src="<?= e(asset('js/app.js')) ?>"></script>
</head>
<body class="dashboard-shell <?=e(platform_theme_classes($platformSettings))?>">
    <?php require dirname(__DIR__) . '/components/dashboard/sidebar.php'; ?>
    <div class="dashboard-main">
        <?php require dirname(__DIR__) . '/components/dashboard/topbar.php'; ?>
        <main class="dashboard-content">
            <?php if ($flashSuccess): ?><div class="alert alert--success"><?= e($flashSuccess) ?></div><?php endif; ?>
            <?php if ($flashError): ?><div class="alert alert--error"><?= e($flashError) ?></div><?php endif; ?>
            <?= $content ?>
        </main>
    </div>
</body>
</html>
