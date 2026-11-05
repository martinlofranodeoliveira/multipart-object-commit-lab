# Contributing

Contributions to Multipart Object Commit Lab should preserve the documented development 0.1.0 contract and small, original, dependency-light implementation. Author metadata uses **Lofrano Tecnologia**, without an email address.

## Local checks

Prepare the qualified unprivileged Linux runtime described in [docs/runtime.md](docs/runtime.md), then run:

```sh
php bin/qualify.php
php tests/run.php
php bin/demo.php --json
```

Tests must use real multipart parsing through the private nginx/FPM harness. Do not substitute a hand-built `$_FILES` success test for admission coverage. Keep synthetic fixtures only, and do not add screenshots, fabricated clients, performance numbers, deployment assertions or private client code.

For regression changes, add an independent oracle: exact bytes, known digest, direct SQL or directory inventory. Test invalid final files and five-file requests as complete batch failures, not merely an error message. Preserve the pre-lock location of the concurrent rendezvous and the single-lock staging/install/commit/collection discipline.

Security-sensitive changes should review symlink behavior, caller-path preservation, object/reference/staging byte accounting, PHP parser settings and bounded process cleanup. Never signal a process group after wait ownership ends. Avoid unbounded response reads or diagnostics. Do not add runtime APIs, telemetry, tracking, secrets, authentication claims or release/deployment workflows.

Use four spaces for PHP, strict types, argument-array subprocess calls and explicit checked I/O. Document any new resource threshold and distinguish observed accounting from a physical quota. Keep English and Portuguese API/recovery explanations aligned. Contract-breaking changes need a versioned explanation and tests.

General questions can use the [official homepage](https://lofranotecnologia.com.br). For vulnerabilities, follow [SECURITY.md](SECURITY.md) rather than opening a public issue containing exploit details.
