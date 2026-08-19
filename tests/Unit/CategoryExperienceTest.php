<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Admin\CategoryController;
use App\Services\Media\MediaStoragePolicy;
use App\Services\Media\SiteUploadService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class CategoryExperienceTest extends TestCase
{
    public function testCategoryDirectoryAcceptsOnlyManagedGeneratedImages(): void
    {
        self::assertContains('platform/category', MediaStoragePolicy::config()['site']['directories']);
        $directory = MediaStoragePolicy::siteMediaRoot() . '/platform/category';
        if (!is_dir($directory)) self::assertTrue(mkdir($directory, 0755, true) || is_dir($directory));
        $filename = str_repeat('c', 32) . '.webp';
        $absolutePath = $directory . '/' . $filename;
        file_put_contents($absolutePath, 'test');

        try {
            self::assertFalse((new SiteUploadService())->deleteManaged('platform/category/manual-name.webp'));
            self::assertTrue((new SiteUploadService())->deleteManaged('platform/category/' . $filename));
            self::assertFileDoesNotExist($absolutePath);
        } finally {
            if (is_file($absolutePath)) unlink($absolutePath);
        }
    }

    public function testCategoryInputCreatesCleanSlugAndExplicitVisibilityFlags(): void
    {
        $previousPost = $_POST;
        $_POST = [
            'name' => 'Tomara que Caia',
            'slug' => '',
            'sort_order' => '20',
            'status' => 'active',
            'show_in_home' => '1',
            'allow_products' => '1',
            'customer_visible' => '1',
        ];

        try {
            $method = new ReflectionMethod(CategoryController::class, 'validatedInput');
            $data = $method->invoke(new CategoryController());
            self::assertSame('tomara-que-caia', $data['slug']);
            self::assertSame(1, $data['show_in_home']);
            self::assertSame(0, $data['is_featured']);
            self::assertSame(1, $data['allow_products']);
            self::assertSame(1, $data['customer_visible']);
        } finally {
            $_POST = $previousPost;
        }
    }

    public function testCategoryInputRejectsUnknownStatus(): void
    {
        $previousPost = $_POST;
        $_POST = ['name' => 'Categoria', 'status' => 'deleted'];

        try {
            $method = new ReflectionMethod(CategoryController::class, 'validatedInput');
            $this->expectException(RuntimeException::class);
            $method->invoke(new CategoryController());
        } finally {
            $_POST = $previousPost;
        }
    }
}
