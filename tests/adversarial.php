<?php
declare(strict_types=1);

use CommitLab\Gates;
use CommitLab\Scenario;
use CommitLab\Server;

return static function (): void {
    $server = new Server();
    try {
        foreach (['/index.php', '/objects', '/parser/file', '/staging/file', '/unknown'] as $route) {
            same(404, $server->request('GET', $route)->result()['status'], 'fixed routes only');
        }
        same(405, $server->request('GET', '/batches')->result()['status'], 'method contract');
        same(400, $server->request('POST', '/batches?x=1', ['x'], '["x"]')->result()['status'], 'queries rejected');
        same(422, $server->request('POST', '/batches', ['x', 'y'], '["duplicate","duplicate"]')->result()['status'], 'duplicate labels rejected');
        same(422, $server->request('POST', '/batches', ['x', 'y'], '["only one"]')->result()['status'], 'missing label rejects complete batch');
        same(422, $server->request('POST', '/batches', ['x'], '{"0":"object is not a list"}')->result()['status'], 'JSON object not accepted as list');
        same(422, $server->request('POST', '/batches', ['x'], '["bad\\u0000label"]')->result()['status'], 'NUL label rejected');
        same(422, $server->request('POST', '/batches', ['x'], '["' . str_repeat('a', 257) . '"]')->result()['status'], '257-byte label rejected');
        // Automatic PHP parsing used to truncate part 17 and publish 4 refs.
        $overflow = array_fill(0, 4, ['file', 'files[]', 'abc']);
        foreach (range(1, 12) as $_) {
            $overflow[] = ['field', 'labels', '["a","b","c","d"]'];
        }
        $overflow[] = ['file', 'files[]', 'fifth'];
        same(422, multipartVector($server, $overflow)['status'], 'explicit parser rejects formerly truncated 17-part body');
        same(422, multipartVector($server, [['file', 'files[]', 'abc'], ['field', 'labels', '["a"]'], ['field', 'labels', '["a"]']])['status'], 'duplicate labels exceed explicit single variable limit');
        same(422, multipartVector($server, [['file', 'files' . str_repeat('[x]', 70), 'abc'], ['field', 'labels', '["a"]']])['status'], 'parser nesting warning rejects before publication');
        $db = Scenario::sql($server);
        same(0, (int) $db->query('SELECT count(*) FROM batches')->fetchColumn(), 'invalid requests publish no rows');
        unset($db);

        // Verify barrier self-crash request discipline: controller never signals a numeric PID.
        fails(static fn() => $server->killWorker(['marker' => '../outside.ready', 'pid' => 1, 'birth' => '1']), RuntimeException::class, 'invalid barrier marker name rejected');
        $readyName = 'after_install.0123456789abcdef.ready';
        $readyPath = $server->ns->root . '/gates/' . $readyName;
        $selfBirth = Gates::birth(getmypid());
        file_put_contents($readyPath, json_encode(['pid' => getmypid(), 'birth' => $selfBirth], JSON_THROW_ON_ERROR));
        fails(static fn() => $server->killWorker(['marker' => $readyName, 'pid' => getmypid(), 'birth' => $selfBirth . 'x']), RuntimeException::class, 'mismatched barrier birth rejected');
        check(! is_file($readyPath . '.crash'), 'mismatched barrier writes no crash marker');
        $server->killWorker(['marker' => $readyName, 'pid' => getmypid(), 'birth' => $selfBirth]);
        same('self-crash', file_get_contents($readyPath . '.crash'), 'killWorker writes private crash marker without signalling controller PID');
        fails(static fn() => $server->killWorker(['marker' => $readyName, 'pid' => getmypid(), 'birth' => $selfBirth]), RuntimeException::class, 'duplicate crash marker rejected');
        unlink($readyPath . '.crash');
        unlink($readyPath);

        // Boundary batch: 4 files including 0-byte, exact 2 MiB, intra-batch duplicate content, and 1..256 byte UTF-8 labels.
        $twoMib = str_repeat('M', 2097152);
        $maxLabel = str_repeat('é', 128);
        $boundary = Scenario::json($server->createBatch(['', $twoMib, 'dup', 'dup'], ['a', $maxLabel, 'dup-1', 'dup-2']), 201);
        same([0, 2097152, 3, 3], array_column($boundary['files'], 'size'), 'boundary 0-byte, 2 MiB and duplicate sizes');
        same($boundary, Scenario::json($server->getBatch($boundary['id']), 200), 'boundary batch snapshot');
        $emptyRes = $server->readObject(hash('sha256', ''));
        same(200, $emptyRes['status'], '0-byte object HTTP 200');
        same('', $emptyRes['body'], '0-byte object exact body');
        $twoMibRes = $server->readObject(hash('sha256', $twoMib));
        same(200, $twoMibRes['status'], '2 MiB object HTTP 200');
        same(2097152, strlen($twoMibRes['body']), '2 MiB object exact byte count');
        same(hash('sha256', $twoMib), hash('sha256', $twoMibRes['body']), '2 MiB object SHA-256');

        $baseline = Scenario::json($server->upload(['abc'], ['baseline']), 201);
        $abcDigest = hash('sha256', 'abc');
        $abcPath = $server->ns->root . '/objects/' . $abcDigest;
        chmod($abcPath, 0600);
        file_put_contents($abcPath, 'abd'); // Same 3-byte size, wrong SHA-256.
        chmod($abcPath, 0400);
        $corruptRead = Scenario::json($server->readObject($abcDigest), 503);
        same('object_corrupt', $corruptRead['error']['code'], 'HTTP readObject rejects same-size corrupt bytes');
        chmod($abcPath, 0600);
        file_put_contents($abcPath, 'abc');
        chmod($abcPath, 0400);
        same('abc', $server->readObject($abcDigest)['body'], 'restored object reads cleanly');

        foreach (range(0, 3) as $index) {
            file_put_contents($server->ns->root . '/staging/' . str_pad(dechex($index), 32, '0', STR_PAD_LEFT) . '.stage', 'abandoned');
        }
        $capacity = Scenario::json($server->upload(['xyz'], ['new']), 409);
        same('capacity_exceeded', $capacity['error']['code'], 'prospective staging reservation');
        same(4, $server->recover()['staged_removed'], 'explicit recovery releases abandoned stage capacity');
        $server->ns->exclusive(function () use ($server, $abcDigest): void {
            $db = Scenario::sql($server);
            $db->exec('PRAGMA foreign_keys=ON');
            $db->beginTransaction();
            $batch = $db->prepare('INSERT INTO batches(id) VALUES (?)');
            $ref = $db->prepare('INSERT INTO refs(batch_id,position,label,digest) VALUES (?,0,?,?)');
            for ($index = 1; $index <= 126; $index++) {
                $id = str_pad(dechex($index), 32, '0', STR_PAD_LEFT);
                $batch->execute([$id]);
                $ref->execute([$id, 'synthetic seeded reference', $abcDigest]);
            }
            $db->commit();
        });
        Scenario::json($server->upload(['xyz'], ['would exceed retained batches']), 409);
        $db = Scenario::sql($server);
        same(128, (int) $db->query('SELECT count(*) FROM batches')->fetchColumn(), 'retained batch cap');
        same(131, (int) $db->query('SELECT count(*) FROM refs')->fetchColumn(), 'no capacity-failure reference');
        check(!in_array(hash('sha256', 'xyz'), $server->ns->entries('objects'), true), 'reserve before any installation');
        same($baseline, Scenario::json($server->request('GET', '/batches/' . $baseline['id'])->result(), 200), 'capacity rejection preserves original snapshot');
        unset($db);
    } finally {
        $server->close();
    }
};
