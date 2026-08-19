<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\Media\ProductMediaRepository;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$options = getopt('', ['execute', 'sql:', 'product:']);
$execute = array_key_exists('execute', $options);
$sqlPath = isset($options['sql']) ? (string) $options['sql'] : $root . '/images_tuffer.sql';
$productFilter = isset($options['product']) ? max(1, (int) $options['product']) : null;

if (!is_file($sqlPath) || !is_readable($sqlPath)) {
    fwrite(STDERR, "Arquivo SQL não encontrado ou sem permissão de leitura: {$sqlPath}\n");
    exit(1);
}

foreach (['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'] as $key) {
    if (trim((string) ($_ENV[$key] ?? '')) === '') {
        fwrite(STDERR, "Configuração obrigatória ausente: {$key}\n");
        exit(1);
    }
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "A extensão cURL do PHP é obrigatória para transferir as imagens.\n");
    exit(1);
}

/** @return list<array{id:int,old_product_id:int,url:string,alt:string,sort_order:int,is_main:bool}> */
function parseLegacyImages(string $contents): array
{
    $pattern = "~INSERT INTO\\s+(?:public\\.)?product_images\\s*\\([^;]+?\\)\\s*VALUES\\s*\\(\\s*(\\d+)\\s*,\\s*(\\d+)\\s*,\\s*'((?:[^']|'')*)'\\s*,\\s*'((?:[^']|'')*)'\\s*,\\s*(\\d+)\\s*,\\s*(true|false)~i";
    preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER);

    $images = [];
    foreach ($matches as $match) {
        $images[] = [
            'id' => (int) $match[1],
            'old_product_id' => (int) $match[2],
            'url' => str_replace("''", "'", $match[3]),
            'alt' => str_replace("''", "'", $match[4]),
            'sort_order' => (int) $match[5],
            'is_main' => mb_strtolower($match[6]) === 'true',
        ];
    }

    return $images;
}

function repairMojibake(string $value): string
{
    for ($attempt = 0; $attempt < 2 && preg_match('/(?:Ã.|Â.)/u', $value); $attempt++) {
        $repaired = mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
        if ($repaired === '' || !mb_check_encoding($repaired, 'UTF-8')) break;
        $value = $repaired;
    }
    return $value;
}

function normalizedProductName(string $value): string
{
    $value = mb_strtolower(repairMojibake(trim($value)), 'UTF-8');
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = $ascii === false ? $value : $ascii;
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
}

/** @param array<string,mixed> $product @return array<string,mixed> */
function uploadToCloudinary(array $legacyImage, array $product): array
{
    $sourceUrl = (string) $legacyImage['url'];
    $parts = parse_url($sourceUrl);
    $allowedHosts = ['pub-664ca12d099643c78432f29d9154ae98.r2.dev'];
    if (($parts['scheme'] ?? '') !== 'https' || !in_array(mb_strtolower((string) ($parts['host'] ?? '')), $allowedHosts, true)) {
        throw new RuntimeException('URL de origem fora da lista permitida.');
    }

    $cloudName = (string) $_ENV['CLOUDINARY_CLOUD_NAME'];
    $apiKey = (string) $_ENV['CLOUDINARY_API_KEY'];
    $apiSecret = (string) $_ENV['CLOUDINARY_API_SECRET'];
    $storeSlug = preg_replace('/[^a-z0-9-]+/', '-', mb_strtolower((string) $product['store_slug']));
    $publicId = sprintf(
        'stores/%s-%d/products/%d/legacy-%d',
        trim((string) $storeSlug, '-'),
        (int) $product['store_id'],
        (int) $product['id'],
        (int) $legacyImage['id']
    );
    $timestamp = time();
    $signature = sha1("public_id={$publicId}&timestamp={$timestamp}{$apiSecret}");

    $curl = curl_init('https://api.cloudinary.com/v1_1/' . rawurlencode($cloudName) . '/image/upload');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => [
            'file' => $sourceUrl,
            'api_key' => $apiKey,
            'timestamp' => (string) $timestamp,
            'public_id' => $publicId,
            'signature' => $signature,
        ],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $transportError = curl_error($curl);
    curl_close($curl);

    if ($body === false) throw new RuntimeException('Falha de transporte: ' . $transportError);
    $response = json_decode($body, true);
    if (!is_array($response)) throw new RuntimeException("Resposta inválida do Cloudinary (HTTP {$status}).");
    if ($status >= 400 || isset($response['error'])) {
        throw new RuntimeException((string) ($response['error']['message'] ?? "Cloudinary retornou HTTP {$status}."));
    }

    return $response;
}

$legacyImages = parseLegacyImages((string) file_get_contents($sqlPath));
if (!$legacyImages) {
    fwrite(STDERR, "Nenhuma imagem foi reconhecida no arquivo SQL.\n");
    exit(1);
}

$legacyGroups = [];
foreach ($legacyImages as $image) $legacyGroups[$image['old_product_id']][] = $image;

$pdo = Database::connection();
$products = $pdo->query("SELECT p.id,p.name,p.store_id,s.slug store_slug,(SELECT COUNT(*) FROM product_media pm WHERE pm.product_id=p.id AND pm.resource_type='image') existing_images,(SELECT COUNT(*) FROM product_media pm WHERE pm.product_id=p.id AND pm.resource_type='image' AND pm.is_cover=1) existing_covers FROM products p JOIN stores s ON s.id=p.store_id ORDER BY p.id")->fetchAll();
$productsByName = [];
foreach ($products as $product) $productsByName[normalizedProductName((string) $product['name'])][] = $product;

