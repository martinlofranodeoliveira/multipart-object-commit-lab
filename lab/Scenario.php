<?php
declare(strict_types=1);
namespace CommitLab;

final class Scenario
{
    private static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException('Oracle failed: ' . $message);
        }
    }

    public static function json(array $response, int $status): array
    {
        self::check($response['exit'] === 0 && $response['status'] === $status, 'HTTP status ' . $status . ', received ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        return json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
    }

    // Independent SQL oracle: no Repository or Database connection helper.
    public static function sql(Server $server): \PDO
    {
        return new \PDO('sqlite:' . $server->ns->root . '/metadata.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    }

    private static function counts(Server $server, int $batches, int $references, int $objects): void
    {
        $db = self::sql($server);
        foreach (['batches' => $batches, 'refs' => $references, 'objects' => $objects] as $table => $expected) {
            self::check((int) $db->query('SELECT count(*) FROM ' . $table)->fetchColumn() === $expected, 'direct SQL count for ' . $table);
        }
    }

    private static function objectBytes(Server $server, string $digest, string $bytes): void
    {
        $response = $server->request('GET', '/objects/' . $digest)->result();
        self::check($response['exit'] === 0 && $response['status'] === 200 && $response['body'] === $bytes, 'curl object bytes');
        self::check(file_get_contents($server->ns->root . '/objects/' . $digest) === $bytes, 'independent on-disk object bytes');
        self::check(hash('sha256', $bytes) === $digest, 'independent SHA-256');
    }

    private static function withServer(callable $body): void
    {
        $server = new Server();
        try {
            $body($server);
        } finally {
            $server->close();
        }
    }

    public static function all(): array
    {
        $fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/batches.json'), true, 8, JSON_THROW_ON_ERROR);
        $abc = $fixture['abc_sha256'];
        $xyz = $fixture['xyz_sha256'];
        self::check(hash('sha256', 'abc') === $abc && hash('sha256', 'xyz') === $xyz, 'published SHA-256 test vectors');
        $passed = [];

        self::withServer(function (Server $s) use ($abc, $xyz): void {
            $batch = self::json($s->upload(['abc', 'xyz'], ['alpha', 'beta']), 201);
            self::check(array_column($batch['files'], 'digest') === [$abc, $xyz], 'two complete references in order');
            self::check(array_column($batch['files'], 'size') === [3, 3], 'exact sizes');
            self::check(self::json($s->request('GET', '/batches/' . $batch['id'])->result(), 200) === $batch, 'snapshot matches publication');
            self::objectBytes($s, $abc, 'abc');
            self::objectBytes($s, $xyz, 'xyz');
            self::counts($s, 1, 2, 2);
        });
        $passed[] = 'two-file publication and independent byte/SHA/SQL oracles';

        self::withServer(function (Server $s) use ($abc): void {
            $first = self::json($s->upload(['abc'], ['first']), 201);
            $second = self::json($s->upload(['abc'], ['second']), 201);
            self::check($first['id'] !== $second['id'], 'distinct batch identity');
            self::counts($s, 2, 2, 1);
            self::check($s->ns->entries('objects') === [$abc], 'exactly one physical object');
            self::objectBytes($s, $abc, 'abc');
        });
        $passed[] = 'deduplication preserves separate batch references';

        self::withServer(function (Server $s) use ($abc): void {
            $baseline = self::json($s->upload(['abc'], ['baseline']), 201);
            $before = $s->request('GET', '/batches/' . $baseline['id'])->result()['body'];
            $db = self::sql($s);
            $snapshot = [];
            foreach (['batches' => 'id', 'objects' => 'digest', 'refs' => 'batch_id,position'] as $table => $order) {
                $snapshot[$table] = $db->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order)->fetchAll();
            }
            unset($db);
            self::json($s->upload(['x', 'y', 'z', str_repeat('Q', 2097153)], ['one', 'two', 'three', 'oversize']), 422);
            self::json($s->upload(['a', 'b', 'c', 'd', 'e'], ['one', 'two', 'three', 'four', 'five']), 422);
            $db = self::sql($s);
            foreach (['batches' => 'id', 'objects' => 'digest', 'refs' => 'batch_id,position'] as $table => $order) {
                self::check($db->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order)->fetchAll() === $snapshot[$table], 'invalid batch changes no SQL rows');
            }
            self::check($s->request('GET', '/batches/' . $baseline['id'])->result()['body'] === $before, 'existing snapshot byte-for-byte unchanged');
            self::check($s->ns->entries('objects') === [$abc] && $s->ns->entries('staging') === [], 'no installation before complete admission');
        });
        $passed[] = 'oversize final file and five-file request publish nothing';

        self::withServer(function (Server $s) use ($abc, $xyz): void {
            self::json($s->upload(['abc'], ['keep']), 201);
            $s->arm('after_install');
            $pending = $s->request('POST', '/batches', ['xyz'], '["orphan"]');
            $marker = $s->ready('after_install', 1)[0];
            $s->killWorker($marker);
            $lost = $pending->result();
            self::check($lost['status'] !== 201, 'precommit worker death has no successful acknowledgement');
            $s->restart();
            self::counts($s, 1, 1, 1);
            $missing = $s->request('GET', '/objects/' . $xyz)->result();
            self::check($missing['status'] === 404, 'installed orphan is not publicly readable');
            self::check($s->recover() === ['staged_removed' => 0, 'objects_removed' => 1, 'bytes_removed' => 3], 'exact orphan recovery');
            self::check($s->ns->entries('objects') === [$abc], 'referenced object survives recovery');
            self::objectBytes($s, $abc, 'abc');
        });
        $passed[] = 'precommit crash leaves only an invisible recoverable orphan';

        self::withServer(function (Server $s) use ($abc, $xyz): void {
            $s->arm('after_commit');
            $pending = $s->request('POST', '/batches', ['abc', 'xyz'], '["left","right"]');
            $s->killWorker($s->ready('after_commit', 1)[0]);
            $pending->result();
            $s->restart();
            self::counts($s, 1, 2, 2);
            $id = (string) self::sql($s)->query('SELECT id FROM batches')->fetchColumn();
            $batch = self::json($s->request('GET', '/batches/' . $id)->result(), 200);
            self::check(array_column($batch['files'], 'digest') === [$abc, $xyz], 'complete batch survives lost acknowledgement');
            self::check($s->recover()['objects_removed'] === 0, 'postcommit recovery preserves all referenced objects');
            self::objectBytes($s, $abc, 'abc');
            self::objectBytes($s, $xyz, 'xyz');
        });
        $passed[] = 'postcommit lost acknowledgement retains one complete batch';

        self::withServer(function (Server $s) use ($abc): void {
            $s->arm('after_hash');
            $left = $s->request('POST', '/batches', ['abc'], '["left"]');
            $right = $s->request('POST', '/batches', ['abc'], '["right"]');
            $markers = $s->ready('after_hash', 2);
            self::check($markers[0]['pid'] !== $markers[1]['pid'], 'two separate FPM workers rendezvous before the publication lock');
            $s->release('after_hash');
            $a = self::json($left->result(), 201);
            $b = self::json($right->result(), 201);
            self::check($a['id'] !== $b['id'], 'concurrent writers keep distinct batches');
            self::counts($s, 2, 2, 1);
            self::check($s->ns->entries('objects') === [$abc], 'one immutable shared installation');
            self::objectBytes($s, $abc, 'abc');
        });
        $passed[] = 'concurrent identical uploads rendezvous without lock deadlock';

        self::withServer(function (Server $s) use ($abc, $fixture): void {
            $label = $fixture['traversal_label'];
            $caller = $s->ns->root . '/client/caller.txt';
            file_put_contents($caller, 'synthetic caller sentinel');
            $batch = self::json($s->upload(['abc'], [$label]), 201);
            $read = self::json($s->request('GET', '/batches/' . $batch['id'])->result(), 200);
            self::check($read['files'][0]['label'] === $label, 'traversal-style UTF-8 label is exact metadata');
            self::check(file_get_contents($caller) === 'synthetic caller sentinel', 'caller path preserved');
            self::check($s->ns->entries('objects') === [$abc] && $s->ns->entries('staging') === [], 'independent payload directory inventory');
            foreach (NamespaceStore::DIRECTORIES as $directory) {
                foreach ($s->ns->entries($directory) as $name) {
                    self::check($name !== 'escaped.php', 'no label-derived file in namespace');
                }
            }
            self::check(!NamespaceStore::exists($s->ns->root . '/escaped.php'), 'no traversal output at namespace root');
            self::objectBytes($s, $abc, 'abc');
        });
        $passed[] = 'traversal-style labels remain data and caller paths survive';

        return ['version' => Limits::VERSION, 'ok' => true, 'scenarios' => $passed, 'vectors' => ['abc' => $abc, 'xyz' => $xyz]];
    }
}
