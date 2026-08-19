<?php

declare(strict_types=1);

use App\Core\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$environment = strtolower(trim((string) ($_ENV['APP_ENV'] ?? 'local')));
$allowSeed = filter_var($_ENV['ALLOW_DATABASE_SEED'] ?? false, FILTER_VALIDATE_BOOL);
$seedDemoData = filter_var($_ENV['SEED_DEMO_DATA'] ?? false, FILTER_VALIDATE_BOOL);
if ($environment === 'production' && !$allowSeed) {
    throw new RuntimeException('Seed bloqueado em produção. Defina ALLOW_DATABASE_SEED=true somente durante uma execução controlada.');
}

$adminEmail = mb_strtolower(trim((string) ($_ENV['ADMIN_EMAIL'] ?? '')));
$adminPassword = (string) ($_ENV['ADMIN_PASSWORD'] ?? '');
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminPassword) < 12) {
    throw new RuntimeException('Configure ADMIN_EMAIL e ADMIN_PASSWORD com uma senha de pelo menos 12 caracteres antes de executar o seed.');
}
if ($environment === 'production' && ($adminEmail === 'admin@tuffer.local' || $adminPassword === 'Admin@123!')) {
    throw new RuntimeException('Credenciais administrativas de demonstração não podem ser usadas em produção.');
}

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    $roles = [
        ['admin', 'Superadministrador'],
        ['seller', 'Vendedor'],
        ['customer', 'Cliente'],
        ['operator', 'Operador'],
    ];
    $roleStatement = $pdo->prepare('INSERT INTO roles (slug, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
    foreach ($roles as $role) {
        $roleStatement->execute($role);
    }

    $permissionStatement = $pdo->prepare('INSERT INTO permissions(slug,name) VALUES(?,?) ON DUPLICATE KEY UPDATE name=VALUES(name)');
    $permissionStatement->execute(['wholesale.manage', 'Gerenciar cadastros atacadistas']);
    $pdo->prepare("INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r JOIN permissions p ON p.slug='wholesale.manage' WHERE r.slug='admin'")->execute();

    $platformDefaults = [
        'platform_name' => 'Tuffer',
        'tagline' => 'Para todos os estilos. Para todos os dias.',
        'logo_path' => 'platform/logos/tuffer-logo.svg',
        'favicon_path' => 'platform/favicon/favicon.svg',
        'home_main_banner' => 'platform/banners/home-main.svg',
        'home_discount_banner' => 'platform/banners/home-discount.svg',
        'home_official_banner' => 'platform/banners/home-official.svg',
        'official_wide_banner' => 'platform/banners/official-wide.svg',
        'seo_title' => 'Tuffer Oficial',
        'seo_description' => 'Tuffer: produtos de lojas oficiais em uma experiência simples e segura.',
        'seo_robots' => 'index,follow',
        'default_commission' => '10.00',
        'orders_prefix' => 'TF',
    ];
    $settingStatement = $pdo->prepare("INSERT IGNORE INTO settings(scope_type,scope_id,setting_key,setting_value) VALUES('platform',0,?,?)");
    foreach ($platformDefaults as $key => $value) {
        $settingStatement->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    $userStatement = $pdo->prepare("INSERT INTO users (name, email, password_hash, type, status, email_verified_at) VALUES (?, ?, ?, 'admin', 'active', NOW()) ON DUPLICATE KEY UPDATE name = VALUES(name), updated_at = NOW()");
    $userStatement->execute([
        $_ENV['ADMIN_NAME'] ?? 'Administrador Tuffer',
        $adminEmail,
        password_hash($adminPassword, PASSWORD_DEFAULT),
    ]);

    $linkStatement = $pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT u.id, r.id FROM users u JOIN roles r ON r.slug = ? WHERE u.email = ?");
    $linkStatement->execute(['admin', $adminEmail]);

    if (!$seedDemoData) {
        $pdo->commit();
        echo "Configuração essencial criada.\n";
        echo "Administrador: {$adminEmail}\n";
        echo "Dados demonstrativos: desativados.\n";
        return;
    }

    $sellerEmail = 'vendedor@tuffer.local';
    $sellerPassword = 'Vendedor@123!';
    $sellerUserStatement = $pdo->prepare("INSERT INTO users (name, email, phone, document, password_hash, type, status, email_verified_at) VALUES (?, ?, ?, ?, ?, 'seller', 'active', NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name), phone=VALUES(phone), document=VALUES(document), password_hash=VALUES(password_hash), status='active', updated_at=NOW()");
    $sellerUserStatement->execute([
        'Equipe Tuffer Oficial',
        $sellerEmail,
        '(11) 4000-1234',
        '11222333000181',
        password_hash($sellerPassword, PASSWORD_DEFAULT),
    ]);
    $sellerUserId = (int) $pdo->query("SELECT id FROM users WHERE email='vendedor@tuffer.local'")->fetchColumn();
    $linkStatement->execute(['seller', $sellerEmail]);

    $customerEmail = 'cliente@tuffer.local';
    $customerStatement = $pdo->prepare("INSERT INTO users (name,email,phone,password_hash,type,status,email_verified_at) VALUES (?,?,?,?, 'customer','active',NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),phone=VALUES(phone),password_hash=VALUES(password_hash),status='active',updated_at=NOW()");
    $customerStatement->execute(['Cliente Tuffer', $customerEmail, '(11) 99999-0000', password_hash('Cliente@123!', PASSWORD_DEFAULT)]);
    $linkStatement->execute(['customer', $customerEmail]);

    $sellerStatement = $pdo->prepare("INSERT INTO sellers (user_id, legal_name, trade_name, document, commission_rate, pagarme_recipient_id, payment_onboarding_status, payment_enabled, status, approved_at) VALUES (?, ?, ?, ?, 10.00, 'rp_DEMOTUFFER001', 'active', 1, 'active', NOW()) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), legal_name=VALUES(legal_name), trade_name=VALUES(trade_name), commission_rate=VALUES(commission_rate), pagarme_recipient_id=VALUES(pagarme_recipient_id), payment_onboarding_status='active', payment_enabled=1, payment_block_reason=NULL, status='active', approved_at=COALESCE(approved_at, NOW()), updated_at=NOW()");
    $sellerStatement->execute([$sellerUserId, 'Tuffer Comércio de Vestuário LTDA', 'Tuffer Oficial', '11222333000181']);
    $sellerId = (int) $pdo->query("SELECT id FROM sellers WHERE document='11222333000181'")->fetchColumn();
    $pdo->prepare("INSERT INTO seller_payment_accounts (seller_id,provider,environment,recipient_id,recipient_status,kyc_status,kyc_status_reason,registration_type,bank_code,bank_branch_masked,bank_account_masked,bank_account_type,onboarding_status,enabled_for_sales,last_synced_at,approved_at) VALUES (?,'pagarme','test','rp_DEMOTUFFER001','active','approved','ok','corporation','000','***1','*****-1','checking','active',1,NOW(),NOW()) ON DUPLICATE KEY UPDATE recipient_status='active',kyc_status='approved',kyc_status_reason='ok',onboarding_status='active',enabled_for_sales=1,last_synced_at=NOW(),approved_at=COALESCE(approved_at,NOW())")->execute([$sellerId]);

    $storeStatement = $pdo->prepare("INSERT INTO stores (seller_id, name, slug, description, logo_url, banner_url, status) VALUES (?, ?, ?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE seller_id=VALUES(seller_id), name=VALUES(name), description=VALUES(description), logo_url=VALUES(logo_url), banner_url=VALUES(banner_url), status='active', updated_at=NOW()");
    $storeStatement->execute([
        $sellerId,
        'Tuffer Oficial',
        'tuffer-oficial',
        'Moda íntima Tuffer para todos os estilos e todos os dias.',
        null,
        null,
    ]);
    $storeId = (int) $pdo->query("SELECT id FROM stores WHERE slug='tuffer-oficial'")->fetchColumn();
    $pdo->prepare('UPDATE stores SET logo_url=?,banner_url=? WHERE id=?')->execute([
        'stores/tuffer-oficial-' . $storeId . '/logo.svg',
        'stores/tuffer-oficial-' . $storeId . '/banner.svg',
        $storeId,
    ]);

    $operatorEmail = 'operador@tuffer.local';
    $operatorStatement = $pdo->prepare("INSERT INTO users (name,email,password_hash,type,status,email_verified_at) VALUES (?,?,?,'operator','active',NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),password_hash=VALUES(password_hash),status='active',updated_at=NOW()");
    $operatorStatement->execute(['Operador Tuffer', $operatorEmail, password_hash('Operador@123!', PASSWORD_DEFAULT)]);
    $operatorId = (int) $pdo->query("SELECT id FROM users WHERE email='operador@tuffer.local'")->fetchColumn();
    $linkStatement->execute(['operator', $operatorEmail]);
    $pdo->prepare("INSERT INTO store_users(store_id,user_id,role) VALUES(?,?,'manager') ON DUPLICATE KEY UPDATE role='manager'")->execute([$storeId, $operatorId]);

    $categories = [
        ['Calcinhas', 'calcinhas', 10],
        ['Cuecas', 'cuecas', 20],
        ['Moda Feminina', 'moda-feminina', 30],
        ['Moda Masculina', 'moda-masculina', 40],
        ['Kits', 'kits', 50],
        ['Boxer', 'boxer', 60],
        ['Slip', 'slip', 70],
        ['Samba Canção', 'samba-cancao', 80],
    ];
    $categoryStatement = $pdo->prepare("INSERT INTO categories (name, slug, sort_order, status) VALUES (?, ?, ?, 'active') ON DUPLICATE KEY UPDATE name=VALUES(name), sort_order=VALUES(sort_order), status='active', updated_at=NOW()");
    foreach ($categories as $category) {
        $categoryStatement->execute($category);
    }

    $pdo->prepare("INSERT INTO brands (name, slug, status) VALUES ('Tuffer', 'tuffer', 'active') ON DUPLICATE KEY UPDATE name='Tuffer', status='active', updated_at=NOW()")->execute();
    $brandId = (int) $pdo->query("SELECT id FROM brands WHERE slug='tuffer'")->fetchColumn();

    $warehouseQuery = $pdo->prepare('SELECT id FROM warehouses WHERE seller_id=? AND name=? LIMIT 1');
    $warehouseQuery->execute([$sellerId, 'Estoque principal']);
    $warehouseId = (int) $warehouseQuery->fetchColumn();
    if ($warehouseId === 0) {
        $pdo->prepare("INSERT INTO warehouses (seller_id, name, postal_code, city, state, status) VALUES (?, 'Estoque principal', '01310900', 'São Paulo', 'SP', 'active')")->execute([$sellerId]);
        $warehouseId = (int) $pdo->lastInsertId();
    }

    $products = [
        [
            'name' => 'Kit com 10 Cuecas Plus Size em Microfibra Premium',
            'slug' => 'kit-10-cuecas-plus-size-microfibra-premium',
            'sku' => 'TUF-KIT10-PS-MIC',
            'short' => 'Kit masculino plus size com dez cuecas em microfibra premium.',
            'description' => 'Conforto, respirabilidade e ajuste seguro para todos os dias. Kit com cores variadas e acabamento reforçado.',
            'price' => 159.90,
            'promotional_price' => 135.92,
            'stock' => 36,
            'categories' => ['cuecas', 'kits', 'moda-masculina'],
            'featured' => true,
        ],
        [
            'name' => 'Kit com 10 Calcinhas em Cotton Estampado Tanga',
            'slug' => 'kit-10-calcinhas-cotton-estampado-tanga',
            'sku' => 'TUF-KIT10-CAL-TAN',
            'short' => 'Dez calcinhas tanga em cotton macio com estampas variadas.',
            'description' => 'Modelagem confortável, toque macio e elástico que acompanha o corpo sem apertar.',
            'price' => 79.90,
            'promotional_price' => 64.50,
            'stock' => 42,
            'categories' => ['calcinhas', 'kits', 'moda-feminina'],
            'featured' => true,
        ],
        [
            'name' => 'Kit 5 Tangão Plus Size em Cotton Liso Cós Alto',
            'slug' => 'kit-5-tangao-plus-size-cotton-liso-cos-alto',
            'sku' => 'TUF-KIT5-TAN-PS',
            'short' => 'Kit plus size com cinco peças em cotton de cós alto.',
            'description' => 'Cobertura, maciez e segurança em uma modelagem pensada para acompanhar os movimentos.',
            'price' => 59.90,
            'promotional_price' => 49.90,
            'stock' => 28,
            'categories' => ['calcinhas', 'kits', 'moda-feminina'],
            'featured' => true,
        ],
        [
            'name' => 'Kit 5 Cuecas Boxer Plus Size em Microfibra',
            'slug' => 'kit-5-cuecas-boxer-plus-size-microfibra',
            'sku' => 'TUF-KIT5-BOX-PS',
            'short' => 'Cinco cuecas boxer plus size leves e resistentes.',
            'description' => 'Microfibra de secagem rápida, costuras reforçadas e elástico personalizado Tuffer.',
            'price' => 99.90,
            'promotional_price' => 84.92,
            'stock' => 31,
            'categories' => ['cuecas', 'boxer', 'kits', 'moda-masculina'],
            'featured' => true,
        ],
        [
            'name' => 'Kit 3 Cuecas Boxer Microfibra Tuffer',
            'slug' => 'kit-3-cuecas-boxer-microfibra-tuffer',
            'sku' => 'TUF-KIT3-BOX-MIC',
            'short' => 'Três cuecas boxer essenciais para a rotina.',
            'description' => 'Modelagem anatômica em microfibra, com caimento confortável e cores versáteis.',
            'price' => 69.90,
            'promotional_price' => null,
            'stock' => 54,
            'categories' => ['cuecas', 'boxer', 'kits', 'moda-masculina'],
            'featured' => false,
        ],
        [
            'name' => 'Kit 6 Calcinhas Cotton Conforto Tuffer',
            'slug' => 'kit-6-calcinhas-cotton-conforto-tuffer',
            'sku' => 'TUF-KIT6-CAL-COT',
            'short' => 'Seis calcinhas em cotton para conforto diário.',
            'description' => 'Tecido respirável, modelagem clássica e acabamento delicado para uso prolongado.',
            'price' => 74.90,
            'promotional_price' => 67.41,
            'stock' => 47,
            'categories' => ['calcinhas', 'kits', 'moda-feminina'],
            'featured' => false,
        ],
    ];

    $productStatement = $pdo->prepare("INSERT INTO products (seller_id, store_id, brand_id, name, slug, sku, description, short_description, product_type, status, featured, weight, width, height, length) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'simple', 'active', ?, 0.400, 20.00, 8.00, 28.00) ON DUPLICATE KEY UPDATE seller_id=VALUES(seller_id), store_id=VALUES(store_id), brand_id=VALUES(brand_id), name=VALUES(name), sku=VALUES(sku), description=VALUES(description), short_description=VALUES(short_description), status='active', featured=VALUES(featured), updated_at=NOW()");
    $variantStatement = $pdo->prepare("INSERT INTO product_variants (product_id, sku, name, price, promotional_price, weight, width, height, length, status) VALUES (?, ?, 'Padrão', ?, ?, 0.400, 20.00, 8.00, 28.00, 'active') ON DUPLICATE KEY UPDATE product_id=VALUES(product_id), price=VALUES(price), promotional_price=VALUES(promotional_price), status='active', updated_at=NOW()");
    $categoryLinkStatement = $pdo->prepare('INSERT IGNORE INTO product_categories (product_id, category_id) SELECT ?, id FROM categories WHERE slug=?');
    $stockStatement = $pdo->prepare('INSERT INTO stocks (warehouse_id, product_variant_id, quantity, reserved_quantity, minimum_quantity) VALUES (?, ?, ?, 0, 5) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity), minimum_quantity=VALUES(minimum_quantity), updated_at=NOW()');

    foreach ($products as $product) {
        $productStatement->execute([
            $sellerId,
            $storeId,
            $brandId,
            $product['name'],
            $product['slug'],
            $product['sku'],
            $product['description'],
            $product['short'],
            $product['featured'] ? 1 : 0,
        ]);
        $productIdStatement = $pdo->prepare('SELECT id FROM products WHERE slug=?');
        $productIdStatement->execute([$product['slug']]);
        $productId = (int) $productIdStatement->fetchColumn();

        $variantStatement->execute([$productId, $product['sku'] . '-PAD', $product['price'], $product['promotional_price']]);
        $variantIdStatement = $pdo->prepare('SELECT id FROM product_variants WHERE sku=?');
        $variantIdStatement->execute([$product['sku'] . '-PAD']);
        $variantId = (int) $variantIdStatement->fetchColumn();

        foreach ($product['categories'] as $categorySlug) {
            $categoryLinkStatement->execute([$productId, $categorySlug]);
        }
        $stockStatement->execute([$warehouseId, $variantId, $product['stock']]);
    }

    $pdo->commit();
    echo "Dados iniciais criados.\n";
    echo "Administrador: {$adminEmail}\n";
    echo "Vendedor: {$sellerEmail}\n";
    echo "Cliente: {$customerEmail}\n";
    echo "Operador: {$operatorEmail}\n";
    echo "Loja: Tuffer Oficial\n";
    echo 'Produtos ativos: ' . count($products) . "\n";
} catch (Throwable $exception) {
    $pdo->rollBack();
    throw $exception;
}
