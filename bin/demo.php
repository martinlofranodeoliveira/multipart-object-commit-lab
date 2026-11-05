<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

if ($argc > 2 || ($argc === 2 && $argv[1] !== '--json')) {
    fwrite(STDERR, "Usage: php bin/demo.php [--json]\n");
    exit(2);
}
try {
    CommitLab\Database::qualify();
    $report = CommitLab\Scenario::all();
    if (($argv[1] ?? '') !== '--json') {
        echo "Multipart Object Commit Lab — development 0.1.0\n";
        foreach ($report['scenarios'] as $scenario) {
            echo 'PASS ' . $scenario . "\n";
        }
        echo "7 asserting scenarios passed; synthetic bytes, loopback HTTP, no remote services.\n";
    }
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Demo failed: ' . substr($error->getMessage(), 0, 4000) . "\n");
    exit(1);
}
