# GitHub Actions UAT deployment

## Normal deployment

1. Merge the approved PR into `main`.
2. Open GitHub Actions → **Deploy DCMS UAT** (the default `main` ref).
3. Enter `DEPLOY_UAT` in **confirmation**.
4. Run the workflow.

Image selection, local backups, migrations, application replacement and health checks are automatic. No image digest, release version or release-run ID is entered. No deployment rebuilds an image. Production and NetBox are outside this workflow.

## Automatic image publication and selection

The existing main-push **DCMS CI** builds one Linux/amd64 image and smoke-tests that exact image. It retains the archive with source/run/attempt identity. Frontend, PHP/SQLite, Fast CI, all four MySQL shards, Image/smoke and Full release validation must succeed. Only then does CI call the reusable publisher in `release-image.yml`; it never invokes a second CI suite. PRs, feature branches and explicitly supplied alternate source commits cannot enter automatic publication.

The publisher uses the existing `release-image` environment and `ENABLE_IMAGE_PUBLISHING=true` gate. It checks the archive checksum, source label and image ID, publishes to the existing private GHCR package, resolves that tag's immutable registry digest, pulls the digest and compares its image ID to the tested image. Versions are generated as `v0.0.RUN_ID-rc.ATTEMPT`; these are container tags, not Git tags. The release manifest records the digest, exact source, tested image ID, CI run/attempt and complete migration-file hashes. Its baseline equals source; target migration compatibility is still checked against the deployed inventory by the server. Archives are retained for three days and manifests for thirty days, subject to repository retention policy.

**Deploy DCMS UAT** requires the dispatch SHA to equal current protected `main`. It uses read-only GitHub APIs to locate that exact commit's newest main-push CI run. It waits up to thirty minutes for validation/publication, then verifies the successful run, each required job and smoke step, current attempt and nonexpired source-matching manifest. It checks main again before writing the existing deployment request. Failed/skipped checks, missing shards, changed main, racing reruns or missing/expired evidence stop deployment. It never substitutes another source, falls back to an older successful run after a newer failure, dispatches a publisher or rebuilds.

The existing dedicated runner on 192.168.0.227, UAT environment, deployment concurrency lock, pinned SSH host key and restricted `dcms-deploy` command on 192.168.0.228 remain. The only request fields sent to the fixed entry point are the verified immutable image, source commit and deployment run/attempt. Only the publishing job receives `packages: write`; deployment receives `contents: read` and `actions: read`.

## Server safeguards and configuration preservation

The fixed SSH/sudo entry point remains `docker/deploy-uat-manual.py`, using `Deployment` in `docker/deploy-uat.py`. It checks the healthy existing database, current app image, persistent mounts, source labels and immutable migration baseline before maintenance. It enters maintenance, stops only the app, and makes a new root-private backup under `/srv/dcms-uat/backups/gha-RUN-ATTEMPT` containing:

- `database.sql`, including triggers, routines and events;
- `documents.tar`, containing document and private-storage volumes;
- `configuration.tar`, containing protected configuration and recovery credentials;
- `SHA256SUMS` for all three archives.

The validator checks checksums, nonempty files, SQL completion, archive safety and required configuration members before migration. This verifies backup integrity; it does not perform an isolated restore rehearsal. Migrations use the existing dedicated `migration.env` credentials. Only app is replaced with `--no-deps --no-build --pull never`. Database identity, start time, mounts and health must remain unchanged. Checks include Apache, Docker app health, loopback and HTTPS `/up`, and public-storage denial. Failure retains maintenance/recovery state and root-only history; no automatic schema rollback, reset, seed, import or volume removal occurs.

The checkout happens **on the runner**. The workflow does not copy a release bundle, run Git on UAT or synchronize configuration. Runtime continues using the root-managed files in `/srv/dcms-uat/release` and protected `/srv/dcms-uat/config`. Only the `DCMS_IMAGE` line in the existing `compose.env` changes after success. Existing `app.env`, APP_KEY, credentials, master-data importer settings, Compose settings and the bind-mounted Apache file are preserved.

The Phase 11 handoff records app **2 CPUs / 2 GB**, Apache **MaxRequestWorkers 8**, MySQL **1.5 GB / 0.5 CPU**, `log_bin_trust_function_creators=ON` and additional MySQL tuning. These are server-managed values, not values verified by this local change. Do not replace them with repository bootstrap defaults. Normal app deployments do not recreate MySQL to apply settings. MySQL buffer/resource changes and persistence of the trigger setting require separate controlled server maintenance.

**Preflight prerequisite:** the repository bootstrap `docker/preflight.py` still pins the older 640/768 MiB and 0.5-CPU profile. Do not install that bootstrap helper or old Compose/Apache templates over the tuned deployment. The enrolled server preflight must already validate the approved live profile; otherwise it will safely refuse deployment before maintenance. Review/align that installed policy during separately approved server maintenance. No configuration synchronization mechanism is added here.

## One-time activation prerequisites

These are reviewed setup requirements, not extra routine inputs. Nothing in Phase 11.4 changes GitHub settings or contacts UAT.

- Keep protected-main review and required CI checks. The existing private GHCR package must grant this repository publication access; host pull credentials remain read-only and server-managed. First-package bootstrap is not automatic.
- Configure the existing `release-image` environment for main-only automatic publication and `ENABLE_IMAGE_PUBLISHING=true`. Any configured required reviewer remains effective; it must be reconciled with the four-step routine by the owner. This workflow does not bypass environment approval or organization policy.
- Retain the `uat` environment's `ENABLE_UAT_DEPLOYMENT=true`, `UAT_SSH_KEY`, `UAT_KNOWN_HOSTS` and `UAT_SSH_PORT`, plus runner labels `[self-hosted, Linux, X64, dcms-uat-runner]` and connectivity to UAT/GitHub. No new PAT or OIDC setup is needed.
- Verify root custody of installed runtime helpers, the accepted live preflight profile, registry credentials, protected configuration, deployment lock and sufficient backup space. The Python 3.10 checksum fix from `052aee5` must be present in installed `deploy-uat.py` and `uat-backup.py`; publishing a new app image does not update these host helpers.
- The optional managed-backup restore checker remains separate from normal deployment. Its remaining checksum call now reuses the Python 3.10-compatible stream helper. Install it only through the existing separately approved recovery-maintenance procedure if that path is supported on the target.

## Exceptional failures and recovery

If validation/publication is still pending after the bounded wait, inspect **DCMS CI** before dispatching again. If main advanced, dispatch from the new main after its checks pass. If publication failed or evidence expired, inspect the failure and rerun **all jobs** for the current main CI commit when approved. Full reruns use new attempt identities; a publisher-only retry cannot silently reuse a previous-attempt archive or checks. Existing artifact retention, package access and publishing gates still apply.

For a server failure, preserve `/srv/dcms-uat/config/recovery-required.json`, maintenance state, backups and root-only history. An administrator must inspect migration state and select a reviewed recovery procedure. Do not erase the gate just to retry, automatically roll back migrations, recreate MySQL, remove volumes or rerun master-data import. Local deployment backups remain retained; retention and restore rehearsals are separate administrator work.
