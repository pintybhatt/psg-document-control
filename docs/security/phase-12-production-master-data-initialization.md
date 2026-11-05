# Production XPPL master-data initialization — Step 24I-12

This runbook describes a future, separately authorized operation in the reviewed Production deployment. Implementing this command and approving the baseline **do not authorize execution against Production or business go-live**. No operational database was contacted, backup taken or restore performed while implementing it. Synthetic test artifacts are never operational evidence.

## 1. Product Owner approval and prerequisites

The Product Owner has approved `database/seeders/xppl-master-data.json` as the initial Production baseline, including provisional CAT codes, proposed mappings and untranslated Lao fields. Its SHA-256 at implementation is:

```text
b9e56b5c7831064bc1076aee1e8c825359be2ada27b16207d75f532502a13959
```

The baseline contains 19 departments, 39 categories, 19 document types, 3 registers and 24 type/register mappings, plus the XPPL company/site. Target IDs are resolved by business codes; source IDs are provenance only. Preserve the approved JSON unchanged. Provisional labels remain provisional after import.

Before any Production use, obtain a restricted change record explicitly authorizing the initialization window, naming the executing administrator, and containing Product Owner approval for the exact source SHA-256, target identity and preview plan SHA-256. Baseline approval alone does not approve a plan against a particular Production state. Retain the approval reference and command output in that record.

Prerequisites: the reviewed schema has already been deployed through its separately authorized process; an existing active global System Administrator is available (the internal role code is `SECURITY_ADMINISTRATOR`); a DBA owns the backup and isolated restore test. This command never creates users, roles or permissions. Coordinate a maintenance window and pause writes, especially organization/master-data edits, from final preview and backup through post-import validation.

## 2. Independently verify the exact Production target

Run only from the reviewed Production application runtime. Never repoint this development checkout, DEV or UAT at Production. Independently obtain the TCP hostname, port, database, MySQL server UUID and authenticated account from the approved DBA/infrastructure inventory. Do not trust whichever database a shell happens to reach as the source of the expected pins.

The separate configuration file is `config/xppl_prd_import.php`. Its variables have no `DB_*` or UAT fallback. `docker/prd.app.env.example` leaves enablement false and every identity pin unset. During the separately authorized window only, supply all independently verified values through the approved private configuration process:

```dotenv
XPPL_PRD_IMPORT_ENABLED=true
XPPL_PRD_IMPORT_HOST=<independently-verified-Production-TCP-host>
XPPL_PRD_IMPORT_PORT=<independently-verified-Production-port>
XPPL_PRD_IMPORT_DATABASE=dcms_prd
XPPL_PRD_IMPORT_SERVER_UUID=<independently-verified-Production-server-UUID>
XPPL_PRD_IMPORT_ACCOUNT=<authenticated-account@host-suffix>
```

Keep `APP_ENV=production` and `XPPL_UAT_IMPORT_ENABLED=false`. Do not change credentials, infrastructure or target pins to make a failed guard pass. The normal application writer connection must match every pin. The guard queries `DATABASE()`, `@@server_uuid`, `VERSION()` and `CURRENT_USER()` on that connection. It requires MySQL 8.4, database `dcms_prd` and InnoDB for every base table. It rejects read/write splits, URL overrides, explicit sockets, `localhost` implicit sockets and multiple hosts. There is no automatic import in startup, migrations, seeders or deployment.

Ensure the runtime's effective configuration reflects this temporary authorized configuration using the established configuration-cache process; merely editing an env file does not update cached configuration. This runbook does not authorize a deployment or server change. Existing Production preflight remains compatible with the disabled template; it does not enable the importer or approve execution.

## 3. Capture the read-only preview

In the authorized Production runtime:

```bash
php artisan prd:import-xppl-master-data --no-interaction
```