$matches = [];
$unmatched = [];
$ambiguous = [];
foreach ($legacyGroups as $oldProductId => $images) {
    $legacyName = repairMojibake((string) $images[0]['alt']);
    $candidates = $productsByName[normalizedProductName($legacyName)] ?? [];
    if (!$candidates) {
        $unmatched[] = ['old_id' => $oldProductId, 'name' => $legacyName, 'images' => count($images)];
        continue;
    }
    if (count($candidates) !== 1) {
        $ambiguous[] = ['old_id' => $oldProductId, 'name' => $legacyName, 'images' => count($images)];
        continue;
    }
    if ($productFilter !== null && (int) $candidates[0]['id'] !== $productFilter) continue;
    $matches[] = ['old_id' => $oldProductId, 'product' => $candidates[0], 'images' => $images];
}

echo $execute ? "IMPORTAÇÃO DE IMAGENS ANTIGAS\n" : "PRÉVIA DA IMPORTAÇÃO (nenhuma alteração será feita)\n";
echo str_repeat('=', 72) . "\n";
foreach ($matches as $match) {
    $product = $match['product'];
    printf(
        "Antigo #%d -> Atual #%d | %d imagem(ns) | %d já cadastrada(s)\n  %s\n",
        $match['old_id'],
        $product['id'],
        count($match['images']),
        $product['existing_images'],
        $product['name']
    );
}
foreach ($unmatched as $item) printf("SEM CORRESPONDÊNCIA: antigo #%d | %d imagem(ns) | %s\n", $item['old_id'], $item['images'], $item['name']);
foreach ($ambiguous as $item) printf("CORRESPONDÊNCIA AMBÍGUA: antigo #%d | %s\n", $item['old_id'], $item['name']);

$matchedImageCount = array_sum(array_map(static fn(array $match): int => count($match['images']), $matches));
printf("\nResumo: %d produto(s), %d imagem(ns), %d sem correspondência e %d ambíguo(s).\n", count($matches), $matchedImageCount, count($unmatched), count($ambiguous));

if (!$execute) {
    echo "\nPara executar após revisar: php scripts/import_legacy_product_images.php --execute\n";
    exit(0);
}

$repository = new ProductMediaRepository();
$existingPublicIds = array_fill_keys($pdo->query('SELECT public_id FROM product_media')->fetchAll(PDO::FETCH_COLUMN), true);
$summary = ['imported' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($matches as $match) {
    $product = $match['product'];
    $images = $match['images'];
    usort($images, static fn(array $left, array $right): int => [$left['sort_order'], $left['id']] <=> [$right['sort_order'], $right['id']]);

    $maxSort = $pdo->prepare("SELECT COALESCE(MAX(sort_order),-1) FROM product_media WHERE product_id=? AND resource_type='image'");
    $maxSort->execute([$product['id']]);
    $nextSort = (int) $maxSort->fetchColumn() + 1;
    $needsCover = (int) $product['existing_covers'] === 0;
    $preferredCoverId = null;
    foreach ($images as $image) if ($image['is_main']) { $preferredCoverId = $image['id']; break; }
    $preferredCoverId ??= $images[0]['id'] ?? null;

    foreach ($images as $image) {
        $storeSlug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', mb_strtolower((string) $product['store_slug'])), '-');
        $publicId = sprintf('stores/%s-%d/products/%d/legacy-%d', $storeSlug, $product['store_id'], $product['id'], $image['id']);
        if (isset($existingPublicIds[$publicId])) {
            printf("[ignorada] Produto #%d, imagem antiga #%d já importada.\n", $product['id'], $image['id']);
            $summary['skipped']++;
            continue;
        }

        try {
            $response = uploadToCloudinary($image, $product);
            $isCover = $needsCover && (int) $image['id'] === (int) $preferredCoverId;
            $repository->create((int) $product['id'], null, [
                'resource_type' => 'image',
                'public_id' => $response['public_id'],
                'url' => $response['url'] ?? $response['secure_url'],
                'secure_url' => $response['secure_url'],
                'thumbnail_url' => $response['secure_url'],
                'format' => $response['format'] ?? null,
                'width' => $response['width'] ?? null,
                'height' => $response['height'] ?? null,
                'bytes' => $response['bytes'] ?? null,
                'sort_order' => $nextSort++,
                'is_cover' => $isCover,
            ]);
            if ($isCover) $needsCover = false;
            $existingPublicIds[$publicId] = true;
            $summary['imported']++;
            printf("[importada] Produto #%d, imagem antiga #%d.\n", $product['id'], $image['id']);
        } catch (Throwable $exception) {
            $summary['failed']++;
            printf("[falhou] Produto #%d, imagem antiga #%d: %s\n", $product['id'], $image['id'], $exception->getMessage());
        }
    }

    if ($needsCover) {
        $cover = $pdo->prepare("SELECT id FROM product_media WHERE product_id=? AND resource_type='image' ORDER BY sort_order,id LIMIT 1");
        $cover->execute([$product['id']]);
        $coverId = (int) $cover->fetchColumn();
        if ($coverId > 0) $pdo->prepare('UPDATE product_media SET is_cover=1 WHERE id=? AND product_id=?')->execute([$coverId, $product['id']]);
    }
}

printf("\nConcluído: %d importada(s), %d ignorada(s), %d falha(s).\n", $summary['imported'], $summary['skipped'], $summary['failed']);
exit($summary['failed'] > 0 ? 2 : 0);
