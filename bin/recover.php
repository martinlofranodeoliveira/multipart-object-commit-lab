<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use CommitLab\NamespaceStore;
use CommitLab\Recovery;

if ($argc < 2 || $argc > 3 || ($argc === 3 && $argv[2] !== '--json')) {
    fwrite(STDERR, "Usage: php bin/recover.php CANONICAL_PRIVATE_ROOT [--json]\n");
    exit(2);
}
try {
    $result = (new Recovery(NamespaceStore::open($argv[1])))->run();
    if (($argv[2] ?? '') !== '--json') {
        echo 'Removed ' . $result['objects_removed'] . ' unreferenced objects and ' . $result['staged_removed'] . " stages.\n";
    }
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Recovery failed: ' . substr($error->getMessage(), 0, 1000) . "\n");
    exit(1);
}
