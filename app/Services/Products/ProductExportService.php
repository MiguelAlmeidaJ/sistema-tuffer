<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Core\Database;
use App\Core\Session;
use RuntimeException;
use SimpleXMLElement;
use XMLWriter;

final class ProductExportService
{
    private const SESSION_KEY = 'seller_product_export_stage';
    private const MAX_FILE_SIZE = 5_242_880;
    private const MAX_ROWS = 5_000;
    private const MAX_COLUMNS = 60;

    /** @var array<string,string> */
    private const CATALOG_COLUMNS = [
        'product_id' => 'ID do produto',
        'name' => 'Produto',
        'slug' => 'Slug',
        'product_sku' => 'SKU do produto',
        'variant_sku' => 'SKU da variação',
        'variant_name' => 'Variação',
        'product_type' => 'Tipo',
        'status' => 'Status',
        'category' => 'Categorias',
        'brand' => 'Marca',
        'price' => 'Preço',
        'promotional_price' => 'Preço promocional',
        'wholesale_price' => 'Preço de atacado',
        'stock' => 'Estoque disponível',
        'weight' => 'Peso (kg)',
        'width' => 'Largura (cm)',
        'height' => 'Altura (cm)',
        'length' => 'Comprimento (cm)',
        'short_description' => 'Descrição curta',
        'description' => 'Descrição',
        'updated_at' => 'Atualizado em',
    ];

    /** @return array<string,mixed> */
    public function currentOrCatalog(array $store): array
    {
        return $this->current((int) $store['id']) ?? $this->useCatalog($store);
    }

