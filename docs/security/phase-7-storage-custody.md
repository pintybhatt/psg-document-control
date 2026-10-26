# Phase 7 — secure document storage and physical custody

Implemented locally on `phase-7-secure-storage-custody`, starting at Phase 6 commit `3c4d02e992684f654412ea424930b31725c8853c`. No dependencies were added. The detailed Phase 7 request is the implementation specification; the separately referenced original handover was not available in the session.

## Architecture and authorization

All three existing registers use the same expandable files/custody panel and scoped API. Existing registration, numbering, membership, confidentiality, clearance, restricted allowlist and global `SECURITY_ADMINISTRATOR` behavior remain authoritative. Attachments use the actual parent record's security profile; client-supplied profile IDs, paths and hashes are never used.

Metadata, preview and download are separate decisions through `DocumentAccess`. The metadata endpoint supplies document-specific preview/download capabilities because workspace permission lists intentionally exclude content permissions. Each content endpoint checks authorization again before resolving the requested file version. Cross-parent, cross-site, cross-company and restricted requests cannot discover file metadata through retrieval endpoints. Access failures are recorded in immutable `storage_events` outside failed mutation transactions; the existing document security audit also records policy decisions and privileged access. Anonymous endpoint denials are logged before authentication.

Three new database-managed permissions are available: `documents.attachments.manage`, `documents.custody.manage` and `storage-locations.manage`. Existing content permissions remain `documents.content.preview` and `documents.attachments.download`. Initialize the catalog with `php artisan rbac:initialize --no-interaction`, then configure operational roles and exact memberships through Administration. Initialization does not automatically grant these capabilities to operational roles. No new administrator role or approval workflow was introduced.

## Files and controlled revisions

The `documents` local disk is fixed outside `public/`, under `storage/app/document-vault`, with private visibility, no storage URL and no temporary public serving. Do not symlink or expose this directory through a web server. Web document roots must point to `public/`. Back up this directory, not the public disk. Storage objects use server-generated UUIDs; original filenames are metadata only. Every file version has SHA-256, size, content type, actor, upload time and a required upload/replacement reason. Ordinary screens omit paths and hashes.

Default limit: 25 MiB, configurable with `DCMS_MAX_UPLOAD_KB`. Set PHP `upload_max_filesize`, `post_max_size` (including multipart overhead), reverse-proxy request limits and request timeouts consistently. Image dimensions are limited to 40 million pixels. Accepted extensions are listed in `config/document_storage.php`. Formats have content checks in addition to extension/filename validation:

- PDF: signature and detected MIME; authorized embedded browser preview.
- JPG/JPEG, PNG, WebP: raster signature/dimensions; image preview. TIFF is download-only.
- DOCX/XLSX: expected Office ZIP parts, bounded archive size/entry count, rejection of VBA projects and embedded payloads. DOC/XLS: legacy compound-file signatures/MIME. Office files are download-only.
- DWG: AutoCAD signature; DXF: binary or structured ASCII signature. Both are download-only; no CAD renderer or conversion service is installed.
- Executable extensions, active web formats including SVG/HTML, macro-enabled Office extensions, path separators, traversal sequences, control/bidirectional formatting characters and unsafe filename characters are rejected.

Validation is not an antivirus engine. Legacy Office documents and other permitted documents can contain unsafe content; apply the organization's malware scanning and workstation policies, using open-source tooling if required. No external or paid preview service is mandatory.

An attachment identity has append-only file versions, separate from controlled revision numbers. Replacement requires the expected current file version and a reason. All prior versions remain retrievable with the same content authorization. Correspondence closure does not delete file history.

Controlled uploads require an exact revision belonging to the parent. Additions and replacements are allowed only while that revision is an editable draft. They lock the parent document, serializing against Phase 6 release. Database guards independently reject file association changes after release. To correct a released file, prepare a new controlled revision, upload its files explicitly and release it with existing Phase 6 authority. Files are not silently inherited into a new revision. A released revision may legitimately have no electronic attachment, allowing physical-only documents. Physical copy/custody events can continue for released or archived revisions without changing released metadata or files.

