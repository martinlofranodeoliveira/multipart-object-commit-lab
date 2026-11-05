<?php
declare(strict_types=1);

spl_autoload_register(static function (string $name): void {
    $prefix = 'CommitLab\\';
    if (!str_starts_with($name, $prefix)) {
        return;
    }
    $short = substr($name, strlen($prefix));
    if (!preg_match('/\A[A-Za-z]+\z/D', $short)) {
        return;
    }
    $directory = in_array($short, ['Process', 'Http', 'Server', 'Scenario'], true) ? 'lab' : 'src';
    $file = dirname(__DIR__) . '/' . $directory . '/' . $short . '.php';
    if (is_file($file)) {
        require $file;
    }
});
