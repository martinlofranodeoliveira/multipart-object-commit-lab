<?php
declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', received ' . var_export($actual, true));
    }
}

function fails(callable $operation, string $class, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw new RuntimeException($message . ': unexpected ' . get_class($error), 0, $error);
    }
    throw new RuntimeException($message . ': expected failure');
}

// Independent curl form-vector oracle. Unlike Http's canonical helper this can
// exercise duplicate labels, indexed names and parser limits without a parser
// implementation in the test or application.
function multipartVector(CommitLab\Server $server, array $parts): array
{
    $prefix = $server->ns->root . '/client/vector-' . bin2hex(random_bytes(6));
    $paths = [$prefix . '.body', $prefix . '.status', $prefix . '.err'];
    $command = ['curl', '--silent', '--show-error', '--http1.1', '--noproxy', '*', '--connect-timeout', '1', '--max-time', '3', '--max-filesize', '4096', '--output', $prefix . '.body', '--write-out', '%{http_code}'];
    foreach ($parts as $index => [$type, $name, $value]) {
        if ($type === 'file') {
            $path = $prefix . '.' . $index . '.input';
            file_put_contents($path, $value);
            $paths[] = $path;
            array_push($command, '--form', $name . '=@' . $path . ';filename=synthetic.bin');
        } else {
            array_push($command, '--form-string', $name . '=' . $value);
        }
    }
    $command[] = 'http://127.0.0.1:' . $server->port . '/batches';
    $process = new CommitLab\Process($command, $prefix . '.status', $prefix . '.err');
    try {
        $server->wait(fn(): bool => $process->completed());
        $exit = $process->finish();
        return ['exit' => $exit, 'status' => (int) CommitLab\Process::readBounded($prefix . '.status', 16), 'body' => CommitLab\Process::readBounded($prefix . '.body', 4096)];
    } finally {
        $process->stop();
        foreach ($paths as $path) {
            if (CommitLab\NamespaceStore::exists($path)) {
                unlink($path);
            }
        }
    }
}
