<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $resolvedDescription=$metaDescription??($platformSettings['seo_description']??'Tuffer, um marketplace feito para comprar de quem faz.');$resolvedCanonical=$canonicalUrl??absolute_url((string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH));$resolvedOpenGraphImage=$openGraphImage??(!empty($platformSettings['share_image_path'])?absolute_url('/uploads/'.ltrim((string)$platformSettings['share_image_path'],'/')):null);$defaultStructuredData=[['@context'=>'https://schema.org','@type'=>'Organization','name'=>$platformSettings['platform_name']??'Tuffer','url'=>absolute_url('/'),'logo'=>absolute_url('/uploads/'.ltrim($platformSettings['logo_path']??'platform/logos/tuffer-logo.svg','/'))],['@context'=>'https://schema.org','@type'=>'WebSite','name'=>$platformSettings['platform_name']??'Tuffer','url'=>absolute_url('/'),'potentialAction'=>['@type'=>'SearchAction','target'=>absolute_url('/buscar?q={search_term_string}'),'query-input'=>'required name=search_term_string']]];$schemas=$structuredData??$defaultStructuredData; ?>
    <meta name="description" content="<?= e($resolvedDescription) ?>">
    <meta name="robots" content="<?= e($platformSettings['seo_robots'] ?? 'index,follow') ?>">
    <link rel="canonical" href="<?= e($resolvedCanonical) ?>">
    <meta property="og:type" content="<?= e($openGraphType??'website') ?>"><meta property="og:title" content="<?=e($pageTitle??($platformSettings['seo_title']??'Marketplace'))?>"><meta property="og:description" content="<?=e($resolvedDescription)?>"><meta property="og:url" content="<?=e($resolvedCanonical)?>"><?php if(!empty($resolvedOpenGraphImage)):?><meta property="og:image" content="<?=e($resolvedOpenGraphImage)?>"><?php endif;?>
    <title><?= e($pageTitle ?? ($platformSettings['seo_title'] ?? 'Marketplace')) ?> · <?= e($platformSettings['platform_name'] ?? 'Tuffer') ?></title>
    <link rel="icon" href="<?= e(upload_asset($platformSettings['favicon_path'] ?? 'platform/favicon/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/responsive.css')) ?>">
    <style>:root{<?= platform_theme_style($platformSettings) ?>}</style>
    <script defer src="<?= e(asset('js/app.js')) ?>"></script>
    <?php foreach($schemas as $schema):?><script type="application/ld+json"><?=json_encode($schema,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script><?php endforeach;?>
</head>
<body class="public-shell <?=e(platform_theme_classes($platformSettings))?>">
    <?php require dirname(__DIR__) . '/components/public/header.php'; ?>
    <?php if ($flashSuccess): ?><div class="toast toast--success" role="status"><?= e($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="toast toast--error" role="alert"><?= e($flashError) ?></div><?php endif; ?>
    <main><?= $content ?></main>
    <?php require dirname(__DIR__) . '/components/public/footer.php'; ?>
</body>
</html>
