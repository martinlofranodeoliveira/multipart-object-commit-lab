# Runtime preparation and qualification

Development 0.1.0 targets PHP 8.4 on Linux with real nginx and foreground PHP-FPM under an unprivileged UID. Image preparation has network access; the test and demo use only private loopback HTTP and a private FastCGI Unix socket.

## Reproduce the runtime

```sh
docker build -t multipart-object-commit-lab:local .
docker run --rm --network none multipart-object-commit-lab:local php bin/qualify.php
docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=192m multipart-object-commit-lab:local php tests/run.php
docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=192m multipart-object-commit-lab:local php bin/demo.php --json
```

The PHP 8.4 FPM Bookworm base is pinned by digest. nginx and build packages come from the base's Debian repositories; installed package versions are recorded. No system service is started during preparation. This does not claim byte-identical OS package resolution.

`dependency-metadata/sqlite-source.json` records the official SQLite **3.54.1** archive URL, archive SHA-256/SHA3-256, official amalgamation SHA3-256 and expected SQL source ID. `tools/build-sqlite.sh` verifies the fixed archive SHA3-256 before extraction, checks member names/links and the `sqlite3.c` hash, and builds with FTS5. The Dockerfile supplies the reviewed public pins; no repository variables or credentials are needed. SHA3-256 and SHA-256 are distinct algorithms.

The official PHP image already contains PDO SQLite. The builder registers `/opt/sqlite/lib` with the dynamic loader before starting CLI or FPM, rather than rebuilding that builtin extension. Minimal child environments therefore select the same verified shared library. `bin/qualify.php` queries actual PDO `sqlite_version()` / `sqlite_source_id()` and creates/queries FTS5. The application rejects a different source ID/version. FTS5 is qualification evidence, not an upload feature. These checks are not a general security certification.

Build receipts in `/opt/sqlite/` record `qualification.json`, `packages.txt`, PHP/nginx/compiler versions. The source archive/build tree remain in `/opt/sqlite-build`. The minimum supported security floor is 3.51.3; this development runtime deliberately qualifies exactly 3.54.1.

## Process ownership and bounds

The harness requires PDO SQLite, POSIX, hash, proc_open, /proc, curl, nginx and /usr/bin/setsid. It refuses UID 0, starts no system service, uses no sudo/nested Docker and reads no host database or credentials. Installation paths are limited to ASCII letters/digits/slash/dot/underscore/hyphen for the fixed config renderer. FPM/nginx master groups are signalled only before their direct-child wait ownership ends. Numeric worker PIDs are never signalled by the controller: private barrier messages request worker self-SIGKILL and the FPM master reaps them.

All nginx temporary paths are explicit private directories, including otherwise-unused proxy/uwsgi/scgi defaults. Cleanup removes only known owned entries after stopping/reaping direct masters and clients. No broad arbitrary-daemon descendant cleanup is promised.

nginx bounds the body before FastCGI; explicit PHP parsing applies its limits inside admission before publication. The maintained PHP multipart parser is used. Application checks, `memory_limit`, `max_execution_time`, logical retained-state limits and observed WAL thresholds do not imply hard pre-parser RSS or physical disk quotas. The sample tmpfs limit is an external container boundary. Process-crash scenarios do not prove power-loss durability.

CI builds this same public Dockerfile, then runs test/demo offline with read-only image storage and a bounded /tmp tmpfs. Permissions are read-only; there are no publish/deploy or artifact upload steps.

## Explicit multipart parsing

FPM disables automatic POST parsing before the request starts. `Admission::fromPhp()` calls the PHP 8.4 maintained `request_parse_body()` once, with explicit bounds of 4 files / 5 parts / 1 form variable, 9 MiB body and 2 MiB file. Limit exceptions and parser warnings reject before publication. No body read precedes parsing. This avoids automatic startup truncation without enormous parser limits. Raw name normalization remains PHP-defined and is explicitly scoped in the contract; no handwritten multipart parser is used.