Without `--apply`, the command writes no database records, audits, backups or permissions. Capture the exact `Target JSON`, `Source SHA-256`, `Plan SHA-256`, `Administrator confirmation`, per-kind create/skip/conflict counts and the complete provisional issue table. Confirm all target fields against the independent inventory and the source hash against the approved baseline. No generic yes/force flag bypasses approval.

Any conflict blocks the entire import. The command never overwrites existing names, codes, status, category relationships or extra mappings. It can insert a missing type/register mapping without updating existing type metadata. The plan hash binds the target identity, source bytes, existing IDs and observed master values/mappings. Target/source changes require another preview, approval and matching backup manifest. Resolve conflicts through a separate reviewed process; do not edit the approved JSON or business records as an import workaround.

## 4. Take a real backup and verify an isolated restore

The DBA must take a fresh, complete backup of the verified Production database after the final preview and while writes are paused. Verify successful backup completion, nonzero content and SHA-256 integrity. Use the established private backup process with securely provisioned credentials. Never put credentials in arguments, Git or this runbook.

Restore that actual artifact into a separate, disposable MySQL 8.4 instance isolated from operational databases. Never restore-test against DEV, UAT or Production. Verify schema, constraints, row counts, representative documents and file references, existing audits, users/roles and numbering configuration/counters/history. Record the artifact digest, isolated restore target, start/completion times, checks, outcomes and responsible verifier in a restricted external restore-test record. Follow the existing file-backup process if needed to recover referenced private files.

Only after a successful completed restore test, the executing active global System Administrator must attest to it in a private manifest. Both backup and manifest must be readable, existing absolute regular files outside the application's `public/` directory, with owner-only permissions (normally `0600`). Symlink file paths are rejected. Keep their private containing directory restricted and the artifacts unchanged through execution.

Manifest shape (placeholders are instructions, **not evidence**):

```json
{
  "target": {
    "environment": "production",
    "driver": "mysql",
    "host": "<exact-preview-host>",
    "port": "<exact-preview-port-as-string>",
    "database": "dcms_prd",
    "server_uuid": "<exact-preview-server-UUID>",
    "account": "<exact-preview-authenticated-account>"
  },
  "plan_sha256": "<approved-preview-plan-SHA256>",
  "backup_sha256": "<actual-backup-file-SHA256>",
  "created_at": "<absolute-ISO8601-backup-completion-with-timezone>",
  "verified_at": "<absolute-ISO8601-restore-test-completion-with-timezone>",
  "restore_verified": true,
  "verified_by": 123,
  "verification_reference": "<restricted-record-of-the-real-completed-restore-test>"
}
```

Replace `verified_by` with the executing administrator's integer Production user ID. `target` must equal the complete preview identity, including string-valued port. The backup must be less than 24 hours old. Restore verification must follow backup completion and neither timestamp may be in the future. A changed plan requires a matching verified receipt; an expired backup requires a new backup and restore test.

Production reuses the established UAT artifact-only verification method unchanged. That method reads local files; it does not invoke UAT identity verification, configuration or a database connection. It validates private paths/permissions, artifact digest, exact target, plan, administrator, freshness and completed restore attestation. It cannot independently prove that an external restore occurred: a truthful, reviewable attestation tied to genuine external evidence is mandatory. A checksum, boolean, placeholder, fabricated receipt or synthetic fixture is never a restore test.

## 5. Approve the hashes and administrator confirmation

Retain Product Owner approval for the exact preview plan and explicit provisional use of the exact source hash, with a nonempty approval reference. The approved current baseline has provisional issues, so `--approve-provisional` is required. A future separately reviewed source with no provisional issues would require plan approval but no provisional override.

The executing administrator must confirm the entire preview phrase exactly:

```text
IMPORT XPPL dcms_prd ON <verified-server-UUID> PLAN <approved-plan-SHA256>
```

The phrase binds both target and plan. `--no-interaction` supplies no implicit consent. Interactive execution without `--confirm` asks for the exact phrase; any other answer is refused. An inactive user, inactive assignment, scoped administrator or unprivileged user cannot authorize apply.

