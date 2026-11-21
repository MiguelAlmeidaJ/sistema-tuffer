<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BrandManagementExperienceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root=dirname(__DIR__,2);
    }

    public function testAdminBrandRoutesRequireAdministratorAndCsrf(): void
    {
        $routes=file_get_contents($this->root.'/routes/admin.php');
        self::assertStringContainsString('BrandController::class', $routes);
        self::assertStringContainsString("'/marcas'", $routes);
        self::assertStringContainsString("'/marcas/{id}'", $routes);
        self::assertStringContainsString("['csrf']", $routes);
        self::assertStringContainsString("'role:admin'", $routes);
        $sidebar=file_get_contents($this->root.'/resources/views/components/dashboard/sidebar.php');
        self::assertStringContainsString("'/admin/marcas'", $sidebar);
    }

    public function testBrandEditorValidatesImageAndKeepsExistingProducts(): void
    {
        $controller=file_get_contents($this->root.'/app/Http/Controllers/Admin/BrandController.php');
        self::assertStringContainsString('SiteUploadService', $controller);
        self::assertStringContainsString("'platform/site'", $controller);
        self::assertStringContainsString('cover_image_path', $controller);
        self::assertStringNotContainsString('DELETE FROM products', $controller);
        $form=file_get_contents($this->root.'/resources/views/admin/brands/form.php');
        self::assertStringContainsString('enctype="multipart/form-data"', $form);
        self::assertStringContainsString('csrf_field()', $form);
        self::assertStringContainsString('name="cover"', $form);
    }

    public function testHomepageUsesBrandCoverAndNoLetterPlaceholder(): void
    {
        $home=file_get_contents($this->root.'/resources/views/public/home.php');
        $controller=file_get_contents($this->root.'/app/Http/Controllers/Public/HomeController.php');
        self::assertStringNotContainsString('home-categories__placeholder', $home);
        self::assertStringContainsString('home-categories__empty-media', $home);
        self::assertStringContainsString('cover_image_path', $home);
        self::assertStringContainsString('SELECT name,slug,cover_image_path FROM brands', $controller);
        self::assertStringContainsString('data-product-progress', $home);
        self::assertStringContainsString('data-product-next', $home);
    }

    public function testBrandCoverMigrationIsNonDestructive(): void
    {
        $sql=file_get_contents($this->root.'/database/migrations/051_add_brand_cover.sql');
        self::assertStringContainsString('ALTER TABLE brands ADD COLUMN cover_image_path', $sql);
        self::assertStringNotContainsString('DROP TABLE', $sql);
    }
}
