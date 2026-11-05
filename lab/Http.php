<?php
declare(strict_types=1);
namespace CommitLab;

final class Http
{
    public readonly Process $process;
    private array $paths = [];
    private string $body;
    private string $headers;
    private string $status;
    private string $errors;
    private bool $collected = false;

    public function __construct(private readonly Server $server, string $method, string $route, ?array $bytes = null, ?string $labels = null)
    {
        $prefix = $server->ns->root . '/client/' . bin2hex(random_bytes(12));
        $this->body = $prefix . '.body';
        $this->headers = $prefix . '.headers';
        $this->status = $prefix . '.status';
        $this->errors = $prefix . '.errors';
        $this->paths = [$this->body, $this->headers, $this->status, $this->errors];
        $command = ['curl', '--silent', '--show-error', '--http1.1', '--noproxy', '*', '--connect-timeout', '1', '--max-time', '8', '--max-filesize', (string) Limits::RESPONSE_BYTES, '--output', $this->body, '--dump-header', $this->headers, '--write-out', '%{http_code}', '--request', $method];
        if ($bytes !== null) {
            foreach ($bytes as $index => $content) {
                $file = $prefix . '.' . $index . '.input';
                if (file_put_contents($file, $content) !== strlen($content)) {
                    throw new \RuntimeException('Cannot create synthetic client fixture');
                }
                $this->paths[] = $file;
                $command[] = '--form';
                $command[] = 'files[]=@' . $file . ';filename=synthetic.bin;type=application/octet-stream';
            }
            $command[] = '--form-string';
            $command[] = 'labels=' . ($labels ?? '[]');
        }
        $command[] = 'http://127.0.0.1:' . $server->port . $route;
        $this->process = new Process($command, $this->status, $this->errors);
    }

    public function inspect(): void
    {
        foreach ([[$this->body, Limits::RESPONSE_BYTES], [$this->headers, 16384], [$this->status, 16], [$this->errors, Limits::DIAGNOSTIC_BYTES]] as [$file, $maximum]) {
            clearstatcache(true, $file);
            $s = @lstat($file);
            if ($s !== false && $s['size'] > $maximum) {
                throw new \RuntimeException('HTTP client output limit exceeded');
            }
        }
    }

    public function result(): array
    {
        if ($this->collected) {
            throw new \LogicException('HTTP result already collected');
        }
        try {
            $this->server->wait(fn(): bool => $this->process->completed());
            $this->inspect();
            $exit = $this->process->finish();
            return [
                'status' => (int) Process::readBounded($this->status, 16),
                'body' => Process::readBounded($this->body, Limits::RESPONSE_BYTES),
                'headers' => Process::readBounded($this->headers, 16384),
                'exit' => $exit,
                'errors' => Process::readBounded($this->errors, Limits::DIAGNOSTIC_BYTES),
            ];
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->collected) {
            return;
        }
        $this->process->stop();
        foreach ($this->paths as $path) {
            if (NamespaceStore::exists($path)) {
                NamespaceStore::regular($path);
                unlink($path);
            }
        }
        $this->collected = true;
        $this->server->forget($this);
    }
}
