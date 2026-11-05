<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $result = CommitLab\Database::qualify();
    $result['php_version'] = PHP_VERSION;
    $receipt = '/opt/sqlite/qualification.json';
    if (is_file($receipt)) {
        $build = json_decode((string) file_get_contents($receipt), true, 8, JSON_THROW_ON_ERROR);
        if (($build['sqlite_source_id'] ?? null) !== $result['sqlite_source_id']) {
            throw new RuntimeException('Linked library differs from build receipt');
        }
        $result['build'] = $build;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Qualification failed: ' . $error->getMessage() . "\n");
    exit(1);
}
