<?php
declare(strict_types=1);

use App\DB;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/bootstrap.php';

$pdo = DB::conn();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');

$migrationsDir = __DIR__ . '/../database/migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

$appliedStmt = $pdo->prepare('SELECT COUNT(1) FROM schema_migrations WHERE version = :v');
$insertStmt = $pdo->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES(:v, :t)');

foreach ($files as $file) {
    $version = basename($file);
    $appliedStmt->execute([':v' => $version]);
    $already = (int)$appliedStmt->fetchColumn() > 0;
    if ($already) {
        echo "Skipping $version (already applied)\n";
        continue;
    }
    $sql = file_get_contents($file) ?: '';
    if ($sql === '') {
        echo "Empty migration: $version\n";
        continue;
    }
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $insertStmt->execute([':v' => $version, ':t' => date('c')]);
        $pdo->commit();
        echo "Applied $version\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Migration failed for $version: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "All migrations applied.\n";


