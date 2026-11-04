# XPPL UAT master-data import

This is an operator runbook for Product Owner review. No live UAT connection or import was performed while preparing it. Do not point the DEV checkout at UAT. Run only from the reviewed UAT deployment during an agreed maintenance window.

## Source and scope

`database/seeders/xppl-master-data.json` remains the single source. It contains 19 departments, 39 categories, 19 types, 3 registers and 24 type/register mappings. Company/site bilingual metadata was added to that same file from the existing DEV XPPL organization through a read-only query. The old top-level company/site IDs are DEV provenance and are never used to identify target records.

The file is still a **proposal**. There are 77 `[DEV-UNTRANSLATED]` Lao labels, two English-only company/site Lao names, 39 provisional CAT codes, and proposed category/register applicability. The importer lists every issue. Nothing here approves a translation or official code.

Only missing XPPL organization/master records, a required site authorization scope, missing type/register mappings, and new target-side creation/mapping audits are written. Existing records are never updated, renumbered, deleted or detached. Unrelated companies/sites and all users, credentials, roles, permissions, memberships, documents, files, numbering settings/history/counters and existing audits are preserved. A missing mapping can be added without touching the existing type's metadata.

The existing `dev:prepare-xppl-master-data` command retains its local-only, exact SQLite-path, ID-2, approval and backup protections. Do not use it for UAT.

## Prerequisites and independent target verification

1. Review this commit, run the normal migrations separately through the deployment process, and confirm the application schema exists. This command does not migrate or seed users/roles. An existing active global Security Administrator is required even when there is no XPPL organization yet.
2. Obtain the UAT host, port, database, MySQL server UUID and authenticated account (`CURRENT_USER()`, including its host suffix) from independent DBA/infrastructure inventory. Do not derive the expected identity by blindly copying whichever database the checkout currently reaches.
3. In the UAT deployment configuration, set `APP_ENV=uat` and the following reviewed values. Never put passwords in command arguments or committed files:

```dotenv
XPPL_UAT_IMPORT_ENABLED=true
XPPL_UAT_IMPORT_HOST=<verified-UAT-host>
XPPL_UAT_IMPORT_PORT=3306
XPPL_UAT_IMPORT_DATABASE=<verified-UAT-database>
XPPL_UAT_IMPORT_SERVER_UUID=<verified-server-UUID>
XPPL_UAT_IMPORT_ACCOUNT=<authenticated-user@host>
```

Use the existing UAT database connection and normal config-cache/deployment process. The importer checks the actual writer connection's database, server UUID, version and account, and compares host/port to the independent pins. It requires MySQL 8.4 and InnoDB tables. Socket and read/write split configurations are refused. Production/local/staging are refused; `--env` is not a substitute for correct deployment configuration. It is disabled by default.

SQLite is supported only for automated in-memory tests. MySQL tests require the existing identity-checked isolated test bootstrap; no environment switch or SQLite fallback enables a live import.

## Preview and Product Owner approval

```bash
php artisan uat:import-xppl-master-data --no-interaction
```

The default operation is read-only, including audits, permissions, backups and scope creation. Capture its `Target JSON`, source SHA-256, plan SHA-256, confirmation phrase, per-kind summary and provisional issue table in the restricted change record. Any conflict blocks apply; do not bypass it.

The plan hash binds the source, target identity, target IDs and the existing master values/mappings observed by preview. Obtain Product Owner approval for that exact plan and, if any issues remain, explicit approval for **provisional UAT use** of the exact source hash. Record the approval reference. Approval does not magically turn placeholder English into verified Lao or CAT codes into official codes.

For reviewed corrections, edit the existing JSON through version control, retaining stable business codes and fixing any category references consistently. Rerun preview and obtain new hashes/approval. Before first import this can introduce reviewed Lao names or approved category codes. After records exist, a differing name/code is a conflict, not an overwrite instruction. Resolve legitimate label changes separately through existing audited administration, update the reviewed source to match, and preview again. Code changes with operational references require a separate approved migration plan; this importer never rewrites history.

## Verify a backup before apply

A DBA must take a fresh complete backup of the **verified UAT database** and restore-test it on a separate disposable MySQL 8.4 instance. The importer never creates, overwrites or restores a database. Pause master-data edits from preview through import; coordinate any other UAT activity with the backup owner.

