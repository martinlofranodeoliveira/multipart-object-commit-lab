<?php
declare(strict_types=1);
namespace CommitLab;

final class Repository
{
    public function __construct(private readonly NamespaceStore $ns) {}

    public function createBatch(?array $files = null): array
    {
        // Hash and validate every admitted file before acquiring publication ownership.
        $admitted = $files === null ? Admission::fromPhp() : Admission::fromArray($files);
        Gates::wait($this->ns, 'after_hash');
        $batch = $this->ns->exclusive(function () use ($admitted): array {
            $db = Database::connect($this->ns);
            Database::checkWal($this->ns);
            $this->reserve($db, $admitted);
            $stages = [];
            try {
                foreach ($admitted as $index => $file) {
                    $stage = $this->ns->root . '/staging/' . bin2hex(random_bytes(16)) . '.stage';
                    $out = fopen($stage, 'x+b');
                    if ($out === false) {
                        throw new Failure(503, 'storage_unavailable', 'Cannot create staging file.');
                    }
                    $stages[$index] = $stage;
                    if (array_key_exists('bytes', $file)) {
                        $in = fopen('php://temp', 'w+b');
                        if ($in === false || fwrite($in, $file['bytes']) !== strlen($file['bytes']) || !rewind($in)) {
                            if (is_resource($in)) {
                                fclose($in);
                            }
                            fclose($out);
                            throw new Failure(503, 'storage_unavailable', 'Cannot buffer direct upload bytes.');
                        }
                    } else {
                        NamespaceStore::regular($file['path']);
                        $in = fopen($file['path'], 'rb');
                        if ($in === false) {
                            fclose($out);
                            throw new Failure(503, 'storage_unavailable', 'Cannot reopen admitted upload.');
                        }
                    }
                    try {
                        [$digest, $size] = Admission::transfer($in, $out);
                        if ($digest !== $file['digest'] || $size !== $file['size']) {
                            throw new Failure(422, 'invalid_upload', 'Upload changed during admission.');
                        }
                        if (!fflush($out) || !fsync($out)) {
                            throw new Failure(503, 'storage_unavailable', 'Cannot synchronize staged bytes.');
                        }
                    } finally {
                        fclose($in);
                        fclose($out);
                    }
                    if (!chmod($stage, 0400)) {
                        throw new Failure(503, 'storage_unavailable', 'Cannot make object immutable.');
                    }
                }
                try {
                    foreach ($admitted as $index => $file) {
                        $stage = $stages[$index];
                        $object = $this->ns->root . '/objects/' . $file['digest'];
                        if (NamespaceStore::exists($object)) {
                            $s = NamespaceStore::regular($object);
                            if ($s['size'] !== $file['size'] || hash_file('sha256', $object) !== $file['digest']) {
                                throw new Failure(503, 'object_corrupt', 'Existing object failed integrity validation.');
                            }
                            if (!unlink($stage)) {
                                throw new Failure(503, 'storage_unavailable', 'Cannot remove duplicate staging file.');
                            }
                        } else {
                            if (!rename($stage, $object)) {
                                throw new Failure(503, 'storage_unavailable', 'Cannot install complete object.');
                            }
                        }
                    }
                } catch (\Throwable $installError) {
                    // Complete installations remain invisible orphans until
                    // explicit recovery checks the committed reference set.
                    throw $installError;
                }
                Gates::wait($this->ns, 'after_install');
                foreach ($admitted as $file) {
                    $installed = NamespaceStore::regular($this->ns->root . '/objects/' . $file['digest']);
                    if ($installed['size'] !== $file['size']) {
                        throw new Failure(503, 'object_corrupt', 'Installed object size is inconsistent.');
                    }
                }
                $id = bin2hex(random_bytes(16));
                $db->exec('BEGIN IMMEDIATE');
                try {
                    $insertBatch = $db->prepare('INSERT INTO batches(id) VALUES (?)');
                    $insertBatch->execute([$id]);
                    $insertObject = $db->prepare('INSERT INTO objects(digest,size) VALUES (?,?) ON CONFLICT(digest) DO NOTHING');
                    $insertRef = $db->prepare('INSERT INTO refs(batch_id,position,label,digest) VALUES (?,?,?,?)');
                    foreach ($admitted as $position => $file) {
                        $insertObject->execute([$file['digest'], $file['size']]);
                        $insertRef->execute([$id, $position, $file['label'], $file['digest']]);
                    }
                    $db->exec('COMMIT');
                } catch (\Throwable $error) {
                    try {
                        $db->exec('ROLLBACK');
                    } catch (\Throwable) {
                        // COMMIT outcome may be uncertain. Never compensate by
                        // deleting complete objects: committed refs may exist.
                    }
                    if ($error instanceof \PDOException && str_contains($error->getMessage(), 'database or disk is full')) {
                        throw new Failure(409, 'capacity_exceeded', 'Database page limit reached.');
                    }
                    throw $error;
                }
                // A busy reader can defer checkpointing; admission observes the WAL threshold.
                try {
                    $db->query('PRAGMA wal_checkpoint(PASSIVE)')->fetchAll();
                } catch (\Throwable) {
                    // Best-effort passive checkpoint must never fail an already-committed batch.
                }
                return ['id' => $id, 'files' => array_map(static fn(array $f): array => [
                    'label' => $f['label'], 'digest' => $f['digest'], 'size' => $f['size'],
                ], $admitted)];
            } finally {
                foreach ($stages as $stage) {
                    if (NamespaceStore::exists($stage)) {
                        @unlink($stage);
                    }
                }
            }
        });
        Gates::wait($this->ns, 'after_commit');
        return $batch;
    }

