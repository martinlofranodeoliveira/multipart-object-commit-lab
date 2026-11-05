# Changelog

## 0.1.0 — Unreleased

Development version with an explicit local application contract.

- Real PHP-FPM/nginx multipart admission with private loopback/process namespaces.
- Complete-batch validation and SHA-256 admission before publication.
- Digest-addressed immutable objects and all-or-none SQLite batch associations.
- Cooperating publication/recovery locking, conservative prospective capacity checks and snapshot reads.
- Explicit orphan/stage recovery excluding referenced objects, symlinks and parser temporaries.
- Fixed precommit/postcommit crash barriers and pre-lock concurrent-upload rendezvous.
- Asserting offline demo and independent byte, digest, inventory and SQL regression oracles.
- Bilingual API/recovery documentation and actual linked SQLite 3.54.1 qualification with verified public official source pins.

No release, deployment, benchmark or production-readiness claim is made.

### Admission and failure hardening

- Explicit PHP 8.4 multipart parsing with automatic POST reading disabled; parser limit exceptions/warnings reject before publication, including the real 17-part silent-truncation regression.
- Native self-crash barriers avoid signalling unowned FPM worker PIDs.
- Failed SQL publication retains installed complete objects for reference-aware explicit GC; reads verify opened-file SHA-256, including same-size corruption.
- Docker context excludes checkout credentials and transient state; checkout does not persist credentials.
