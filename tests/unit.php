<?php
declare(strict_types=1);

use CommitLab\Admission;
use CommitLab\Database;
use CommitLab\Failure;
use CommitLab\NamespaceStore;
use CommitLab\Recovery;
use CommitLab\Repository;

return static function (): void {
    same(['../é.php', 'plain'], Admission::labels('["../é.php","plain"]', 2), 'labels are data');
    same(['a', 'A'], Admission::labels('["a","A"]', 2), 'bytewise uniqueness');
    $maxLabel = str_repeat('é', 128); // Exact 256 UTF-8 bytes.
    same(256, strlen($maxLabel), 'exact 256-byte multibyte UTF-8 label');
    same([$maxLabel], Admission::labels(json_encode([$maxLabel], JSON_THROW_ON_ERROR), 1), '256-byte label accepted');
    foreach (['{"0":"a"}', '{}', '[]', '[1]', '[""]', '["same","same"]', '["a\\u0000b"]', '["\xff"]', '["' . str_repeat('é', 129) . '"]'] as $json) {
        fails(static fn() => Admission::labels($json, str_contains($json, 'same') ? 2 : 1), Failure::class, 'invalid labels');
    }
    $in = fopen('php://temp', 'w+b');
    fwrite($in, str_repeat('s', 2097153));
    rewind($in);
    fails(static fn() => Admission::transfer($in, null), Failure::class, 'stream bound enforced independently of reported size');
    fclose($in);

    $ns = NamespaceStore::create();
    $owner = $ns->own();
    $caller = $ns->root . '-caller';
    file_put_contents($caller, 'synthetic external sentinel');
    try {
        Database::initialize($ns);
        $repo = new Repository($ns);
        $directBatch = $repo->createBatch(files: [
            ['label' => 'caller-file', 'path' => $caller],
            ['label' => 'inline-empty', 'bytes' => ''],
        ]);
        same($directBatch, $repo->getBatch(batchId: $directBatch['id']), 'direct Repository API createBatch and getBatch');
        same('synthetic external sentinel', file_get_contents($caller), 'direct createBatch preserves caller file');
        $directStream = $repo->readObject(digest: hash('sha256', 'synthetic external sentinel'));
        same('synthetic external sentinel', stream_get_contents($directStream['stream']), 'direct readObject stream bytes');
        fclose($directStream['stream']);
        $emptyStream = $repo->readObject(digest: hash('sha256', ''));
        same(0, $emptyStream['size'], 'zero-byte object size');
        same('', stream_get_contents($emptyStream['stream']), 'zero-byte object bytes');
        fclose($emptyStream['stream']);

        // Installation failure publishes no refs. Complete earlier objects are
        // retained until recovery proves they have no committed references.
        $badExistingDigest = hash('sha256', 'corrupt-existing');
        file_put_contents($ns->root . '/objects/' . $badExistingDigest, 'bad');
        $newUninstalledDigest = hash('sha256', 'fresh-before-failure');
        fails(static fn() => $repo->createBatch([
            ['label' => 'fresh', 'bytes' => 'fresh-before-failure'],
            ['label' => 'corrupt', 'bytes' => 'corrupt-existing'],
        ]), Failure::class, 'corrupt existing object rejects batch');
        same('fresh-before-failure', file_get_contents($ns->root . '/objects/' . $newUninstalledDigest), 'failed installation retains complete orphan');
        $checkDb = Database::connect($ns);
        same(1, (int) $checkDb->query('SELECT count(*) FROM batches')->fetchColumn(), 'installation failure publishes no batch');
        same(2, (int) $checkDb->query('SELECT count(*) FROM refs')->fetchColumn(), 'installation failure publishes no refs');
        unset($checkDb);
        unlink($ns->root . '/objects/' . $badExistingDigest);
        same(['staged_removed' => 0, 'objects_removed' => 1, 'bytes_removed' => strlen('fresh-before-failure')], (new Recovery($ns))->run(), 'explicit recovery removes only the failed complete orphan');
        same($directBatch, $repo->getBatch($directBatch['id']), 'recovery preserves earlier committed batch');

        // A real SQLite trigger aborts metadata insertion after installation.
        // The transaction rolls back all rows; GC alone removes safe orphans.
        $faultDb = Database::connect($ns);
        $faultDb->exec("CREATE TRIGGER fail_new_ref BEFORE INSERT ON refs BEGIN SELECT RAISE(ABORT, 'synthetic SQL fault'); END");
        fails(static fn() => $repo->createBatch([['label' => 'sql-failure', 'bytes' => 'installed-before-sql-failure']]), PDOException::class, 'actual SQL failure prevents metadata publication');
        same(1, (int) $faultDb->query('SELECT count(*) FROM batches')->fetchColumn(), 'SQL failure rolls back batch row');
        same(2, (int) $faultDb->query('SELECT count(*) FROM refs')->fetchColumn(), 'SQL failure rolls back refs');
        $faultDb->exec('DROP TRIGGER fail_new_ref');
        unset($faultDb);
        same('installed-before-sql-failure', file_get_contents($ns->root . '/objects/' . hash('sha256', 'installed-before-sql-failure')), 'SQL error retains complete installed bytes');
        same(['staged_removed' => 0, 'objects_removed' => 1, 'bytes_removed' => strlen('installed-before-sql-failure')], (new Recovery($ns))->run(), 'SQL failure orphan reclaimed explicitly');
        same($directBatch, $repo->getBatch($directBatch['id']), 'SQL failure and recovery preserve prior refs');

        $keep = hash('sha256', 'abc');
        $orphan = hash('sha256', 'orphan');
        $link = hash('sha256', 'link');
        file_put_contents($ns->root . '/objects/' . $keep, 'abc');
        file_put_contents($ns->root . '/objects/' . $orphan, 'orphan');
        file_put_contents($ns->root . '/objects/foreign.txt', 'foreign');
        symlink($caller, $ns->root . '/objects/' . $link);
        file_put_contents($ns->root . '/staging/' . str_repeat('a', 32) . '.stage', 'partial');
        symlink($caller, $ns->root . '/staging/' . str_repeat('b', 32) . '.stage');
        file_put_contents($ns->root . '/parser/parser-owned', 'parser');
        $db = Database::connect($ns);
        $db->prepare('INSERT INTO batches VALUES (?)')->execute([str_repeat('c', 32)]);
        $db->prepare('INSERT INTO objects VALUES (?,3)')->execute([$keep]);
        $db->prepare('INSERT INTO refs VALUES (?,0,?,?)')->execute([str_repeat('c', 32), 'keep', $keep]);
        unset($db);
        $result = (new Recovery($ns))->run();
        same(['staged_removed' => 1, 'objects_removed' => 1, 'bytes_removed' => 13], $result, 'recognized orphan and partial stage only');
        same('abc', file_get_contents($ns->root . '/objects/' . $keep), 'referenced bytes preserved');
        same('synthetic external sentinel', file_get_contents($caller), 'symlink target preserved');
        check(is_link($ns->root . '/objects/' . $link), 'recovery leaves symlinks untouched');
        check(is_link($ns->root . '/staging/' . str_repeat('b', 32) . '.stage'), 'staging symlink untouched');
        same('foreign', file_get_contents($ns->root . '/objects/foreign.txt'), 'foreign name preserved');
        same('parser', file_get_contents($ns->root . '/parser/parser-owned'), 'parser temporary excluded');
        same(['staged_removed' => 0, 'objects_removed' => 0, 'bytes_removed' => 0], (new Recovery($ns))->run(), 'recovery repeat is stable');

        // Same-size byte corruption on a referenced object must never be returned as valid digest content.
        file_put_contents($ns->root . '/objects/' . $keep, 'abd');
        fails(static fn() => $repo->readObject($keep), Failure::class, 'same-size corrupt referenced object rejected on read');

        unlink($ns->root . '/objects/' . $keep);
        symlink($caller, $ns->root . '/objects/' . $keep);
        fails(static fn() => $repo->readObject($keep), Failure::class, 'referenced symlink is not read');
        fails(static fn() => NamespaceStore::open($ns->root . '/..'), Failure::class, 'noncanonical root rejected');
    } finally {
        $ns->dispose();
        flock($owner, LOCK_UN);
        fclose($owner);
        unlink($caller);
    }
};