Preview and download verify size and hash before returning bytes. Missing/corrupt objects return a localized unavailable response without serving content. Responses use private/no-store caching, appropriate MIME/disposition, `nosniff`, and a restrictive content policy. Attachment PDF previews reuse the embedded dialog with its direct blob-download button disabled; download uses its own authorized endpoint. The iframe follows the existing native PDF-viewer interaction without an HTML `sandbox` attribute, which can disable built-in PDF viewers ([MDN iframe reference](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/iframe)). Only verified PDF content is passed to this viewer; HTML/SVG previews are prohibited. Anyone allowed to preview receives content bytes and can potentially save or capture them using browser tools; preview permission is not digital rights management.

## Physical records

Locations are immutable company/site paths: Room → Cabinet → Shelf → Box, with optional lower levels and required parents. Create a new location and transfer the copy to correct placement. The location editor is visible only with its Administration-managed permission.

Each original/copy has a site-unique physical identifier and an exact parent/revision. A copy has one current state: stored, checked out or missing. A checked-out state requires one active custodian from the exact organizational unit, a checkout date and an expected return date. Past expected return dates are overdue, calculated from the application calendar date. Checkout dates cannot be future dates. Returning clears the active custodian and dates. Transfers can move a stored copy or hand a checked-out copy to a new custodian. Its displayed storage location while checked out is the recorded storage/home location; the status and custodian identify current possession.

Changes use parent/copy locks and an expected custody version. Inventory checks record verified/discrepancy observations without silently overwriting custody. Corrections require a reason and reference to an existing history event for that copy. Immutable snapshots preserve before/after state, location labels and custodian names. Parent, revision and copy identity are not reassigned by custody corrections. History and identities cannot be deleted or rewritten through ordinary updates or SQL replacement. No electronic file is required.

## Backup, verification and restoration

Application commands:

```sh
php artisan dcms:files-verify --no-interaction
php artisan dcms:files-backup /private/checkpoints/checkpoint-files.zip --no-interaction
php artisan dcms:files-restore /private/checkpoints/checkpoint-files.zip --no-interaction
```

Verification checks every database file reference for presence, size and SHA-256. It exits nonzero if any reference fails. Backup refuses existing archive names and public destinations, verifies all referenced objects and creates a private ZIP with a version/hash consistency manifest. Backup fails and removes its partial archive if a required object cannot be verified. The archive contains attachments, not a database backup.

Production operators must:

1. Put uploads and custody/document mutations into a maintenance/read-only window when taking a recovery checkpoint.
2. Take a database-consistent SQLite backup or MySQL dump/snapshot, the private file archive and the matching application/configuration version at that same checkpoint. Include the database audit history, authorization data and application encryption key in protected recovery material.
3. Encrypt and copy checkpoint material off-host, restrict access, set retention/recovery objectives and monitor both backup and verification exit codes. Archives are permission-restricted but are not encrypted by the application. Schedule verification with the operating environment; no automatic backup/retention service was added.
4. Test restoration to disposable database/file locations. Restore the matching database first, then run `dcms:files-restore` against its matching archive and run `dcms:files-verify` before reopening service.

Restore reads only generated object names referenced by the current database, validates archived sizes/hashes before writing, preserves valid existing files and ignores unrelated archive entries. It does not extract arbitrary paths or alter document/database records. Missing/corrupt objects may be repaired from the archive; verified existing versions are not replaced. Restore failures stop the command and may leave earlier objects successfully repaired; rerunning is safe after resolving the fault. A restore must occur in a maintenance window to prevent content access while objects are being repaired.

Database/filesystem operations cannot be one distributed transaction. New-upload files are removed on handled database/audit failures. A process crash or failed cleanup can leave an unreferenced object; do not automatically delete objects during recovery. Retention and orphan cleanup remain operator responsibilities. Successful access events record authorized delivery attempts, not proof that a client completed a download.