Example backup command for the DBA, using an already provisioned secure MySQL login path and a **new** private output file:

```bash
umask 077
set -C
mysqldump --login-path=XPPL_UAT_BACKUP --single-transaction --routines --triggers --events --hex-blob --set-gtid-purged=OFF '<UAT_DATABASE>' > /secure/new-uat-before-xppl.sql
sha256sum /secure/new-uat-before-xppl.sql
```

Check dump exit status/completeness, restore it on the disposable instance, and verify schema, row counts, constraints, representative documents and numbering history. Never test a restore against UAT. A checksum alone is not a restore test.

After that verification, create a private JSON manifest (mode `0600`) beside the backup. Replace every placeholder; `target` is the exact preview identity, `verified_by` is an integer target administrator ID, and timestamps are absolute ISO-8601 UTC values:

```json
{
  "target": {
    "environment": "uat",
    "driver": "mysql",
    "host": "<verified-UAT-host>",
    "port": "3306",
    "database": "<verified-UAT-database>",
    "server_uuid": "<verified-server-UUID>",
    "account": "<authenticated-user@host>"
  },
  "plan_sha256": "<approved-plan-SHA256>",
  "backup_sha256": "<backup-file-SHA256>",
  "created_at": "<ISO8601-backup-completion-time>",
  "verified_at": "<ISO8601-restore-test-completion-time>",
  "restore_verified": true,
  "verified_by": 123,
  "verification_reference": "<restricted-DBA-restore-test-record>"
}
```

The importer verifies readable private nonempty files outside `public/`, SHA-256, target identity, plan hash, administrator identity, a completed restore-test attestation and backup age under 24 hours. It relies on the administrator's truthful external restore-test attestation; it does not itself execute or prove a restore. Do not fabricate the receipt. Keep backups/receipts outside the repository.

## Apply

Only after approval and backup verification, substitute values captured from preview:

```bash
php artisan uat:import-xppl-master-data --apply --no-interaction \
  --approve='<APPROVED_PLAN_SHA256>' \
  --approve-provisional='<APPROVED_SOURCE_SHA256>' \
  --approval-reference='<PO_APPROVAL_REFERENCE>' \
  --actor='<EXISTING_UAT_SECURITY_ADMIN_ID>' \
  --backup='/secure/new-uat-before-xppl.sql' \
  --backup-manifest='/secure/new-uat-before-xppl.json' \
  --confirm='<EXACT_ADMINISTRATOR_CONFIRMATION_FROM_PREVIEW>'
```

Omit `--approve-provisional` only if preview reports zero provisional issues. `--no-interaction` never supplies confirmation implicitly. Without `--confirm`, an interactive operator must type the full target/plan phrase. A generic yes/force flag is not supported.

The command rechecks authority, target and the plan under the existing RBAC mutation lock and a database transaction before inserting. It verifies the final plan before commit. Any mismatch, duplicate, exception or audit failure rolls back the whole import. It never repairs conflicts by overwriting existing values.

## Post-import verification and retry

```bash
php artisan uat:import-xppl-master-data --no-interaction
```

Expect zero creates, zero conflicts, and all approved source records/mappings skipped. Record the apply summary and new preview. Review XPPL department/category/type/register lists in both languages, type/category/register applicability, existing organization records, document/file counts and numbering identities/settings against the backup/change record. The command does not activate XPPL numbering, configure approval workflows, or issue any numbers.

If apply fails, review the reported cause and verify no partial records remain. Re-preview after any target/source change and obtain a new plan approval and matching verified backup receipt. Repeating an unchanged successful import is a no-op, including creation audits. Never restore or delete UAT records automatically as a retry strategy. Disable `XPPL_UAT_IMPORT_ENABLED` after the approved operation through the usual configuration process.

## Automated verification

```bash
php artisan test --compact tests/Feature/ImportUatXpplMasterDataTest.php tests/Feature/PrepareXpplMasterDataTest.php
DCMS_MYSQL_TESTING=1 vendor/bin/phpunit -c phpunit.mysql.xml tests/Feature/ImportUatXpplMasterDataTest.php
```

The second command must pass the existing isolated MySQL identity checks. If that server is unavailable, report the blocker; do not redirect tests to DEV/UAT or weaken the bootstrap.
