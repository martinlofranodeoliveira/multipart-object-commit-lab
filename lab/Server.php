<?php
declare(strict_types=1);
namespace CommitLab;

final class Server
{
    public readonly NamespaceStore $ns;
    public int $port;
    private mixed $owner;
    private ?Process $nginx = null;
    private ?Process $fpm = null;
    private array $clients = [];
    private int $deadline;
    private bool $disposed = false;

    public function __construct()
    {
        if (PHP_VERSION_ID < 80400 || PHP_OS_FAMILY !== 'Linux' || !extension_loaded('pdo_sqlite') || !extension_loaded('posix') || posix_geteuid() === 0) {
            throw new \RuntimeException('Use Linux, PHP 8.4+, PDO SQLite and POSIX as an unprivileged UID');
        }
        $this->deadline = hrtime(true) + 10000000000;
        $this->ns = NamespaceStore::create();
        $this->owner = $this->ns->own();
        try {
            $this->ns->exclusive(fn() => Database::initialize($this->ns));
            $this->start();
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    private function start(): void
    {
        $project = dirname(__DIR__);
        if (!preg_match('~\A/[A-Za-z0-9_./-]+\z~D', $project)) {
            throw new \RuntimeException('Harness installation path must use ASCII letters, digits, slash, dot, underscore or hyphen');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException('Cannot reserve loopback port');
        }
        $address = stream_socket_get_name($socket, false);
        $this->port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
        fclose($socket); // Startup failure is reported if another process wins this small bind gap.
        foreach (['fpm.sock', 'fpm.pid', 'nginx.pid'] as $name) {
            $path = $this->ns->root . '/run/' . $name;
            if (NamespaceStore::exists($path)) {
                $s = lstat($path);
                if ($s === false || !in_array($s['mode'] & 0170000, [0100000, 0140000], true)) {
                    throw new \RuntimeException('Unsafe stale run entry');
                }
                unlink($path);
            }
        }
        $replacements = ['@ROOT@' => $this->ns->root, '@PUBLIC@' => $project . '/public', '@PORT@' => (string) $this->port];
        foreach (['nginx', 'fpm'] as $name) {
            $template = file_get_contents($project . '/config/' . $name . '.conf.in');
            if ($template === false) {
                throw new \RuntimeException('Missing server template');
            }
            $rendered = strtr($template, $replacements);
            if (file_put_contents($this->ns->root . '/run/' . $name . '.conf', $rendered) !== strlen($rendered)) {
                throw new \RuntimeException('Cannot write private server configuration');
            }
        }
        $logs = $this->ns->root . '/logs/';
        $this->fpm = new Process(['php-fpm', '--nodaemonize', '--fpm-config', $this->ns->root . '/run/fpm.conf'], $logs . 'fpm.out', $logs . 'fpm.err');
        $this->wait(fn(): bool => NamespaceStore::exists($this->ns->root . '/run/fpm.sock'));
        $this->nginx = new Process(['nginx', '-p', $this->ns->root . '/', '-c', $this->ns->root . '/run/nginx.conf', '-g', 'daemon off;'], $logs . 'nginx.out', $logs . 'nginx.err');
        $this->wait(function (): bool {
            $connection = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.05);
            if ($connection === false) {
                return false;
            }
            fclose($connection);
            return true;
        });
    }

    public function tick(): void
    {
        if (hrtime(true) >= $this->deadline) {
            throw new \RuntimeException('Ten-second scenario deadline exceeded');
        }
        $total = 0;
        foreach ($this->ns->entries('logs') as $name) {
            $s = NamespaceStore::regular($this->ns->root . '/logs/' . $name);
            $total += $s['size'];
        }
        if ($total > Limits::DIAGNOSTIC_TOTAL) {
            throw new \RuntimeException('Diagnostic watchdog threshold exceeded');
        }
        foreach ($this->clients as $client) {
            $client->inspect();
        }
        foreach ([$this->fpm, $this->nginx] as $master) {
            if ($master !== null && $master->completed()) {
                throw new \RuntimeException('Private server master exited: ' . Process::readBounded($master->err, Limits::DIAGNOSTIC_BYTES));
            }
        }
    }

    public function wait(callable $predicate): void
    {
        do {
            $this->tick();
            if ($predicate()) {
                return;
            }
            usleep(10000);
        } while (true);
    }

    public function request(string $method, string $route, ?array $bytes = null, ?string $labels = null): Http
    {
        $this->tick();
        if (count($this->clients) >= Limits::ACTIVE) {
            throw new \RuntimeException('Harness active-client limit reached');
        }
        $client = new Http($this, $method, $route, $bytes, $labels);
        $this->clients[spl_object_id($client)] = $client;
        return $client;
    }

    public function upload(array $bytes, array $labels): array
    {
        return $this->request('POST', '/batches', $bytes, json_encode($labels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))->result();
    }

    public function createBatch(array $files, array $labels): array
    {
        return $this->upload($files, $labels);
    }

    public function getBatch(string $batchId): array
    {
        return $this->request('GET', '/batches/' . $batchId)->result();
    }

    public function readObject(string $digest): array
    {
        return $this->request('GET', '/objects/' . $digest)->result();
    }

    public function forget(Http $client): void
    {
        unset($this->clients[spl_object_id($client)]);
    }

    public function arm(string $point): void
    {
        if (!in_array($point, Gates::POINTS, true)) {
            throw new \LogicException('Unknown gate');
        }
        if (file_put_contents($this->ns->root . '/gates/' . $point . '.arm', 'armed') !== 5) {
            throw new \RuntimeException('Cannot arm gate');
        }
    }

    public function ready(string $point, int $count): array
    {
        $markers = [];
        $this->wait(function () use ($point, $count, &$markers): bool {
            $markers = [];
            foreach ($this->ns->entries('gates') as $name) {
                if (preg_match('/\A' . preg_quote($point, '/') . '\.[0-9a-f]{16}\.ready\z/D', $name)) {
                    $value = json_decode(Process::readBounded($this->ns->root . '/gates/' . $name, 512), true);
                    if (is_array($value) && isset($value['pid'], $value['birth'])) {
                        $value['marker'] = $name;
                        $markers[] = $value;
                    }
                }
            }
            return count($markers) >= $count;
        });
        return $markers;
    }

    public function release(string $point): void
    {
        if (!in_array($point, Gates::POINTS, true)) {
            throw new \LogicException('Unknown gate');
        }
        if (file_put_contents($this->ns->root . '/gates/' . $point . '.go', 'released') !== 8) {
            throw new \RuntimeException('Cannot release gate');
        }
    }

    public function killWorker(array $marker): void
    {
        $name = $marker['marker'] ?? '';
        if (!is_string($name) || !preg_match('/\A(?:after_hash|after_install|after_commit)\.[0-9a-f]{16}\.ready\z/D', $name)) {
            throw new \RuntimeException('Invalid owned barrier marker');
        }
        $ready = $this->ns->root . '/gates/' . $name;
        NamespaceStore::regular($ready);
        $actual = json_decode(Process::readBounded($ready, 512), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($actual) || ($actual['pid'] ?? null) !== ($marker['pid'] ?? null) || ($actual['birth'] ?? null) !== ($marker['birth'] ?? null)) {
            throw new \RuntimeException('Barrier identity changed');
        }
        // A private message requests self-termination. Never signal a numeric
        // grandchild PID: the FPM master owns its wait/reuse lifetime.
        $out = fopen($ready . '.crash', 'x+b');
        if ($out === false) {
            throw new \RuntimeException('Cannot request gated worker self-crash');
        }
        $written = fwrite($out, 'self-crash');
        $flushed = fflush($out);
        fclose($out);
        if ($written !== 10 || !$flushed) {
            throw new \RuntimeException('Cannot write complete worker self-crash marker');
        }
    }

    public function recover(): array
    {
        $prefix = $this->ns->root . '/client/recovery';
        $process = new Process([PHP_BINARY, dirname(__DIR__) . '/bin/recover.php', $this->ns->root, '--json'], $prefix . '.out', $prefix . '.err');
        try {
            $this->wait(fn(): bool => $process->completed());
            if ($process->finish() !== 0) {
                throw new \RuntimeException('Recovery CLI failed: ' . Process::readBounded($prefix . '.err', 4096));
            }
            return json_decode(Process::readBounded($prefix . '.out', 4096), true, 8, JSON_THROW_ON_ERROR);
        } finally {
            $process->stop();
            foreach ([$prefix . '.out', $prefix . '.err'] as $path) {
                if (NamespaceStore::exists($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function restart(): void
    {
        $this->stop();
        foreach ($this->ns->entries('gates') as $name) {
            NamespaceStore::regular($this->ns->root . '/gates/' . $name);
            unlink($this->ns->root . '/gates/' . $name);
        }
        $this->start();
    }

    private function stop(): void
    {
        foreach (array_values($this->clients) as $client) {
            $client->close();
        }
        // Masters and all their workers share separate, harness-owned sessions.
        foreach (['nginx', 'fpm'] as $name) {
            if ($this->$name !== null) {
                $this->$name->stop();
                $this->$name = null;
            }
        }
        foreach ($this->ns->entries('logs') as $name) {
            $path = $this->ns->root . '/logs/' . $name;
            $s = NamespaceStore::regular($path);
            if ($s['size'] > Limits::DIAGNOSTIC_BYTES) {
                $stream = fopen($path, 'rb');
                if ($stream !== false) {
                    fseek($stream, -Limits::DIAGNOSTIC_BYTES, SEEK_END);
                    $tail = stream_get_contents($stream, Limits::DIAGNOSTIC_BYTES);
                    fclose($stream);
                    if ($tail !== false) {
                        file_put_contents($path, $tail);
                    }
                }
            }
        }
    }

    public function close(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->stop();
        $this->ns->dispose();
        flock($this->owner, LOCK_UN);
        fclose($this->owner);
        $this->disposed = true;
    }

    public function __destruct()
    {
        if (!$this->disposed && isset($this->ns, $this->owner)) {
            try {
                $this->close();
            } catch (\Throwable) {
                // A namespace that cannot be safely disposed is preserved.
            }
        }
    }
}
