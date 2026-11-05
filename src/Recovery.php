<?php
declare(strict_types=1);
namespace CommitLab;

final class Recovery
{
    public function __construct(private readonly NamespaceStore $ns) {}

    public function run(): array
    {
        return $this->ns->exclusive(function (): array {
            $db = Database::connect($this->ns);
            $referenced = [];
            foreach ($db->query('SELECT DISTINCT digest FROM refs') as $row) {
                $referenced[$row['digest']] = true;
            }
            // Plan both inventories before deleting anything. Parser temporaries are excluded.
            $plan = [];
            foreach (['staging', 'objects'] as $directory) {
                $pattern = $directory === 'objects' ? '/\A[0-9a-f]{64}\z/D' : '/\A[0-9a-f]{32}\.stage\z/D';
                foreach ($this->ns->entries($directory) as $name) {
                    if (!preg_match($pattern, $name) || ($directory === 'objects' && isset($referenced[$name]))) {
                        continue;
                    }
                    $path = $this->ns->root . '/' . $directory . '/' . $name;
                    $s = lstat($path);
                    if ($s === false || ($s['mode'] & 0170000) !== 0100000 || $s['nlink'] !== 1 || $s['uid'] !== posix_geteuid()) {
                        continue;
                    }
                    $plan[] = [$directory, $path, $s['size']];
                }
            }
            $result = ['staged_removed' => 0, 'objects_removed' => 0, 'bytes_removed' => 0];
            foreach ($plan as [$directory, $path, $size]) {
                NamespaceStore::regular($path);
                if (!unlink($path)) {
                    throw new Failure(503, 'recovery_failed', 'Recovery could not unlink a recognized unreferenced file.');
                }
                $result[$directory === 'objects' ? 'objects_removed' : 'staged_removed']++;
                $result['bytes_removed'] += $size;
            }
            $db->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
            return $result;
        });
    }
}
