<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle ?? 'Acesso') ?> · <?= e($platformSettings['platform_name'] ?? 'Tuffer') ?></title>
    <link rel="icon" href="<?= e(upload_asset($platformSettings['favicon_path'] ?? 'platform/favicon/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/responsive.css')) ?>">
    <style>:root{<?= platform_theme_style($platformSettings) ?>}</style>
    <script src="<?= e(asset('js/app.js')) ?>" defer></script>
</head>
<body class="auth-page <?= e(platform_theme_classes($platformSettings)) ?>">
    <?php if ($flashSuccess): ?><div class="toast toast--success" role="status"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="toast toast--error" role="alert"><?= e($flashError) ?></div><?php endif; ?>

    <main class="auth-main">
        <div class="auth-shell<?= !empty($minimalAuthLayout) ? ' auth-shell--login' : '' ?>">
            <?php if (empty($minimalAuthLayout)): ?>
            <nav class="auth-page-nav" aria-label="Navegação da autenticação">
                <a class="auth-page-nav__logo" href="<?= e(url('/')) ?>" aria-label="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>, página inicial"><img src="<?= e(upload_asset($platformSettings['logo_path'] ?? 'platform/logos/tuffer-logo.svg')) ?>" alt="<?= e($platformSettings['platform_name'] ?? 'Tuffer') ?>"></a>
                <a class="auth-page-nav__back" href="<?= e(url('/')) ?>"><span aria-hidden="true">←</span> Voltar para a loja</a>
            </nav>
            <?php endif; ?>
            <?= $content ?>
        </div>
    </main>
</body>
</html>
