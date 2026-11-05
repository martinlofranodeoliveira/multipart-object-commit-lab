# Contract — development 0.1.0

This file defines the initial stable application subset. Breaking changes to this contract require a documented version change. It does not promise a production service, raw multipart conformance, exactly-once requests or universal power-loss durability.

## Operations and routes

Only loopback IPv4 HTTP is accepted by the private nginx instance. Only these routes reach one fixed FastCGI script (and are also exposed on `CommitLab\Repository` and `CommitLab\Server`):

| Operation | Request | Success |
| --- | --- | --- |
| createBatch(files) | `POST /batches` | 201 JSON batch |
| getBatch(batchId) | `GET /batches/{32 lowercase hex}` | 200 JSON batch |
| readObject(digest) | `GET /objects/{64 lowercase hex}` | 200 exact bytes |

No query parameters, HEAD, ranges, content negotiation, redirects or list/delete routes are supplied. A recognized route with a wrong method returns 405 with `Allow`; an unknown route returns nginx 404. Application errors are JSON, but nginx/FPM transport rejections may be HTML or a closed connection.

## Multipart input

PHP 8.4's maintained `request_parse_body()` parses the body exactly once inside admission; FPM `enable_post_data_reading=Off` prevents silent automatic POST parsing. `Content-Type` must begin with `multipart/form-data;`. A known nonnegative decimal content length no greater than 9,437,184 bytes is required; nginx also limits the body to 9 MiB before FastCGI. The explicit parse sets `post_max_size=9MiB`, `upload_max_filesize=2MiB`, `max_file_uploads=4`, `max_input_vars=1`, and `max_multipart_body_parts=5`. Parser count/size exceptions and warnings reject before hashing or mutation. Header buffers are 1 KiB plus two 2 KiB large buffers. These bounds do not establish a pre-parser RSS quota.

The **PHP-normalized** representation must contain exactly:

- One scalar `labels` POST field: at most 4,096 bytes of strict JSON, depth at most 8, a list containing 1–4 strings.
- One flat, consecutively indexed `files[]` upload array containing 1–4 files. A fifth physical file or excess body parts/variables raise an explicit parse exception and reject the request, even when the total body fits. Automatic POST parsing, which can silently truncate such inputs, is disabled.
- All upload statuses `UPLOAD_ERR_OK`, real `is_uploaded_file` sources, integer lengths and no nested upload arrays. Zero-byte files are permitted. Every actual streamed file is at most 2,097,152 bytes and exactly matches PHP's reported size.
- One label per file, in order. Each label is 1–256 UTF-8 bytes, contains no NUL and is bytewise unique within that batch. No trimming, case folding or Unicode normalization is performed. A label such as `../run/escaped.php — rótulo` is returned exactly as metadata.

PHP filename, full_path and MIME-hint values are not names used by storage or retrieval. They are not executable filenames or authorization claims. Duplicate raw form field spellings and arbitrary raw multipart syntax are subject to PHP normalization; this API does not reconstruct raw parts or certify that ignored parser fields never existed. Clients must send the canonical representation above. Post-parse validation is not a hard pre-parser RSS bound.

Every label, status, file length and SHA-256 must validate before **any** application payload installation or metadata publication. A failed fourth file does not publish the first three. Parser-owned temporaries may still have existed and are cleaned by PHP/process lifecycle rather than recovery.

## Output

A batch is exactly this structure, with a fresh 128-bit random lowercase hexadecimal ID and ordered file entries:

```json
{"id":"00000000000000000000000000000001","files":[{"label":"alpha","digest":"ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad","size":3}]}
```

The ID here is a synthetic shape example, not a fixed generated identifier. `size` is an integer byte count; digest is lowercase SHA-256 of exact uploaded bytes. JSON is UTF-8, compact, newline terminated, with no-store and nosniff headers. Objects use `application/octet-stream`, `Content-Disposition: attachment`, nosniff and an exact content length, never uploaded content types or names. `readObject(digest)` verifies that the referenced object is a private single-link regular file whose exact byte length and SHA-256 digest match the committed reference before returning the stream; corrupt bytes fail with 503 `object_corrupt`. An installed but unreferenced orphan is 404, not publicly readable. Labels are untrusted metadata; consumers must escape them in their own output contexts.

## Atomicity, identity and failures

All batch references become visible together in one SQLite commit. Committed rows refer only to fully installed immutable objects under the cooperating-process model. Readers obtain a batch snapshot in one read transaction. Identical bytes in different requests share one physical digest object but retain distinct batch IDs and associations. Digest equality does not authenticate origin, uploader, rights or safety.