## Verification and local operation

Focused SQLite tests cover private upload, metadata/content separation, restricted and privileged access, cross-parent/site/company boundaries, traversal/prohibited files, supported raster/Office/drawing formats, replacement/stale requests, released-revision association immutability, storage/audit rollback, recovery and physical custody. Separate processes race checkout on SQLite and MySQL. SQL replacement tests protect immutable identities/history.

Use `php artisan test --compact tests/Feature/DocumentStorageTest.php tests/Feature/PhysicalCustodyTest.php tests/Feature/PhysicalCustodyConcurrencyTest.php` for focused SQLite verification. Frontend behavior is covered in `tests/Frontend/DocumentStorage.test.js` and the existing register/PDF tests.

Targeted native MySQL verification requires installed MySQL 8.4 server/client binaries; Docker Desktop is not required. Use only the fixed disposable directory below; never substitute an operational datadir:

```sh
mkdir -m 700 /tmp/dcms-phase7-mysql
mkdir -m 700 /tmp/dcms-phase7-mysql/data
mysqld --no-defaults --initialize-insecure --datadir=/tmp/dcms-phase7-mysql/data
mysqld --no-defaults --datadir=/tmp/dcms-phase7-mysql/data --socket=/tmp/dcms-phase7-mysql/mysql.sock --skip-networking --mysqlx=OFF --log-bin-trust-function-creators=ON --pid-file=/tmp/dcms-phase7-mysql/server.pid --log-error=/tmp/dcms-phase7-mysql/server.log --daemonize
DCMS_NATIVE_MYSQL_TESTS=1 php tests/prepare-native-mysql.php
DCMS_NATIVE_MYSQL_TESTS=1 vendor/bin/phpunit -c phpunit.storage-mysql.xml
mysqladmin --no-defaults --socket=/tmp/dcms-phase7-mysql/mysql.sock --user=root shutdown
```

The bootstrap checks opt-in, server UUID, 8.4 version, exact datadir/database and disabled networking before application boot. The test account has privileges on the disposable database only. Tests may reset that isolated database. Test fake files are isolated per process. Do not use the test server's insecure initialization or trigger-creator setting as a production deployment configuration. Existing guarded Compose verification remains unchanged.

Phase 7 schema changes are additive. Apply with `php artisan migrate --no-interaction` after backup and validation; never reset the everyday database. No Phase 8/9 work, push, merge, deployment or PR is part of this milestone.

## Final local results — 26 October 2026

- Full PHP regression: 670 executed, **663 passed, 0 failed, 7 skipped**, 3,751 assertions, **76.547 s**. The skips are the existing guarded MySQL-only numbering cases in the ordinary SQLite suite.
- Final targeted SQLite storage/custody checks: **31 passed, 0 failed, 0 skipped**, 206 assertions, **9.554 s**, including separate-process checkout contention and recovery using disposable fake storage.
- MySQL **8.4.11** targeted suite: **31 passed, 0 failed, 0 skipped**, 207 assertions, **28.826 s**. Includes custody concurrency and SQL insert/replace history guards. No full MySQL regression was run.
- Frontend regression: **220 passed, 0 failed, 0 skipped**, 20 files, **6.19 s**. Covers both languages, multipart upload, separate preview/download actions, custody dates, released files and access revocation.
- Production build: **passed, 582 ms**. Pint and Git whitespace checks passed.
- The normal development SQLite database was backed up consistently to `/tmp/dcms-phase7-pre-migration/database.sqlite` in a private directory before the three additive migrations and catalog initialization. A comparison against the backup verified all pre-existing rows unchanged. No accounts, role assignments, operational attachments or custody fixtures were created in that database. Test fixtures used separate databases/storage.
- Local stage checkpoints: `7ed2ddf` (7A), `50c4430` (7B), `92e5de7` (7C); the final 7D commit contains this handover and integrated UI/hardening. No push, merge, PR, deployment or Phase 8/9 implementation occurred.
