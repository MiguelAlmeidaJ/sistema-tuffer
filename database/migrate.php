<?php

declare(strict_types=1);

use App\Core\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$pdo = Database::connection();
$lockName = 'tuffer_marketplace_migrations';
$lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
$lock->execute([$lockName]);
if ((int) $lock->fetchColumn() !== 1) throw new RuntimeException('Outra execução de migrações está em andamento.');

try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, migration VARCHAR(255) NOT NULL UNIQUE, checksum CHAR(64) NULL, executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $column = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='migrations' AND column_name='checksum'");
    $column->execute();
    if ((int) $column->fetchColumn() === 0) $pdo->exec('ALTER TABLE migrations ADD COLUMN checksum CHAR(64) NULL AFTER migration');

    $executed = [];
    foreach ($pdo->query('SELECT migration,checksum FROM migrations')->fetchAll() as $migration) $executed[$migration['migration']] = $migration['checksum'];
    $files = glob(__DIR__ . '/migrations/*.sql') ?: [];
    sort($files);

    foreach ($files as $file) {
        $name = basename($file);
        $sql = file_get_contents($file);
        if ($sql === false) throw new RuntimeException("Não foi possível ler {$name}");
        $checksum = hash('sha256', $sql);

        if (array_key_exists($name, $executed)) {
            if (is_string($executed[$name]) && $executed[$name] !== '' && !hash_equals($executed[$name], $checksum)) {
                throw new RuntimeException("A migração já executada {$name} foi alterada.");
            }
            if ($executed[$name] === null || $executed[$name] === '') {
                $statement = $pdo->prepare('UPDATE migrations SET checksum=? WHERE migration=? AND checksum IS NULL');
                $statement->execute([$checksum, $name]);
            }
            echo "Ignorada: {$name}\n";
            continue;
        }

        $pdo->exec($sql);
        $statement = $pdo->prepare('INSERT INTO migrations (migration,checksum) VALUES (?,?)');
        $statement->execute([$name, $checksum]);
        echo "Executada: {$name}\n";
    }

    echo "Migrações concluídas.\n";
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute([$lockName]);
}
