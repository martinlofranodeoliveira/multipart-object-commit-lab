<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/support.php';

try {
    $qualification = CommitLab\Database::qualify();
    echo 'SQLite ' . $qualification['sqlite_version'] . ' FTS5 qualified' . "\n";
    $unit = require __DIR__ . '/unit.php';
    $unit();
    echo "PASS unit and recovery safety\n";
    $adversarial = require __DIR__ . '/adversarial.php';
    $adversarial();
    echo "PASS adversarial HTTP and capacity\n";
    $report = CommitLab\Scenario::all();
    foreach ($report['scenarios'] as $name) {
        echo 'PASS ' . $name . "\n";
    }
    echo "All tests passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL ' . substr($error->getMessage(), 0, 4000) . "\n");
    exit(1);
}