## 6. Controlled execution

Only within the explicitly authorized window, after approval and genuine backup/restore verification:

```bash
php artisan prd:import-xppl-master-data --apply --no-interaction \
  --approve='<APPROVED_PLAN_SHA256>' \
  --approve-provisional='<APPROVED_SOURCE_SHA256>' \
  --approval-reference='<PRODUCT_OWNER_CHANGE_REFERENCE>' \
  --actor='<EXISTING_ACTIVE_GLOBAL_SYSTEM_ADMINISTRATOR_ID>' \
  --backup='/private/approved-backup.sql' \
  --backup-manifest='/private/approved-backup.json' \
  --confirm='<EXACT_ADMINISTRATOR_CONFIRMATION_FROM_PREVIEW>'
```

The command reuses `ImportXpplMasterData`, `AccessControl` and `RbacMutation`. Under the existing RBAC mutation lock and database transaction, it rechecks target identity, current administrator authority, source hash, backup evidence and the locked plan before inserting. It verifies the resulting plan before commit. An operation, audit or post-import verification failure rolls back all import changes. Existing records, documents, audits, users/roles and numbering history are preserved. Successful inserts append the existing organization/master-data audits.

## 7. Post-import validation

While enablement remains temporarily active, rerun the read-only preview:

```bash
php artisan prd:import-xppl-master-data --no-interaction
```

Expect zero creates, zero conflicts and all approved records/mappings skipped. Retain apply output and the new preview in the restricted change record. Compare XPPL bilingual metadata, departments, categories, types, registers and all 24 mappings with the approved source. Compare pre-existing rows, document/file counts, users/roles, existing audit entries and numbering configuration/counters/history with the change record and verified backup. New creation/mapping audits must identify the executing administrator. No numbering configuration, number issuance, workflow activation or business go-live is included.

A repeat execution with newly approved current hashes and matching fresh backup evidence is idempotent; unchanged existing rows and audits are skipped. Old pre-import plan approvals cannot authorize a different post-import plan.

## 8. Immediately disable import enablement

After validation, or immediately on refusal/failure, set `XPPL_PRD_IMPORT_ENABLED=false` through the approved private configuration process and ensure the running application's effective cached configuration is disabled. Retain confirmation in the change record. A subsequent command invocation must refuse with `Production import is disabled`. Do not leave enablement active for later deployments; the committed template remains false with unset identity pins.

## 9. Recovery and failure handling

On failure, capture the error, disable import enablement, preserve artifacts/output and verify no partial master-data changes remain. Do not retry with weaker identity pins, altered approvals, false restore evidence or a modified source. Re-preview and obtain new plan approval and matching backup/restore evidence after any relevant state change.

If a connection is lost during commit or the outcome is uncertain, do not assume rollback based solely on the CLI error. Have the DBA verify actual state and audit entries before any retry. Use a separately authorized read-only preview when needed. A missing/partial import should be investigated, not repaired by deleting records.

Restoring Production is a separate DBA-led incident/change operation requiring explicit approval and a verified recovery plan covering any writes since backup and associated private files. This command never performs a restore, deletes data or reverses numbering history. Successful initialization does not constitute acceptance of business go-live.

## Automated verification boundary

Focused tests run with `APP_ENV=testing`, SQLite `:memory:` and synthetic private restore fixtures. The Production test suite asserts that boundary before creating fixtures. Production guard tests mock the MySQL connection boundary to exercise version, identity and engine checks without opening any MySQL connection. SQLite/file identities cannot be used under `APP_ENV=production`; MySQL is refused under `APP_ENV=testing` by this command even if an isolated-MySQL test constant is defined. The existing UAT importer and its isolated-MySQL behavior remain unchanged.

These tests prove guarded command behavior and rollback in isolation. They do not constitute a live MySQL import rehearsal, Production backup or completed restore-test evidence.
