# Security policy

Multipart Object Commit Lab is development version 0.1.0, a local educational application with a narrow documented contract. It does not provide authentication, malware scanning, origin authentication, hard disk/RSS quotas or a production service guarantee.

## Private reports

Use GitHub private vulnerability reporting:

https://github.com/martinlofranodeoliveira/multipart-object-commit-lab/security/advisories/new

Do not disclose sensitive exploit details, personal data, credentials or private client material in a public issue. Include a synthetic reproducer, affected revision/version, qualification output, relevant OS/PHP/nginx/SQLite versions and the violated documented invariant where possible. No response-time SLA is promised.

General contact is through the [official homepage](https://lofranotecnologia.com.br); no other contact details are specified by this project.

## Relevant boundaries

- Only loopback HTTP and a private FastCGI socket are intended.
- Private namespaces require one non-hostile UID and cooperating advisory-lock users.
- Labels and uploaded content are untrusted. Labels must be escaped by downstream consumers. Content is returned only as opaque attachments.
- A digest identifies bytes, not uploader identity, authorization, provenance or malware safety.
- Referenced objects must never be removed by recovery. Parser-owned active temporary files are excluded from GC.
- PHP parser/body settings do not establish a hard RSS bound. Logical accounting is not a physical quota.
- Local process-crash tests do not qualify power-loss durability, hostile same-UID races, filesystem corruption or unkillable kernel I/O.
- Actual linked SQLite qualification is mandatory; do not rely on a base-image tag or substitute an unverified archive checksum.

There is no formal support window or certification claim. Review fixes and the contract before using this laboratory as design input for another system.
