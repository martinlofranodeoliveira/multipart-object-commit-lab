<?php
declare(strict_types=1);
namespace CommitLab;

final class Database
{
    public static function qualify(): array
    {
        $db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $version = (string) $db->query('SELECT sqlite_version()')->fetchColumn();
        $source = (string) $db->query('SELECT sqlite_source_id()')->fetchColumn();
        if (version_compare($version, '3.51.3', '<') || $version !== '3.54.1') {
            throw new \RuntimeException('This development version requires linked SQLite 3.54.1; found ' . $version);
        }
        if ($source !== '2026-10-16 14:45:20 555e07ef33f69a0957d24d8e555b702464a131d6f1607f4c3c1e4551e8e6ca76') {
            throw new \RuntimeException('Unexpected SQLite source ID');
        }
        $db->exec('CREATE VIRTUAL TABLE temp.qualification USING fts5(body)');
        $db->exec("INSERT INTO qualification(body) VALUES ('synthetic qualification')");
        if ((int) $db->query("SELECT count(*) FROM qualification WHERE qualification MATCH 'synthetic'")->fetchColumn() !== 1) {
            throw new \RuntimeException('Linked FTS5 qualification failed');
        }
        return ['sqlite_version' => $version, 'sqlite_source_id' => $source, 'fts5' => true];
    }

    public static function initialize(NamespaceStore $ns): void
    {
        self::qualify();
        $path = $ns->root . '/metadata.sqlite';
        if (NamespaceStore::exists($path)) {
            throw new \RuntimeException('Initialization never overwrites an existing database');
        }
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create metadata database');
        }
        fclose($handle);
        $db = self::connect($ns);
        $db->exec('PRAGMA page_size=4096');
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA max_page_count=' . Limits::DB_PAGES);
        $db->exec('CREATE TABLE batches (id TEXT PRIMARY KEY CHECK(length(id)=32)) WITHOUT ROWID');
        $db->exec('CREATE TABLE objects (digest TEXT PRIMARY KEY CHECK(length(digest)=64), size INTEGER NOT NULL CHECK(size BETWEEN 0 AND 2097152)) WITHOUT ROWID');
        $db->exec('CREATE TABLE refs (batch_id TEXT NOT NULL REFERENCES batches(id), position INTEGER NOT NULL CHECK(position BETWEEN 0 AND 3), label TEXT NOT NULL, digest TEXT NOT NULL REFERENCES objects(digest), PRIMARY KEY(batch_id,position), UNIQUE(batch_id,label)) WITHOUT ROWID');
        $db->exec('CREATE INDEX refs_digest ON refs(digest)');
        $db->exec('PRAGMA user_version=1');
        $db->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
    }

    public static function connect(NamespaceStore $ns): \PDO
    {
        NamespaceStore::regular($ns->root . '/metadata.sqlite');
        foreach (['-wal', '-shm'] as $suffix) {
            $path = $ns->root . '/metadata.sqlite' . $suffix;
            if (NamespaceStore::exists($path)) {
                NamespaceStore::regular($path);
            }
        }
        $db = new \PDO('sqlite:' . $ns->root . '/metadata.sqlite', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 1,
        ]);
        if ($db->query('SELECT sqlite_version()')->fetchColumn() !== '3.54.1') {
            throw new \RuntimeException('Unexpected linked SQLite version');
        }
        $db->exec('PRAGMA foreign_keys=ON');
        $db->exec('PRAGMA trusted_schema=OFF');
        $db->exec('PRAGMA synchronous=FULL');
        $db->exec('PRAGMA busy_timeout=500');
        $db->exec('PRAGMA max_page_count=' . Limits::DB_PAGES);
        return $db;
    }

    public static function checkWal(NamespaceStore $ns): void
    {
        $path = $ns->root . '/metadata.sqlite-wal';
        if (NamespaceStore::exists($path) && NamespaceStore::regular($path)['size'] > Limits::WAL_BYTES) {
            throw new Failure(409, 'capacity_exceeded', 'Observed WAL threshold reached; maintenance is required.');
        }
    }
}
