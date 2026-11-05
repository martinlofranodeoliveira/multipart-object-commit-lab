<?php
declare(strict_types=1);
namespace CommitLab;

final class Gates
{
    public const POINTS = ['after_hash', 'after_install', 'after_commit'];

    public static function birth(int $pid): string
    {
        $text = @file_get_contents('/proc/' . $pid . '/stat');
        if ($text === false || ($end = strrpos($text, ')')) === false) {
            return '';
        }
        $fields = preg_split('/\s+/', trim(substr($text, $end + 1)));
        return $fields[19] ?? '';
    }

    public static function wait(NamespaceStore $ns, string $point): void
    {
        if (getenv('LAB_GATES') !== '1' || !in_array($point, self::POINTS, true)) {
            return;
        }
        $prefix = $ns->root . '/gates/' . $point;
        if (!NamespaceStore::exists($prefix . '.arm')) {
            return;
        }
        NamespaceStore::regular($prefix . '.arm');
        $ready = $prefix . '.' . bin2hex(random_bytes(8)) . '.ready';
        $handle = fopen($ready, 'x+b');
        if ($handle === false) {
            throw new Failure(503, 'gate_failed', 'Cannot announce test barrier.');
        }
        $payload = json_encode(['pid' => getmypid(), 'birth' => self::birth(getmypid())], JSON_THROW_ON_ERROR);
        $written = fwrite($handle, $payload);
        $flushed = fflush($handle);
        fclose($handle);
        if ($written !== strlen($payload) || !$flushed) {
            @unlink($ready);
            throw new Failure(503, 'gate_failed', 'Cannot write complete test barrier marker.');
        }
        $deadline = hrtime(true) + 3000000000;
        while (!NamespaceStore::exists($prefix . '.go')) {
            // The controller cannot own/reap an FPM worker. Only the worker may
            // signal its own current PID after consuming this private barrier.
            if (NamespaceStore::exists($ready . '.crash')) {
                NamespaceStore::regular($ready . '.crash');
                posix_kill(getmypid(), 9); // Linux SIGKILL, no pcntl dependency.
                throw new \RuntimeException('Self-crash signal unexpectedly returned');
            }
            if (hrtime(true) >= $deadline) {
                throw new Failure(503, 'gate_timeout', 'Test barrier timed out.');
            }
            usleep(10000);
        }
        NamespaceStore::regular($prefix . '.go');
    }
}