An HTTP response can be lost after COMMIT. Retrying can create a second batch; no idempotency key or exactly-once guarantee exists. Inspect retained metadata rather than assuming transport failure means rollback.

Application error shape:

```json
{"error":{"code":"invalid_upload","message":"Every file must be a complete upload of at most 2 MiB."}}
```

Clients should branch on HTTP status and code, not human message wording. Codes/statuses: 400 `query_not_supported`; 403 `loopback_only`; 404 `not_found`; 405 `method_not_allowed`; 409 `capacity_exceeded`; 413 `body_limit`; 415 `multipart_required`; 422 `invalid_labels` or `invalid_upload`; 503 storage, unsafe-inventory, lock/busy, integrity or test-gate failures. Internal exceptions are sanitized to 503 `storage_unavailable`. nginx can independently return 400, 408, 413, 429, 502 or 504. No error response exposes a private file path or stack trace.

## Finite retained-state and request limits

| Resource | Limit |
| --- | --- |
| Harness active curl clients / FPM children | 4 / 4 |
| nginx complete-header active requests per loopback address | 4 |
| Retained batches | 128 |
| Committed references | 512 |
| Installed objects, including recognized orphans | 128 |
| Application stages, existing plus prospective incoming files | 4 |
| Observed objects + stages + prospective staging bytes | 64 MiB |
| SQLite database | 2,048 pages, initialized at 4,096 bytes/page |
| Observed WAL at admission | 4 MiB |
| Harness response read | 2,162,688 bytes |
| Harness response headers / status read | 16 KiB / 16 bytes |
| Retained individual diagnostic / watchdog total | 64 KiB / 256 KiB |
| Directory inspection | 1,024 entries per fixed directory |

Reservations occur under the publication lock before stages or objects are created. Accounting is deliberately conservative and includes orphans until recovery. Foreign or unsafe payload entries cause writer admission to fail closed. Observed thresholds are not physical quotas; bytes can overshoot polling thresholds. DB/WAL, parser temporaries, nginx temporaries, diagnostics and client fixtures are separate from the payload-byte threshold. Deployment-level disk/RSS quotas are excluded.

Deadlines: lock acquisition 2 seconds; optional fixed test gate 3 seconds; nginx header inactivity 3 seconds, body/send inactivity 5 seconds, FastCGI read inactivity 7 seconds; FPM request termination 6 seconds; curl connect/total 1/8 seconds; controller scenario 10 seconds using a monotonic clock. PHP execution/input settings are not wall-clock I/O guarantees. Shutdown TERM/KILL and reap observation are bounded in userspace, subject to normal kernel liveness.

## Recovery

`php bin/recover.php CANONICAL_PRIVATE_ROOT --json` acquires the exclusive writer/maintenance lock. It removes only owned, single-link regular files matching stage names or unreferenced digest object names. Referenced objects, symlinks, directories, foreign names, parser temporaries and caller paths are preserved. It does not repair missing/corrupt referenced objects or delete committed metadata. A repeat on the same clean namespace reports zeros. See the bilingual recovery guides.

The local-filesystem invariants assume cooperation and ordinary process crashes. No cross-filesystem atomicity, hostile same-UID defense, malware filtering, authentication, expiry, batch deletion or power-cut qualification is included.

## Parser scope and failure evidence

The canonical format is one labels JSON field and 1–4 repeated `files[]` parts. A real 17-part request (4 files, 12 duplicate labels, then a fifth file) previously returned 201 under automatic parsing; explicit parsing now rejects it with 422 and publishes no rows. Duplicate labels exceed the single input-variable limit. A depth 70 file name raises a parser warning and is rejected before publication. Canonical fifth-file, oversized-final-file, exact 0 / 2 MiB and label UTF8 boundaries have real loopback HTTP tests.

PHP still owns raw multipart interpretation, field-name normalization and malformed-header behavior. Within the admitted parser limits, explicit indexed names or duplicate raw file keys can normalize to a parsed flat list; the API does not reconstruct raw parts or claim rejection of every noncanonical raw spelling. Clients must use the canonical representation. The bounded accepted batch is the fully validated PHP-normalized file list, not a general strict multipart-wire conformance claim. See [PHP documentation](https://www.php.net/manual/en/function.request-parse-body.php) and the [PHP 8.4.27 parser source](https://github.com/php/php-src/blob/php-8.4.27/main/rfc1867.c).

Installed complete objects are retained on installation/SQL errors until explicit recovery determines references under writer.lock. No catch of COMMIT failure deletes installed objects: its outcome must not be guessed. A trigger-induced SQL failure test proves no batch/ref publication, complete orphan retention, and subsequent GC that preserves prior refs.