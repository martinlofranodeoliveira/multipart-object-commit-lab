# Design — development 0.1.0

## Two persistence domains

SQLite can atomically publish rows, but cannot atomically commit an unrelated directory rename. The order is therefore deliberately asymmetric:

1. Admission invokes PHP 8.4 request_parse_body() once with automatic POST parsing disabled and explicit 4-file/5-part/1-variable limits. Parser exceptions or warnings reject before mutation; uploaded temporary files remain private.
2. Admission validates the complete normalized field/file structure, every upload error, every label, every actual length and every SHA-256. No application stage, object or row is created yet.
3. The optional `after_hash` test rendezvous occurs without the publication lock.
4. A writer acquires the exclusive cooperating `writer.lock` and inspects prospective global capacity.
5. Each admitted file is streamed again into an exclusively created private stage, hashing again to detect a changed admission source. Writes are checked, flushed and `fsync`ed. Files become mode 0400.
6. Under the same lock, after all files in the batch are staged, complete stages are renamed to lowercase digest names, or discarded after validating the existing object's exact length and hash. Installation or SQL errors may leave complete unreferenced objects. The writer never deletes installed objects to compensate for a COMMIT error; only explicit recovery checks committed refs under the same lock before collection.
7. The `after_install` crash barrier can suspend this single writer. It never waits for another writer to reach installation.
8. One `BEGIN IMMEDIATE` transaction inserts the batch, unique objects and every ordered reference. COMMIT publishes all associations.
9. The lock is released, then `after_commit` can suspend the response. An acknowledgement loss is not a rollback.

A process crash before step 8 can leave recognizable unreferenced stages/objects. A process crash after COMMIT leaves a complete visible batch. There is no intermediate committed batch subset. The tests terminate the real FPM worker, not a simulated repository callback.

## Ownership and concurrency

The harness creates a new random mode-0700 namespace. It holds `owner.lock` for that namespace's lifetime. PHP workers and recovery open that namespace but never own its disposal. Uploaded bytes are outside `public/`, and nginx has no upload alias, document-root file service, extension-based PHP handler or configurable application filename.

The writer/recovery lock covers staging through commit and every GC deletion. Hash admission uses only parser-owned files before this lock; recovery never examines that directory. Thus recovery cannot delete an admitted writer's stages or just-installed object. Committed references are never deleted in this version, allowing an object reader to open a referenced object without holding up publication or recovery.

SQLite still supplies foreign keys, uniqueness and read transactions. `getBatch` opens a read transaction covering batch existence and all ordered references. Unique `(batch_id, position)` and `(batch_id, label)` constraints preserve a batch's structure. The object digest is globally unique, while batch IDs and references remain distinct. A single exclusive publisher implies at most one SQLite writer transaction. WAL readers do not expose uncommitted associations.

The safety model requires cooperating processes under one non-hostile UID. PHP does not provide an `openat(O_NOFOLLOW)` storage abstraction here. Canonical private ownership, `lstat`, single-link regular-file checks and fixed names reject symlinks, but cannot defeat malicious same-UID replacement between checks and opens. Recovery does not follow or remove symlinks, foreign names, directories or multiple-hardlink entries.

## Bounds

The writer reserves the prospective count and peak logical payload under the lock. The peak includes all existing objects/stages plus every incoming staging byte, even when content will deduplicate. This conservative rule can reject a request whose eventual retained footprint would fit. Orphans count until recovery. Staging admission is similarly conservative: existing stages plus all incoming files must fit four slots.

The DB cap is 2,048 pages of 4,096 bytes; connections reapply `max_page_count`. WAL is observed at admission with a 4 MiB threshold; passive checkpointing follows commits and recovery requests truncation. A stalled reader can defer checkpointing. Neither this threshold nor the private-directory byte calculation is a physical quota. Foreign payload entries make writer inventory fail closed; recovery preserves them for inspection.

nginx allows four complete-header active requests per loopback address, FPM has four static children, and the harness owns at most four curl clients. Connection/header/body inactivity limits, a six-second FPM termination timeout, curl's eight-second total timeout and a ten-second monotonic scenario deadline serve different purposes. PHP execution time is not an upload or filesystem wall-clock guarantee. The fixed parser limits constrain accepted traffic but do not establish a hard RSS ceiling. Parser and nginx body temporary bytes are outside the 64 MiB application payload accounting.

Client response files are inspected during polling and read with explicit caps. Diagnostic watchdog accounting stops a scenario above 256 KiB; retained individual logs are trimmed to 64 KiB after shutdown. Polling thresholds can overshoot between checks; they are not physical quotas. No access log, request-body log, telemetry or trace collection is enabled.

## Process lifetime

Masters and clients execute as argument arrays through `setsid`, never through a shell. Each master owns a separate session/process group containing its daemon workers. They stay foreground. Linux `/proc` observations detect completion without reaping; shutdown sends TERM then KILL to that owned group before reaping the master. An unreaped PID cannot be reused. No group signal follows `proc_get_status`/`proc_close` ending wait ownership. The test-only worker kill verifies the private barrier marker's PID and Linux process birth identity and writes a `.crash` marker so the worker signals itself; FPM reaps that worker.

Normal shutdown budgets 100 ms between TERM/KILL and at most one second to observe reapability before reporting a failure. These are userspace budgets, not a guarantee that the kernel can interrupt unkillable I/O. Unexpected unsafe cleanup preserves a namespace rather than traversing arbitrary trees. Disposal is nonrecursive over fixed owned directories only, after process cleanup. Caller input paths are never adopted as object paths or namespace roots.

## Durability and scope

A same-filesystem rename makes an object complete before metadata can refer to it; all payload directories are siblings in one private namespace. File `fsync` and SQLite `synchronous=FULL` improve ordinary durability but there is no directory-fsync protocol or cross-filesystem commit. Process termination tests are not power-cut qualification. Filesystems, mounts, storage controllers and corruption can invalidate stronger durability assumptions. Existing objects are checked on reuse, and reads verify regular-file metadata, exact byte length and SHA-256 digest before returning the stream.

No idempotency key, authentication, malware detection, deletion, expiry clock or distributed locking is included. SHA-256 equality supplies content identity, not provenance or authorization.

## Primary references

- PHP uploads: https://www.php.net/manual/en/features.file-upload.php
- FPM configuration: https://www.php.net/manual/en/install.fpm.configuration.php
- PDO transactions: https://www.php.net/manual/en/pdo.transactions.php
- SQLite atomic commit: https://www.sqlite.org/atomiccommit.html
- nginx request processing: https://nginx.org/en/docs/http/request_processing.html

These references inform a narrow application contract; the project does not claim comprehensive multipart, HTTP or storage-standard compliance.
