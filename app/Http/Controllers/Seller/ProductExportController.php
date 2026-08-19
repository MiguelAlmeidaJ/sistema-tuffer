<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Core\Response;
use App\Core\Session;
use App\Http\Controllers\Controller;
use App\Services\Products\ProductExportService;
use App\Services\Products\ProductImportService;
use App\Services\Stores\SellerStoreContext;
use App\Support\Str;
use RuntimeException;
use Throwable;

final class ProductExportController extends Controller
{
    public function index(): string
    {
        $store = (new SellerStoreContext())->current();
        if (!$store) return Response::redirect('/vendedor/onboarding');
        $stage = (new ProductExportService())->currentOrCatalog($store);
        $importService = new ProductImportService();
        return $this->page('seller/products/export', 'layouts/seller', [
            'pageTitle' => 'Importar e exportar produtos',
            'currentStore' => $store,
            'stage' => $stage,
            'previewRows' => array_slice($stage['rows'] ?? [], 0, 100, true),
            'importFields' => $importService->fields(),
            'importSuggestions' => $importService->suggestions(array_values(array_filter($stage['headers'] ?? [], 'is_string'))),
            'importResult' => Session::pullFlash('product_import_result'),
        ]);
    }

    public function prepare(): string
    {
        $store = (new SellerStoreContext())->current();
        if (!$store) return Response::redirect('/vendedor/onboarding');
        $service = new ProductExportService();
        try {
            if (($_POST['source'] ?? '') === 'catalog') {
                $service->useCatalog($store);
                Session::flash('success', 'Catálogo atual carregado para organização.');
            } elseif (($_POST['source'] ?? '') === 'sql_table') {
                $stage = $service->selectSqlTable($store, (string) ($_POST['sql_table'] ?? ''));
                Session::flash('success', 'Tabela ' . $stage['selected_table'] . ' carregada para o de-para.');
            } else {
                $stage = $service->useUpload($store, is_array($_FILES['product_file'] ?? null) ? $_FILES['product_file'] : []);
                Session::flash('success', count($stage['rows']) . ' linha(s) carregada(s). Organize a prévia antes de exportar.');
            }
        } catch (Throwable $exception) {
            Session::flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível preparar o arquivo.');
        }
        return Response::redirect('/vendedor/produtos/exportar');
    }

    public function download(): string
    {
        $store = (new SellerStoreContext())->current();
        if (!$store) return Response::redirect('/vendedor/onboarding');
        $service = new ProductExportService();
        $stage = $service->current((int) $store['id']);
        if (!$stage) {
            Session::flash('error', 'A preparação expirou. Carregue os produtos novamente.');
            return Response::redirect('/vendedor/produtos/exportar');
        }
        try {
            $columns = array_values(array_filter($_POST['columns'] ?? [], 'is_string'));
            $rowMode = (string) ($_POST['row_mode'] ?? 'all');
            $rowIndexes = $rowMode === 'selected'
                ? array_values(array_filter(array_map('intval', $_POST['rows'] ?? []), static fn(int $index): bool => $index >= 0))
                : array_keys($stage['rows'] ?? []);
            $format = mb_strtolower((string) ($_POST['format'] ?? 'csv'));
            $export = $service->render($stage, $format, $columns, $rowIndexes);
            $baseName = Str::slug((string) ($_POST['file_name'] ?? 'produtos-' . $store['slug'])) ?: 'produtos';
            $fileName = $baseName . '-' . date('Ymd-His') . '.' . $export['extension'];
            header('Content-Type: ' . $export['mime']);
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . strlen($export['content']));
            header('X-Content-Type-Options: nosniff');
            return $export['content'];
        } catch (Throwable $exception) {
            Session::flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível gerar a exportação.');
            return Response::redirect('/vendedor/produtos/exportar');
        }
    }

    public function import(): string
    {
        $store = (new SellerStoreContext())->current();
        if (!$store) return Response::redirect('/vendedor/onboarding');
        $stage = (new ProductExportService())->current((int) $store['id']);
        if (!$stage) {
            Session::flash('error', 'A preparação expirou. Envie o arquivo novamente.');
            return Response::redirect('/vendedor/produtos/exportar');
        }
        try {
            if (($_POST['confirm_import'] ?? '') !== '1') throw new RuntimeException('Confirme que revisou os dados antes de importar.');
            $mapping = [];
            foreach (is_array($_POST['mapping'] ?? null) ? $_POST['mapping'] : [] as $field => $source) if (is_string($field) && is_string($source) && $source !== '') $mapping[$field] = $source;
            $result = (new ProductImportService())->import($store, $stage, $mapping, (string) ($_POST['conflict'] ?? 'skip'), (string) ($_POST['new_status'] ?? 'draft'));
            Session::flash('product_import_result', $result);
            Session::flash('success', $result['created'] . ' produto(s) criado(s), ' . $result['updated'] . ' atualizado(s) e ' . $result['skipped'] . ' ignorado(s).');
        } catch (Throwable $exception) {
            Session::flash('error', $exception instanceof RuntimeException ? $exception->getMessage() : 'Não foi possível importar os produtos.');
        }
        return Response::redirect('/vendedor/produtos/exportar');
    }
}
