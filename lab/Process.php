<?php
declare(strict_types=1);
namespace CommitLab;

final class Process
{
    // Linux POSIX signal numbers; pcntl is intentionally not a runtime dependency.
    public const TERM = 15;
    public const KILL = 9;
    private mixed $handle;
    private bool $closed = false;
    private int $exit = -1;
    public readonly int $pid;

    public function __construct(array $command, public readonly string $out, public readonly string $err)
    {
        $this->handle = proc_open(
            array_merge(['/usr/bin/setsid'], $command),
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            dirname(__DIR__),
            ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C', 'HOME' => '/tmp']
        );
        if (!is_resource($this->handle)) {
            throw new \RuntimeException('Cannot start owned subprocess');
        }
        $status = proc_get_status($this->handle);
        $this->pid = $status['pid'];
        if (!$status['running']) {
            $this->exit = $status['exitcode'];
            proc_close($this->handle);
            $this->closed = true;
            throw new \RuntimeException('Subprocess failed during launch: ' . self::readBounded($err, 4096));
        }
    }

    // Linux /proc inspection deliberately does not reap the process.
    public function completed(): bool
    {
        if ($this->closed) {
            return true;
        }
        $stat = @file_get_contents('/proc/' . $this->pid . '/stat');
        if ($stat === false) {
            return true;
        }
        $end = strrpos($stat, ')');
        $state = $end === false ? '' : substr($stat, $end + 2, 1);
        return $state === 'Z' || $state === 'X';
    }

    public function finish(): int
    {
        if ($this->closed) {
            return $this->exit;
        }
        if (!$this->completed()) {
            throw new \LogicException('Cannot reap a running process');
        }
        $status = proc_get_status($this->handle);
        $code = proc_close($this->handle);
        $this->exit = $status['exitcode'] >= 0 ? $status['exitcode'] : $code;
        $this->closed = true;
        return $this->exit;
    }

    public function stop(): void
    {
        if ($this->closed) {
            return;
        }
        // The unreaped master PID cannot be reused. Signal its session group only
        // before proc_get_status/proc_close can end our wait ownership.
        @posix_kill(-$this->pid, self::TERM);
        usleep(100000);
        @posix_kill(-$this->pid, self::KILL);
        $deadline = hrtime(true) + 1000000000;
        while (!$this->completed()) {
            if (hrtime(true) >= $deadline) {
                throw new \RuntimeException('Owned process did not become reapable after SIGKILL');
            }
            usleep(10000);
        }
        $this->finish();
        foreach ([$this->out, $this->err] as $file) {
            self::retainTail($file);
        }
    }

    public static function readBounded(string $file, int $limit): string
    {
        $s = @lstat($file);
        if ($s === false) {
            return '';
        }
        if (($s['mode'] & 0170000) !== 0100000 || $s['size'] > $limit) {
            throw new \RuntimeException('Subprocess output exceeded its read limit');
        }
        $value = file_get_contents($file, false, null, 0, $limit + 1);
        if ($value === false || strlen($value) > $limit) {
            throw new \RuntimeException('Cannot read bounded subprocess output');
        }
        return $value;
    }

    private static function retainTail(string $file): void
    {
        $s = @lstat($file);
        if ($s === false || ($s['mode'] & 0170000) !== 0100000 || $s['size'] <= Limits::DIAGNOSTIC_BYTES) {
            return;
        }
        $in = fopen($file, 'rb');
        if ($in === false) {
            return;
        }
        fseek($in, -Limits::DIAGNOSTIC_BYTES, SEEK_END);
        $tail = stream_get_contents($in, Limits::DIAGNOSTIC_BYTES);
        fclose($in);
        if ($tail !== false) {
            file_put_contents($file, $tail);
        }
    }

    public function __destruct()
    {
        if (!$this->closed) {
            try {
                $this->stop();
            } catch (\Throwable) {
                // Normal shutdown is explicit. Do not signal after wait ownership ends.
            }
        }
    }
}
