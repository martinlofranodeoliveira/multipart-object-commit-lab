<?php
declare(strict_types=1);
namespace CommitLab;

final class NamespaceStore
{
    public const DIRECTORIES = ['objects', 'staging', 'parser', 'run', 'gates', 'client', 'logs', 'nginx-body', 'nginx-fastcgi', 'nginx-proxy', 'nginx-uwsgi', 'nginx-scgi'];

    private function __construct(public readonly string $root) {}

    public static function create(): self
    {
        umask(0077);
        $parent = realpath(sys_get_temp_dir());
        if ($parent === false) {
            throw new \RuntimeException('Temporary directory unavailable');
        }
        $root = $parent . '/mocl-' . bin2hex(random_bytes(8));
        if (!mkdir($root, 0700)) {
            throw new \RuntimeException('Cannot create private namespace');
        }
        foreach (self::DIRECTORIES as $name) {
            if (!mkdir($root . '/' . $name, 0700)) {
                throw new \RuntimeException('Cannot create private directory');
            }
        }
        foreach (['owner.lock', 'writer.lock'] as $name) {
            $handle = fopen($root . '/' . $name, 'x+b');
            if ($handle === false) {
                throw new \RuntimeException('Cannot create lock');
            }
            fclose($handle);
        }
        $marker = json_encode(['version' => 1, 'id' => bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR);
        if (file_put_contents($root . '/namespace.json', $marker) !== strlen($marker)) {
            throw new \RuntimeException('Cannot write namespace marker');
        }
        return self::open($root);
    }

    public static function open(string $root): self
    {
        if ($root === '' || realpath($root) !== $root) {
            throw new Failure(503, 'namespace_unavailable', 'A canonical private namespace is required.');
        }
        self::privateDirectory($root);
        foreach (self::DIRECTORIES as $name) {
            self::privateDirectory($root . '/' . $name);
        }
        foreach (['owner.lock', 'writer.lock', 'namespace.json'] as $name) {
            self::regular($root . '/' . $name);
        }
        $marker = file_get_contents($root . '/namespace.json', false, null, 0, 256);
        $data = json_decode($marker === false ? '' : $marker, true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !preg_match('/\A[0-9a-f]{32}\z/D', $data['id'] ?? '')) {
            throw new Failure(503, 'namespace_unavailable', 'Invalid namespace marker.');
        }
        return new self($root);
    }

    private static function privateDirectory(string $path): void
    {
        $s = lstat($path);
        if ($s === false || ($s['mode'] & 0170000) !== 0040000 || ($s['mode'] & 0077) !== 0 || $s['uid'] !== posix_geteuid()) {
            throw new Failure(503, 'namespace_unavailable', 'Directory ownership or permissions are unsafe.');
        }
    }

    public static function regular(string $path): array
    {
        clearstatcache(true, $path);
        $s = lstat($path);
        if ($s === false || ($s['mode'] & 0170000) !== 0100000 || $s['nlink'] !== 1 || $s['uid'] !== posix_geteuid()) {
            throw new Failure(503, 'unsafe_file', 'Expected a private regular file.');
        }
        return $s;
    }

    public static function exists(string $path): bool
    {
        clearstatcache(true, $path);
        return @lstat($path) !== false;
    }

    public function entries(string $directory): array
    {
        if (!in_array($directory, self::DIRECTORIES, true)) {
            throw new \LogicException('Unknown private directory');
        }
        $names = [];
        $handle = opendir($this->root . '/' . $directory);
        if ($handle === false) {
            throw new Failure(503, 'storage_unavailable', 'Cannot enumerate private storage.');
        }
        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $names[] = $name;
                if (count($names) > 1024) {
                    throw new Failure(503, 'inventory_limit', 'Private directory inventory exceeds its inspection limit.');
                }
            }
        } finally {
            closedir($handle);
        }
        sort($names, SORT_STRING);
        return $names;
    }

    public function exclusive(callable $operation): mixed
    {
        self::regular($this->root . '/writer.lock');
        $lock = fopen($this->root . '/writer.lock', 'r+b');
        if ($lock === false) {
            throw new Failure(503, 'lock_unavailable', 'Publication lock unavailable.');
        }
        $deadline = hrtime(true) + 2000000000;
        try {
            while (!flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) >= $deadline) {
                    throw new Failure(503, 'busy', 'Publication or maintenance is busy.');
                }
                usleep(10000);
            }
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function own(): mixed
    {
        $handle = fopen($this->root . '/owner.lock', 'r+b');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Namespace already has a lifetime owner');
        }
        return $handle;
    }

    // Only the harness calls dispose, after reaping every owned master/client.
    public function dispose(): void
    {
        foreach (self::DIRECTORIES as $directory) {
            foreach ($this->entries($directory) as $name) {
                $path = $this->root . '/' . $directory . '/' . $name;
                $s = lstat($path);
                if ($s !== false && ($s['mode'] & 0170000) !== 0040000) {
                    if (!unlink($path)) { // Unlink a link itself, never its target.
                        throw new \RuntimeException('Cannot unlink owned namespace entry');
                    }
                } else {
                    throw new \RuntimeException('Refusing recursive namespace disposal');
                }
            }
            if (!rmdir($this->root . '/' . $directory)) {
                throw new \RuntimeException('Cannot remove owned namespace directory');
            }
        }
        $allowed = ['owner.lock', 'writer.lock', 'namespace.json', 'metadata.sqlite', 'metadata.sqlite-wal', 'metadata.sqlite-shm'];
        foreach (scandir($this->root) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (!in_array($name, $allowed, true)) {
                throw new \RuntimeException('Unexpected namespace entry; namespace preserved');
            }
            $path = $this->root . '/' . $name;
            $s = lstat($path);
            if ($s === false || ($s['mode'] & 0170000) === 0040000 || !unlink($path)) {
                throw new \RuntimeException('Unsafe namespace disposal');
            }
        }
        if (!rmdir($this->root)) {
            throw new \RuntimeException('Cannot remove namespace root');
        }
    }
}
