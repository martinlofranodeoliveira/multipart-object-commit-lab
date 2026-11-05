# Recovery — development 0.1.0

Recovery is an explicit local operation, not an automatic expiry policy. No wall clock decides whether data is live. A digest object is live if a committed `refs` row references it.

## Command and result

```sh
php bin/recover.php /tmp/mocl-private-root --json
```

The path above illustrates the required canonical private root; use the actual namespace owned by your local controller. Recovery never creates, adopts or recursively deletes a caller directory. The namespace marker, UID, private directory modes and lock files must validate. A typical orphan-only result is:

```json
{"staged_removed":0,"objects_removed":1,"bytes_removed":3}
```

Without `--json`, a short human-readable summary precedes the same JSON line. Exit status is 0 on completion, 1 on failure and 2 on usage error. Counts describe removed regular files and their observed logical lengths, not reclaimed physical blocks. A partial filesystem failure can leave a partially completed recovery; repeating it is safe under the same cooperating model, but the failed invocation does not return a success report.

The harness tests the actual CLI through `Server::recover()`. `php bin/demo.php --json` exercises real precommit and postcommit worker death and asserts exact recovery counts. The demo removes its own namespace on successful cleanup; it is not a persistent daemon.

## Safety sequence

1. Validate the canonical private namespace and open `writer.lock`.
2. Obtain the same exclusive lock used from the start of application staging through installation and metadata COMMIT. Wait at most two seconds; report busy rather than race a writer.
3. Read committed digest references. With publication excluded and no metadata deletion API, this set cannot change during collection.
4. Enumerate only the fixed `staging/` and `objects/` directories, at most 1,024 entries each, and build the deletion plan before deleting any file.
5. Delete only UID-owned, single-link regular stages named `{32 lowercase hex}.stage`, and similarly safe objects named `{64 lowercase hex}` absent from the referenced set.
6. Request a truncating SQLite WAL checkpoint and release the lock.

Symlinks, multiple hardlinks, subdirectories, foreign names and referenced objects are never collection candidates. `parser/`, nginx temporary directories, logs, client fixture paths and the database are not scanned for payload garbage. Parser cleanup belongs to PHP and process lifetime; GC must not guess whether a parser file is active.

## Crash cases

- During staging: recognizable partial stages may remain. No row exists for them; a later recovery can remove them.
- After installation, before metadata COMMIT: complete objects may remain unreferenced. They are not visible through `readObject`; recovery removes them.
- During the SQLite transaction: SQLite rollback/WAL recovery determines whether the transaction committed, never a partial batch. Collection uses the committed reference set.
- After COMMIT, before response: all references remain. Recovery preserves every referenced object. A client retry may create a second batch sharing the same objects.

To reproduce, run `php tests/run.php` or `php bin/demo.php`; fixed barriers announce private PID/birth markers. The controller writes a private crash request at that exact barrier. The worker consumes it and sends SIGKILL to itself; the FPM master owns worker reaping. The controller never signals a numeric worker PID. The concurrent upload rendezvous uses `after_hash`, before the exclusive lock. Never wait for two writers at `after_install` while the first owns that lock.

## What recovery cannot repair

Recovery does not recreate missing referenced bytes, repair corruption, authenticate content, erase committed batches or reclaim arbitrary unknown files. Keep foreign entries for inspection. If a referenced object is missing, a symlink, size-inconsistent or digest-corrupt, reads fail rather than exposing another path or corrupt bytes. Same-UID hostile mutations and filesystem corruption are outside this advisory-lock design.

Keep any controller-owned namespace intact while investigating. Do not point the command at an upload directory from another application. The harness's disposal code applies only to namespaces it newly created, after shutting down and reaping its own processes. Directory rename and SQLite commit are not a distributed filesystem transaction; process-crash tests do not establish power-loss durability.