    private function reserve(\PDO $db, array $files): void
    {
        $objects = [];
        $bytes = 0;
        foreach (['objects', 'staging'] as $directory) {
            $pattern = $directory === 'objects' ? '/\A[0-9a-f]{64}\z/D' : '/\A[0-9a-f]{32}\.stage\z/D';
            $names = $this->ns->entries($directory);
            foreach ($names as $name) {
                if (!preg_match($pattern, $name)) {
                    throw new Failure(503, 'unsafe_inventory', 'Unexpected payload entry; inspect private storage.');
                }
                $s = NamespaceStore::regular($this->ns->root . '/' . $directory . '/' . $name);
                if ($s['size'] > Limits::FILE_BYTES) {
                    throw new Failure(503, 'unsafe_inventory', 'Unexpected payload size.');
                }
                $bytes += $s['size'];
                if ($directory === 'objects') {
                    $objects[$name] = true;
                }
            }
            if ($directory === 'staging' && count($names) + count($files) > Limits::STAGES) {
                throw new Failure(409, 'capacity_exceeded', 'Staging threshold reached; run recovery.');
            }
        }
        foreach ($files as $file) {
            $objects[$file['digest']] = true;
            $bytes += $file['size']; // Includes the maximum prospective staging footprint.
        }
        $batches = (int) $db->query('SELECT count(*) FROM batches')->fetchColumn();
        $refs = (int) $db->query('SELECT count(*) FROM refs')->fetchColumn();
        $pages = (int) $db->query('PRAGMA page_count')->fetchColumn();
        if (
            $batches + 1 > Limits::BATCHES
            || $refs + count($files) > Limits::REFERENCES
            || count($objects) > Limits::OBJECTS
            || $bytes > Limits::PAYLOAD_BYTES
            || $pages >= Limits::DB_PAGES
        ) {
            throw new Failure(409, 'capacity_exceeded', 'A retained-state threshold would be exceeded.');
        }
    }

    public function getBatch(string $batchId): array
    {
        if (!preg_match('/\A[0-9a-f]{32}\z/D', $batchId)) {
            throw new Failure(404, 'not_found', 'Batch not found.');
        }
        $db = Database::connect($this->ns);
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT id FROM batches WHERE id=?');
            $query->execute([$batchId]);
            if ($query->fetchColumn() === false) {
                throw new Failure(404, 'not_found', 'Batch not found.');
            }
            $query = $db->prepare('SELECT r.label,r.digest,o.size FROM refs r JOIN objects o ON o.digest=r.digest WHERE r.batch_id=? ORDER BY r.position');
            $query->execute([$batchId]);
            $files = $query->fetchAll();
            foreach ($files as &$file) {
                $file['size'] = (int) $file['size'];
            }
            unset($file);
            $db->commit();
            return ['id' => $batchId, 'files' => $files];
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    public function readObject(string $digest): array
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/D', $digest)) {
            throw new Failure(404, 'not_found', 'Object not found.');
        }
        $db = Database::connect($this->ns);
        $query = $db->prepare('SELECT o.size FROM objects o WHERE o.digest=? AND EXISTS (SELECT 1 FROM refs r WHERE r.digest=o.digest)');
        $query->execute([$digest]);
        $size = $query->fetchColumn();
        if ($size === false) {
            throw new Failure(404, 'not_found', 'Object not found.');
        }
        $path = $this->ns->root . '/objects/' . $digest;
        $s = NamespaceStore::regular($path);
        if ($s['size'] !== (int) $size) {
            throw new Failure(503, 'object_corrupt', 'Referenced object size is inconsistent.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new Failure(503, 'storage_unavailable', 'Object cannot be opened.');
        }
        $opened = fstat($stream);
        if (
            $opened === false
            || $opened['dev'] !== $s['dev']
            || $opened['ino'] !== $s['ino']
            || ($opened['mode'] & 0170000) !== 0100000
            || $opened['nlink'] !== 1
            || $opened['uid'] !== posix_geteuid()
            || $opened['size'] !== (int) $size
        ) {
            fclose($stream);
            throw new Failure(503, 'unsafe_file', 'Opened object did not match private regular file metadata.');
        }
        try {
            [$actualDigest, $actualSize] = Admission::transfer($stream, null);
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        }
        if ($actualSize !== (int) $size || $actualDigest !== $digest || !rewind($stream)) {
            fclose($stream);
            throw new Failure(503, 'object_corrupt', 'Referenced object failed integrity validation.');
        }
        return ['stream' => $stream, 'size' => (int) $size];
    }
}