    /** @return array<string,mixed>|null */
    public function current(int $storeId): ?array
    {
        $meta = Session::get(self::SESSION_KEY);
        if (!is_array($meta) || (int) ($meta['store_id'] ?? 0) !== $storeId) return null;
        $id = (string) ($meta['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) return null;
        $path = $this->directory() . DIRECTORY_SEPARATOR . $id . '.json';
        if (!is_file($path)) return null;
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) && (int) ($decoded['store_id'] ?? 0) === $storeId ? $decoded : null;
    }

    /** @return array<string,mixed> */
    public function useCatalog(array $store): array
    {
        $statement = Database::connection()->prepare("SELECT p.id product_id,p.name,p.slug,p.sku product_sku,pv.sku variant_sku,pv.name variant_name,p.product_type,p.status,(SELECT GROUP_CONCAT(c.name ORDER BY c.name SEPARATOR ', ') FROM product_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.product_id=p.id) category,b.name brand,pv.price,pv.promotional_price,pv.wholesale_price,COALESCE((SELECT SUM(sk.quantity-sk.reserved_quantity) FROM stocks sk WHERE sk.product_variant_id=pv.id),0) stock,COALESCE(pv.weight,p.weight) weight,COALESCE(pv.width,p.width) width,COALESCE(pv.height,p.height) height,COALESCE(pv.length,p.length) length,p.short_description,p.description,p.updated_at FROM products p LEFT JOIN brands b ON b.id=p.brand_id LEFT JOIN product_variants pv ON pv.product_id=p.id WHERE p.store_id=? ORDER BY p.updated_at DESC,p.id,pv.id LIMIT " . self::MAX_ROWS);
        $statement->execute([(int) $store['id']]);
        $rows = array_map(fn(array $row): array => $this->normalizeRow($row, array_keys(self::CATALOG_COLUMNS)), $statement->fetchAll());
        return $this->save([
            'store_id' => (int) $store['id'],
            'store_name' => (string) $store['name'],
            'source_type' => 'catalog',
            'source_name' => 'Catálogo atual da loja',
            'headers' => array_keys(self::CATALOG_COLUMNS),
            'labels' => self::CATALOG_COLUMNS,
            'rows' => $rows,
        ]);
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public function useUpload(array $store, array $file): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecione um arquivo CSV ou XML válido.');
        if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > self::MAX_FILE_SIZE) throw new RuntimeException('O arquivo deve ter no máximo 5 MB.');
        $path = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($path)) throw new RuntimeException('Não foi possível validar o arquivo enviado.');
        $name = basename((string) ($file['name'] ?? 'produtos'));
        $extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $parsed = match ($extension) {
            'csv' => $this->parseCsv($path),
            'xml' => $this->parseXml($path),
            'sql' => $this->parseSql($path),
            default => throw new RuntimeException('Formato não aceito. Envie uma planilha CSV, um XML ou um dump SQL com instruções INSERT.'),
        };
        [$headers, $labels, $rows] = $parsed;
        if (!$rows) throw new RuntimeException('O arquivo não possui linhas de produtos para organizar.');
        $dataset = [
            'store_id' => (int) $store['id'],
            'store_name' => (string) $store['name'],
            'source_type' => 'upload',
            'source_name' => $name,
            'original_name' => $name,
            'headers' => $headers,
            'labels' => $labels,
            'rows' => $rows,
        ];
        if ($extension === 'sql') {
            $dataset['sql_tables'] = $parsed[3] ?? [];
            $dataset['selected_table'] = $parsed[4] ?? null;
            $dataset['source_name'] .= !empty($dataset['selected_table']) ? ' · ' . $dataset['selected_table'] : '';
        }
        return $this->save($dataset);
    }

    /** @return array<string,mixed> */
    public function selectSqlTable(array $store, string $table): array
    {
        $stage = $this->current((int) $store['id']);
        $tables = is_array($stage['sql_tables'] ?? null) ? $stage['sql_tables'] : [];
        if (!$stage || !isset($tables[$table]) || !is_array($tables[$table])) throw new RuntimeException('A tabela selecionada não está disponível neste SQL.');
        $selected = $tables[$table];
        $stage['headers'] = $selected['headers'];
        $stage['labels'] = $selected['labels'];
        $stage['rows'] = $selected['rows'];
        $stage['selected_table'] = $table;
        $stage['source_name'] = (string) ($stage['original_name'] ?? 'Arquivo SQL') . ' · ' . $table;
        return $this->save($stage);
    }

    /** @param array<string,mixed> $stage @param array<int,string> $columns @param array<int,int> $rowIndexes @return array{content:string,mime:string,extension:string} */
    public function render(array $stage, string $format, array $columns, array $rowIndexes): array
    {
        $available = array_values(array_filter($stage['headers'] ?? [], 'is_string'));
        $columns = array_values(array_unique(array_filter($columns, static fn(string $column): bool => in_array($column, $available, true))));
        if (!$columns) throw new RuntimeException('Selecione ao menos uma coluna para exportar.');
        $allRows = is_array($stage['rows'] ?? null) ? $stage['rows'] : [];
        $rows = [];
        foreach (array_values(array_unique($rowIndexes)) as $index) {
            if (isset($allRows[$index]) && is_array($allRows[$index])) $rows[] = $allRows[$index];
        }
        if (!$rows) throw new RuntimeException('Selecione ao menos um produto para exportar.');
        $labels = is_array($stage['labels'] ?? null) ? $stage['labels'] : [];
        return match ($format) {
            'csv' => ['content' => $this->csv($rows, $columns, $labels), 'mime' => 'text/csv; charset=UTF-8', 'extension' => 'csv'],
            'sql' => ['content' => $this->sql($rows, $columns), 'mime' => 'application/sql; charset=UTF-8', 'extension' => 'sql'],
            'xml' => ['content' => $this->xml($rows, $columns, (string) ($stage['store_name'] ?? 'Loja')), 'mime' => 'application/xml; charset=UTF-8', 'extension' => 'xml'],
            default => throw new RuntimeException('Escolha um formato de exportação válido.'),
        };
    }

    public function clear(): void
    {
        $meta = Session::get(self::SESSION_KEY);
        if (is_array($meta) && preg_match('/^[a-f0-9]{32}$/', (string) ($meta['id'] ?? ''))) {
            $path = $this->directory() . DIRECTORY_SEPARATOR . $meta['id'] . '.json';
            if (is_file($path)) @unlink($path);
        }
        Session::forget(self::SESSION_KEY);
    }

    /** @return array{0:array<int,string>,1:array<string,string>,2:array<int,array<string,string>>} */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('Não foi possível ler a planilha.');
        $firstLine = (string) fgets($handle);
        $delimiter = $this->detectDelimiter($firstLine);
        rewind($handle);
        $rawHeaders = fgetcsv($handle, 0, $delimiter);
        if (!is_array($rawHeaders) || !$rawHeaders) { fclose($handle); throw new RuntimeException('A planilha precisa ter uma linha de cabeçalho.'); }
        [$headers, $labels] = $this->headers($rawHeaders);
        $rows = [];
        while (($values = fgetcsv($handle, 0, $delimiter)) !== false && count($rows) < self::MAX_ROWS) {
            if (!array_filter($values, static fn(mixed $value): bool => trim((string) $value) !== '')) continue;
            $row = [];
            foreach ($headers as $index => $header) $row[$header] = mb_substr(trim((string) ($values[$index] ?? '')), 0, 20_000);
            $rows[] = $row;
        }
        fclose($handle);
        return [$headers, $labels, $rows];
    }

    /** @return array{0:array<int,string>,1:array<string,string>,2:array<int,array<string,string>>} */
    private function parseXml(string $path): array
    {
        $content = (string) file_get_contents($path);
        if (stripos($content, '<!DOCTYPE') !== false) throw new RuntimeException('XML com DOCTYPE não é aceito.');
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        if (!$xml) { libxml_clear_errors(); throw new RuntimeException('O XML enviado não é válido.'); }
        $nodes = $xml->xpath('//*[local-name()="product" or local-name()="produto" or local-name()="row" or local-name()="item"]') ?: [];
        if (!$nodes) foreach ($xml->children() as $child) if ($child->count() > 0) $nodes[] = $child;
        $rawRows = [];
        $rawHeaders = [];
        foreach ($nodes as $node) {
            if (count($rawRows) >= self::MAX_ROWS) break;
            $row = [];
            foreach ($node->children() as $child) {
                $label = $child->getName();
                if (!array_key_exists($label, $row)) $row[$label] = mb_substr(trim((string) $child), 0, 20_000);
                if (!in_array($label, $rawHeaders, true)) $rawHeaders[] = $label;
            }
            if ($row) $rawRows[] = $row;
        }
        if (!$rawHeaders) throw new RuntimeException('Não encontramos registros de produtos no XML.');
        [$headers, $labels, $mapping] = $this->headers($rawHeaders, true);
        $rows = [];
        foreach ($rawRows as $rawRow) {
            $row = [];
            foreach ($mapping as $original => $header) $row[$header] = (string) ($rawRow[$original] ?? '');
            $rows[] = $row;
        }
        return [$headers, $labels, $rows];
    }

    /** @return array{0:array<int,string>,1:array<string,string>,2:array<int,array<string,string>>,3:array<string,array<string,mixed>>,4:string} */
    private function parseSql(string $path): array
    {
        $content = (string) file_get_contents($path);
        $tables = [];
        foreach ($this->sqlStatements($content) as $statement) {
            if (stripos($statement, 'INSERT INTO') === false) continue;
            if (!preg_match('/INSERT\s+INTO\s+((?:[a-zA-Z0-9_]+|`[^`]+`|"[^"]+")(?:\s*\.\s*(?:[a-zA-Z0-9_]+|`[^`]+`|"[^"]+"))?)\s*(?:\((.*?)\))?\s*VALUES\s*(.*)$/is', trim($statement), $matches)) continue;
            $table = $this->sqlIdentifier($matches[1]);
            $columnList = trim((string) ($matches[2] ?? ''));
            $valuesSql = (string) ($matches[3] ?? '');
            $rows = $this->sqlTuples($valuesSql, null);
            if (!$rows) continue;
            $columnCount = count($rows[0]);
            if ($columnCount > self::MAX_COLUMNS) continue;
            $columns = $columnList !== '' ? $this->sqlColumns($columnList) : $this->legacyColumns($table, $columnCount);
            if (count($columns) !== $columnCount) throw new RuntimeException("A quantidade de colunas da tabela {$table} não corresponde aos valores.");
            [$headers, $labels] = $this->headers($columns);
            if (!isset($tables[$table])) $tables[$table] = ['headers' => $headers, 'labels' => $this->sqlLabels($table, $headers, $labels, $columnList === ''), 'rows' => []];
            if ($tables[$table]['headers'] !== $headers) throw new RuntimeException("Os blocos INSERT da tabela {$table} possuem colunas diferentes.");
            foreach ($rows as $values) {
                if (count($tables[$table]['rows']) >= self::MAX_ROWS) break;
                $tables[$table]['rows'][] = array_combine($headers, $values);
            }
        }
        if (!$tables) throw new RuntimeException('Nenhuma tabela com registros INSERT foi encontrada no SQL.');
        $this->enrichLegacyProducts($tables);
        $selectedTable = $this->preferredSqlTable($tables);
        $selected = $tables[$selectedTable];
        return [$selected['headers'], $selected['labels'], $selected['rows'], $tables, $selectedTable];
    }

    /** @return array<int,string> */
    private function sqlStatements(string $content): array
    {
        $statements = [];
        $buffer = '';
        $quoted = false;
        $length = strlen($content);
        for ($index = 0; $index < $length; $index++) {
            $character = $content[$index];
            $buffer .= $character;
            if ($quoted && $character === "\\" && $index + 1 < $length) { $buffer .= $content[++$index]; continue; }
            if ($character === "'") {
                if ($quoted && ($content[$index + 1] ?? '') === "'") { $buffer .= $content[++$index]; continue; }
                $quoted = !$quoted;
            }
            if ($character === ';' && !$quoted) { $statements[] = rtrim(substr($buffer, 0, -1)); $buffer = ''; }
        }
        if (trim($buffer) !== '') $statements[] = trim($buffer);
        return $statements;
    }

    /** @return array<int,array<int,string>> */
    private function sqlTuples(string $values, ?int $columnCount): array
    {
        $tuples = [];
        $length = strlen($values);
        $index = 0;
        while ($index < $length && count($tuples) < self::MAX_ROWS) {
            while ($index < $length && ($values[$index] === ',' || ctype_space($values[$index]))) $index++;
            if ($index >= $length) break;
            if ($values[$index] !== '(') throw new RuntimeException('Bloco VALUES inválido no SQL.');
            $index++;
            $row = [];
            while ($index < $length) {
                while ($index < $length && ctype_space($values[$index])) $index++;
                if (substr($values, $index, 4) === 'NULL') { $row[] = ''; $index += 4; }
                elseif (($values[$index] ?? '') === "'") {
                    $index++;
                    $value = '';
                    while ($index < $length) {
                        $character = $values[$index++];
                        if ($character === "'") {
                            if (($values[$index] ?? '') === "'") { $value .= "'"; $index++; continue; }
                            break;
                        }
                        if ($character === "\\" && $index < $length) {
                            $escaped = $values[$index++];
                            $value .= match ($escaped) { '0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => "\x1a", default => $escaped };
                            continue;
                        }
                        $value .= $character;
                    }
                    $row[] = $value;
                } else {
                    $start = $index;
                    while ($index < $length && $values[$index] !== ',' && $values[$index] !== ')') $index++;
                    $token = trim(substr($values, $start, $index - $start));
                    if ($token === '') throw new RuntimeException('O SQL contém um valor vazio ou não suportado.');
                    $row[] = strcasecmp($token, 'NULL') === 0 ? '' : $token;
                }
                while ($index < $length && ctype_space($values[$index])) $index++;
                if (($values[$index] ?? '') === ',') { $index++; continue; }
                if (($values[$index] ?? '') === ')') { $index++; break; }
                throw new RuntimeException('Separador inválido no SQL.');
            }
            if ($columnCount !== null && count($row) !== $columnCount) throw new RuntimeException('Uma linha SQL possui quantidade incorreta de valores.');
            if ($tuples && count($row) !== count($tuples[0])) throw new RuntimeException('As linhas SQL possuem quantidades diferentes de valores.');
            $tuples[] = $row;
        }
        return $tuples;
    }

    private function sqlIdentifier(string $identifier): string
    {
        $parts = preg_split('/\s*\.\s*/', trim($identifier)) ?: [];
        $parts = array_map(static fn(string $part): string => trim($part, "`\" \t\n\r"), $parts);
        return implode('.', array_filter($parts, static fn(string $part): bool => $part !== ''));
    }

    /** @return array<int,string> */
    private function sqlColumns(string $columnList): array
    {
        $columns = [];
        foreach (str_getcsv($columnList, ',', '"', '\\') as $column) {
            $column = trim((string) $column, "`\" \t\n\r");
            if ($column !== '') $columns[] = $column;
        }
        return $columns;
    }

    /** @return array<int,string> */
    private function legacyColumns(string $table, int $count): array
    {
        $name = mb_strtolower((string) preg_replace('/^.*\./', '', $table));
        $known = match ($name) {
            'products' => [1 => 'legacy_id', 2 => 'legacy_seller_id', 3 => 'legacy_store_id', 4 => 'legacy_category_id', 5 => 'legacy_brand_id', 6 => 'name', 7 => 'slug', 8 => 'short_description', 9 => 'description', 10 => 'legacy_status', 11 => 'legacy_approval_status', 12 => 'price', 13 => 'promotional_price', 14 => 'legacy_cost_price', 15 => 'sku', 16 => 'weight', 17 => 'width', 18 => 'height', 19 => 'length'],
            'product_variants' => [1 => 'legacy_variant_id', 2 => 'legacy_product_id', 3 => 'sku', 4 => 'variant_name', 5 => 'legacy_color_name', 6 => 'legacy_color_hex', 7 => 'legacy_size', 8 => 'price', 9 => 'promotional_price', 10 => 'stock', 11 => 'legacy_minimum_stock', 12 => 'legacy_status', 13 => 'weight', 14 => 'width', 15 => 'height', 16 => 'length'],
            default => [],
        };
        $columns = [];
        for ($position = 1; $position <= $count; $position++) $columns[] = $known[$position] ?? 'coluna_' . $position;
        return $columns;
    }

    /** @param array<int,string> $headers @param array<string,string> $labels @return array<string,string> */
    private function sqlLabels(string $table, array $headers, array $labels, bool $positional): array
    {
        if (!$positional) return $labels;
        $friendly = ['name' => 'Nome do produto', 'sku' => 'SKU', 'price' => 'Preço', 'promotional_price' => 'Preço promocional', 'short_description' => 'Descrição curta', 'description' => 'Descrição completa', 'stock' => 'Estoque', 'weight' => 'Peso', 'width' => 'Largura', 'height' => 'Altura', 'length' => 'Comprimento', 'variant_name' => 'Nome da variação'];
        foreach ($headers as $index => $header) $labels[$header] = ($friendly[$header] ?? ucwords(str_replace('_', ' ', $header))) . ' · coluna ' . ($index + 1);
        return $labels;
    }

    /** @param array<string,array<string,mixed>> $tables */
    private function preferredSqlTable(array $tables): string
    {
        foreach (array_keys($tables) as $table) if (mb_strtolower((string) preg_replace('/^.*\./', '', $table)) === 'products') return $table;
        $selected = array_key_first($tables);
        foreach ($tables as $table => $dataset) if (count($dataset['rows'] ?? []) > count($tables[$selected]['rows'] ?? [])) $selected = $table;
        return (string) $selected;
    }

    /** @param array<string,array<string,mixed>> $tables */
    private function enrichLegacyProducts(array &$tables): void
    {
        $productsKey = $variantsKey = null;
        foreach (array_keys($tables) as $table) {
            $base = mb_strtolower((string) preg_replace('/^.*\./', '', $table));
            if ($base === 'products') $productsKey = $table;
            if ($base === 'product_variants') $variantsKey = $table;
        }
        if ($productsKey === null || $variantsKey === null) return;
        $productHeaders = $tables[$productsKey]['headers'] ?? [];
        $variantHeaders = $tables[$variantsKey]['headers'] ?? [];
        if (!in_array('legacy_id', $productHeaders, true) || !in_array('legacy_product_id', $variantHeaders, true) || !in_array('stock', $variantHeaders, true)) return;
        $stockByProduct = [];
        foreach ($tables[$variantsKey]['rows'] ?? [] as $variant) {
            $productId = (string) ($variant['legacy_product_id'] ?? '');
            if ($productId === '') continue;
            $stockByProduct[$productId] = ($stockByProduct[$productId] ?? 0) + max(0, (int) round((float) ($variant['stock'] ?? 0)));
        }
        if (!in_array('stock', $tables[$productsKey]['headers'], true)) {
            $tables[$productsKey]['headers'][] = 'stock';
            $tables[$productsKey]['labels']['stock'] = 'Estoque total · calculado pelas variações';
        }
        foreach ($tables[$productsKey]['rows'] as &$product) $product['stock'] = (string) ($stockByProduct[(string) ($product['legacy_id'] ?? '')] ?? 0);
        unset($product);
    }

    /** @param array<int,mixed> $rawHeaders @return array{0:array<int,string>,1:array<string,string>,2?:array<string,string>} */
    private function headers(array $rawHeaders, bool $withMapping = false): array
    {
        if (count($rawHeaders) > self::MAX_COLUMNS) throw new RuntimeException('O arquivo pode ter no máximo 60 colunas.');
        $headers = [];
        $labels = [];
        $mapping = [];
        foreach ($rawHeaders as $index => $rawHeader) {
            $label = trim((string) $rawHeader, "\xEF\xBB\xBF \t\n\r\0\x0B");
            if ($label === '') $label = 'Coluna ' . ($index + 1);
            $base = $this->columnKey($label) ?: 'coluna_' . ($index + 1);
            if (preg_match('/^\d/', $base)) $base = 'campo_' . $base;
            $key = $base;
            $suffix = 2;
            while (in_array($key, $headers, true)) $key = $base . '_' . $suffix++;
            $headers[] = $key;
            $labels[$key] = $label;
            $mapping[(string) $rawHeader] = $key;
        }
        return $withMapping ? [$headers, $labels, $mapping] : [$headers, $labels];
    }

    private function detectDelimiter(string $line): string
    {
        $delimiters = ["\t", ';', ','];
        $best = ';';
        $score = 0;
        foreach ($delimiters as $delimiter) {
            $count = count(str_getcsv($line, $delimiter));
            if ($count > $score) { $score = $count; $best = $delimiter; }
        }
        return $best;
    }

    private function columnKey(string $label): string
    {
        $normalized = strtr(mb_strtolower(trim($label)), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
        ]);
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $normalized), '_');
    }

    /** @param array<string,mixed> $dataset @return array<string,mixed> */
    private function save(array $dataset): array
    {
        $this->clear();
        $this->cleanup();
        $dataset['created_at'] = date(DATE_ATOM);
        $json = json_encode($dataset, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (strlen($json) > 12_000_000) throw new RuntimeException('O arquivo organizado ficou grande demais para esta exportação.');
        $id = bin2hex(random_bytes(16));
        $path = $this->directory() . DIRECTORY_SEPARATOR . $id . '.json';
        if (file_put_contents($path, $json, LOCK_EX) === false) throw new RuntimeException('Não foi possível preparar a exportação.');
        Session::put(self::SESSION_KEY, ['id' => $id, 'store_id' => (int) $dataset['store_id']]);
        return $dataset;
    }

    private function directory(): string
    {
        $directory = dirname(__DIR__, 3) . '/storage/private/product-exports';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('Não foi possível criar a área temporária de exportação.');
        return $directory;
    }

    private function cleanup(): void
    {
        foreach (glob($this->directory() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < time() - 86_400) @unlink($path);
        }
    }

    /** @param array<string,mixed> $row @param array<int,string> $headers @return array<string,string> */
    private function normalizeRow(array $row, array $headers): array
    {
        $normalized = [];
        foreach ($headers as $header) $normalized[$header] = $row[$header] === null ? '' : (string) ($row[$header] ?? '');
        return $normalized;
    }

    /** @param array<int,array<string,mixed>> $rows @param array<int,string> $columns @param array<string,string> $labels */
    private function csv(array $rows, array $columns, array $labels): string
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_map(static fn(string $column): string => $labels[$column] ?? $column, $columns), ';', '"', '\\', "\r\n");
        foreach ($rows as $row) fputcsv($stream, array_map(fn(string $column): string => $this->spreadsheetCell((string) ($row[$column] ?? '')), $columns), ';', '"', '\\', "\r\n");
        rewind($stream);
        $content = (string) stream_get_contents($stream);
        fclose($stream);
        return $content;
    }

    private function spreadsheetCell(string $value): string
    {
        return preg_match('/^[\t\r\n ]*[=+\-@]/u', $value) ? "'" . $value : $value;
    }

    /** @param array<int,array<string,mixed>> $rows @param array<int,string> $columns */
    private function sql(array $rows, array $columns): string
    {
        $table = 'tuffer_product_export';
        $quotedColumns = array_map(static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`', $columns);
        $content = "-- Exportação de produtos Tuffer\nSET NAMES utf8mb4;\n\nCREATE TABLE IF NOT EXISTS `{$table}` (\n  " . implode(" LONGTEXT NULL,\n  ", $quotedColumns) . " LONGTEXT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";
        foreach (array_chunk($rows, 100) as $chunk) {
            $values = [];
            foreach ($chunk as $row) $values[] = '(' . implode(',', array_map(static fn(string $column): string => "'" . str_replace(["\\", "'", "\0", "\n", "\r", "\x1a"], ["\\\\", "''", "\\0", "\\n", "\\r", "\\Z"], (string) ($row[$column] ?? '')) . "'", $columns)) . ')';
            $content .= "INSERT INTO `{$table}` (" . implode(',', $quotedColumns) . ") VALUES\n" . implode(",\n", $values) . ";\n\n";
        }
        return $content;
    }

    /** @param array<int,array<string,mixed>> $rows @param array<int,string> $columns */
    private function xml(array $rows, array $columns, string $storeName): string
    {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('catalogo_produtos');
        $writer->writeAttribute('loja', $storeName);
        $writer->writeAttribute('gerado_em', date(DATE_ATOM));
        foreach ($rows as $row) {
            $writer->startElement('produto');
            foreach ($columns as $column) $writer->writeElement($column, (string) ($row[$column] ?? ''));
            $writer->endElement();
        }
        $writer->endElement();
        $writer->endDocument();
        return $writer->outputMemory();
    }
}
