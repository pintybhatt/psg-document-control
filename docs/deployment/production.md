# Production configuration and deployment engine — Phases 12.2A–B

These phases prepare local configuration and the Production deployment/backup/recovery
engine. Nothing has been installed, initialized, published or deployed. The GitHub
workflow and one-time server bootstrap remain separate work; there is no `deploy-prd.yml` workflow.

| Setting | Production |
| --- | --- |
| Public URL | `https://dcms.phonesackgroup.com:9444` |
| Root / protected configuration | `/srv/dcms-prd` / `/srv/dcms-prd/config` |
| Compose project | `dcms-prd-xppl` |
| Compose files, in order | `compose.release.yaml`, `compose.prd.yaml` |
| Ingress | Host Nginx HTTPS 9444 → `127.0.0.1:18085` → Apache 8080 |
| Database / account | `dcms_prd` / `dcms_prd`, pinned release MySQL 8.4 |
| App RAM / RAM+swap / CPU | 2 GiB / 2 GiB / 1 CPU |
| MySQL RAM / RAM+swap / CPU | 1.5 GiB / 1.5 GiB / 0.5 CPU |

## Relationship to UAT and isolation

Production reuses the immutable PHP 8.5/Apache release image and pinned MySQL image.
It does not layer the UAT overlay or invoke UAT deployment entry points. The engine
imports the existing root-custody, image identity, health and release-evidence helpers
as libraries; their UAT deployment, backup and authentication entry points are never called. UAT remains project
`dcms-uat-xppl`, root `/srv/dcms-uat`, database/account `dcms_uat`, HTTPS 8444 and
loopback HTTP 18084. Its configurations, scripts and preflight profile are unchanged.

The new project owns four Docker-managed local named volumes:
`dcms-prd-xppl_database`, `dcms-prd-xppl_documents`, `dcms-prd-xppl_private` and
`dcms-prd-xppl_framework`. Their paths are managed by Docker, not host bind mounts
under `/srv`. The ingress/backend networks have the same Production project prefix;
the database connects only to the internal backend and publishes no port. No external
or shared volumes/networks are accepted. No volumes or database are created here.

Production requires independent APP_KEY, database user/root passwords and protected
configuration files. Do not copy UAT secrets, previous encryption keys or data.
`dcms_prd_session` is secure, encrypted, HTTP-only, host-only and SameSite=Lax;
`dcms_prd_` isolates cache keys. Database-backed sessions/cache use the Production
database. UAT master-data import is disabled. RBAC, audit and private document
authorization remain in the existing application.

The base image's `docker/apache.conf` is reused for its public document root and
storage/configuration denials. UAT's two-worker tuning is environment-specific;
`docker/apache-prd.conf` supplies four prefork workers with PHP 256 MiB/request.
Only that individual read-only file is bind-mounted. The app retains its read-only
root, dropped capabilities, no-new-privileges and bounded writable tmpfs. MySQL has
a 512 MiB buffer pool and 40-connection ceiling. These are initial bounds, not
capacity or workload acceptance evidence; verify them at the approved first start.

## Templates and local validation

`docker/prd.app.env.example` and `docker/prd.compose.env.example` contain no usable
secrets or image digest. Later approved setup must supply owner-only files (0600)
at the exact `/srv/dcms-prd/config/{compose.env,app.env,db-password,db-root-password}`
paths. Preflight also accepts the engine-owned candidate environment at
`/srv/dcms-prd/history/gha-RUN-ATTEMPT/compose.env`, with positive numeric run/attempt IDs;
its application/secret paths must still use the fixed Production configuration directory. The app database password must match `db-password`; the root password must
differ. Preflight rejects symlinks, alternate environment paths, mutable/unapproved
images, altered resource limits and storage/network/runtime overrides.

After separately approved provisioning, the configuration-only validation command is:

```bash
python3 -B docker/preflight.py --target production --prd-profile \
  --project dcms-prd-xppl --compose-env /srv/dcms-prd/config/compose.env \
  --manifest /srv/dcms-prd/releases/APPROVED-RELEASE/release-manifest.json
```

This renders only the explicit release + Production Compose files and does not
pull/start containers, migrate or create volumes. Do not use `--probe-local` during
this phase: it reads the local daemon/current stack and needs separate host approval.
No localhost HTTP session exception is permitted for Production. Preflight cannot
prove that independently generated credentials differ from unseen UAT secrets;
the setup operator must attest to their origin without exposing them.

The independent `docker/nginx-prd.conf.template` fixes the hostname, HTTPS 9444 and
loopback upstream 18085. Render **only** `DCMS_TLS_CERTIFICATE` and `DCMS_TLS_KEY`
with restricted `envsubst '${DCMS_TLS_CERTIFICATE} ${DCMS_TLS_KEY}'`; validate both
values as absolute paths containing only letters, digits, `/`, `_`, `.` and `-`.
Preserve Nginx `$host` and `$remote_addr`. It rejects other hosts and protected
storage paths, overwrites forwarding headers, and clears client-supplied forwarded
host/standard Forwarded/real-IP headers. Trust only the actual Production ingress
gateway IP observed during approved setup; preflight refuses wildcards and CIDRs.
No existing Nginx file is modified. The 27 MiB ingress body limit matches the release
Apache/PHP POST limit and 25 MiB application file limit.

Local regression command (temporary synthetic files; Compose rendering only):

```bash
python3 -B -m unittest discover -s tests -p 'test_*preflight.py' -v
git diff --check
```

Production tests render real Compose with temporary files, then virtualize the fixed
`/srv` file boundary for preflight negative cases. They never write `/srv`, contact
Docker Engine or create Production secrets. Nginx template assertions are static;
they do not replace an actual Nginx syntax/TLS/runtime check.

## Prerequisites for separately approved initial setup

- Confirm host ownership, change window, capacity and unused 9444/18085 ports. The
  two container ceilings total 3.5 GiB and 1.5 CPUs, before host services and UAT;
  these limits do not reserve resources or prove coexistence capacity.
- Approve DNS, certificate SAN/trust, TLS key custody/renewal, firewall access to
  9444 and outbound registry access. Keep 18085 loopback-only and MySQL unpublished.
- Prepare the protected Production root/config/release directories, independent
  credentials/APP_KEY and least-privilege GHCR pull access outside Git. Select the
  approved immutable image and matching source/migration manifest.
- Review the initial-bootstrap procedure, volume ownership (app UID/GID 33), MySQL
  startup, migration trigger privileges/definers and initial administrator setup.
  No administrator deployment input or database initialization is added here.
- Approve Production backup/restore and off-host retention, paired database/vault
  recovery, restore drill, rollback, migration review, monitoring and log rotation.
  UAT backup/deployment scripts are restricted to UAT and must not be pointed at PRD.
- Validate Apache/Nginx syntax on the approved host, certificate loading, effective
  resource limits, observed proxy IP, HTTPS login/cookies/redirects, confidential
  upload/download/RBAC/audit behavior and coexistence with existing services.

GitHub settings, deployment credentials/workflow and activation remain outside
Phases 12.2A–B. Copying templates or passing preflight does not authorize deployment.

## Routine deployment interface and trust boundary

`docker/prd-ssh-command.sh` accepts exactly `SSH_ORIGINAL_COMMAND=deploy-dcms-prd`
with no arguments. It calls a no-argument sudo rule for `/usr/local/sbin/dcms-prd-deploy`,
installed from `docker/prd-deploy-entrypoint.sh`. That wrapper clears the caller's
entire environment and runs `deploy-prd.py` with Python isolated mode. Install both
wrappers and the complete reviewed `docker` helper set root-owned and non-writable
by deployment/backup accounts; the engine also checks root custody of executables,
configuration and ancestor directories. SSH must disable PTY, forwarding, user RC,
agent forwarding and unrestricted commands. The dedicated Production key/account
must differ from UAT. No SSH/sudo enrollment is performed by this phase.

The future **Deploy DCMS PRD** workflow must run its reviewed deployment tooling from
`main`, validate its `confirm_deploy` input, resolve `ref` to a published release,
and send a bounded JSON request on stdin containing exactly:

```json
{
  "deployment_run_id": 789,
  "deployment_run_attempt": 1,
  "release_run_id": 123,
  "release_run_attempt": 1,
  "release_artifact_id": 456,
  "image": "ghcr.io/pintybhatt/psg-document-control@sha256:APPROVED_64_HEX_DIGEST",
  "source_commit": "APPROVED_FULL_40_HEX_COMMIT",
  "manifest_sha256": "APPROVED_64_HEX_MANIFEST_HASH"
}
```

These are machine-derived identities, not additional administrator workflow inputs.
No target, project, path, shell command, operation, credential or raw ref is accepted.
Duplicate/unknown JSON fields, booleans in numeric fields and oversized requests are
refused. The SSH principal is the admission boundary; this implementation does not
accept workflow OIDC/GitHub tokens or add an approval-file mechanism. A fixed
`config/github-read-token` supplies server-side read-only access to this private
repository's Actions metadata/artifacts. Keep it outside Git, owner-only and out of
workflow logs. GitHub/API failure stops the deployment.

The server checks the active `workflow_dispatch` run's exact name/path, main branch,
repository and run attempt; downloads authenticated release evidence; reuses
`verify_publication` to verify full CI, all MySQL shards, exact-image smoke,
publication and artifact identity; and checks image/source/hash against the request.
The source must belong to main history and advance the currently recorded source.
The pulled image's registry manifest, tested configuration ID, platform and source
labels must match, including support for classic/containerd image stores.
The future workflow still needs its own reviewed SSH credential/confirmation handling;
merely naming a workflow in this document does not configure it.

## Server state and credentials

One-time setup must provide `/srv/dcms-prd/{release,config,history,backups,registry}`,
root custody of all parents, private state directories (0700), a pre-created private
`deployment.lock`, protected `config/current.json` containing the full verified current
release manifest, and `registry/config.json` with Production-only GHCR pull access.
The `release` directory contains reviewed deployment tools and Compose configuration;
it is not replaced from a workflow-selected checkout. All credential files are 0600.
Every run/attempt reserves `history/gha-RUN-ATTEMPT` once, keeps private command logs,
request/publication evidence, previous/candidate manifests, backup receipt and a
fixed-stage `result.json`. Requests cannot choose these paths. Concurrent or replayed
attempts are refused. History/logs contain sensitive configuration and must stay private.
Stage records and recovery markers are synced before each destructive stage; state
publication and off-host acknowledgement sync files/directories before reporting success.

In addition to the Phase 12.2A files, provision separately generated accounts:

- `config/migration.env`: exactly `DB_USERNAME=dcms_prd_migrate` and
  `DB_PASSWORD=<independent secret>`. Grant reviewed schema/trigger privileges only
  on `dcms_prd`; the runtime account must not have migration DDL privileges.
- `config/backup.cnf`: exactly a `[client]` section with `user=dcms_prd_backup` and
  `password=<independent secret>`. Grant the MySQL 8.4 privileges needed for a consistent
  full database dump including views, routines, triggers and events. Validate actual
  privileges and definers during setup; the engine does not grant privileges, enable
  global function-creator trust, or disable live database durability.
- Application, backup, migration and root passwords must all differ. Attest that
  none of these secrets, the APP_KEY or transport keys came from UAT; the engine
  deliberately does not read UAT files to compare secrets.

Do not run the routine engine until the already initialized app/database are healthy,
all four named volumes and both networks exist with matching project ownership,
MySQL runtime image matches the reviewed configuration, and `current.json` and
`compose.env` match the actual deployed image/schema. No normal deployment creates
an initial database, seeds users/business data, imports UAT data or starts/recreates MySQL.

## Deployment sequence and failure behavior

1. Acquire the nonblocking Production lock, refuse unresolved recovery state, reserve
   the run history and verify release evidence. Validate active and staged Production
   Compose configurations without changing the active file. Check separate credentials,
   off-host prerequisites, current containers, networks, local volume drivers/labels,
   exact mounts, private ports and running image/source. Extra project containers,
   host-backed/external volumes and UAT identities are refused.
2. Pull the candidate digest and verify its source/tested image identity. Read its
   migration filenames/hashes without executing them. Previously deployed inventory
   must remain an unchanged prefix and live migration names/batches must match the
   current manifest. New migration files must be unique and sorted.
3. Persist `config/recovery-required.json`, enter Laravel file maintenance, verify the
   shared marker, and stop only `app`. The enrolled topology has synchronous queues
   and no external writers; setup must confirm no cron/import/DB writers bypass it.
   The database stays running. This quiescence provides a paired database/file snapshot.
4. Dump `dcms_prd`, archive document-vault and private storage through read-only mounts,
   archive required protected configuration/current identity, and create `identity.json`
   plus SHA-256 checksums. Stream dumps to exclusive private files. Send and restore-verify
   the set off-host; a matching acknowledgement is mandatory before migrations.
5. Recheck database identity/start time/mounts and unchanged protected configuration.
   Only when inventory advances, run the candidate's
   `php artisan migrate --force --no-interaction` using the dedicated migration env file.
   Verify exact resulting inventory and preserve every previous migration batch.
   No reverse migration or automatic database restore exists.
6. Run Compose `up -d --no-deps --no-build --pull never --force-recreate app` only.
   Verify the new image/source and unchanged app mounts; check Apache configuration,
   writable private/vault/framework storage, absence of `public/storage`, migration
   status, and database health/identity. Leave maintenance, then check loopback and
   HTTPS `/up` (200 without redirects or TLS bypass), and protected storage/config paths
   (403/404). Container readiness is bounded to 60 attempts; HTTP retries/timeouts are bounded.
7. After all checks pass, record previous/current manifests and replace only the image
   line in active Compose environment, write success and remove the recovery marker.
   APP_KEY/application settings, database and document volumes stay intact. Routine
   success requires no recovery cleanup. The deployment lock releases automatically.

Failure records the fixed stage and retains backup/history. A failure before maintenance
requires no recovery marker. Once maintenance begins, failures request maintenance again
and keep the marker, including migration failure, database restart, or failed health.
SIGTERM/HUP/INT/timeouts follow the same path. SIGKILL/power loss may leave `*.next`
files or a partial state publication; the durable marker blocks a new routine deployment.
No code deletes volumes, prunes backups, downgrades schema or claims a partial success.
Storage I/O failure may prevent a final `failed` journal update; an unfinished stage
record or recovery marker always requires inspection and is never success evidence.

Inventory/batch checks establish forward migration history compatibility; they cannot
prove that arbitrary new PHP/DDL preserves business semantics. Release review must
assess new migration operations and data compatibility. A modified old migration,
unknown live migration, source downgrade, or ambiguous recovery state requires review.

## Production off-host backup and restore verification

`config/backup-target.json` independently selects the Production backup host/SSH port:

```json
{"host":"REPLACE_WITH_PRODUCTION_BACKUP_HOST.invalid","port":22}
```

The placeholder deliberately fails validation. Provision `config/backup-ssh-key` and
`config/backup-known-hosts` separately from UAT. SSH uses only that key, strict pinned
host verification, no caller SSH configuration/agent and fixed destination user
`dcms-prd-backup` / command `receive-dcms-prd-backup`. The backup owner must confirm
that DNS resolves to an independent host; a hostname alone cannot prove physical separation.

On that host install the reviewed helper set together, root-owned, for example under
`/usr/local/libexec/dcms-prd/`. A dedicated `dcms-prd-backup` account's forced command
runs `/usr/bin/python3 -I -B /usr/local/libexec/dcms-prd/prd-backup.py` with
`SSH_ORIGINAL_COMMAND=receive-dcms-prd-backup`; disable shell/forwarding/PTY access.
It needs a matching native MySQL **8.4** server/client installed for disposable checks,
not permission to access either operational database. Root owns
`/srv/dcms-prd-backups/config/receiver.json` and its ancestors (readable by this account):

```json
{
  "store":"/srv/dcms-prd-backups/managed-v1",
  "restore_work":"/srv/dcms-prd-backups/restore-work"
}
```

Create the two distinct leaf directories owned by `dcms-prd-backup`, mode 0700, under
root-owned parents. Paths must remain inside `/srv/dcms-prd-backups`, without symlinks
or overlapping store/work directories. These accounts/paths/credentials are independent
of UAT backup and restore targets. Provision disk capacity, storage encryption/access
policy and retention monitoring. No automatic deletion is enabled in this engine.

The receiver preserves each `pending-UUID` transfer, checks every file's SHA-256,
rejects unsafe/duplicate/link archive entries, and verifies the embedded Production
identity. It restores the dump using a newly initialized temporary MySQL data directory
and unique Unix socket under `restore_work`, with TCP/MySQLX, events, local infile and
binary logging disabled. Clients use `--no-defaults --no-login-paths --protocol=SOCKET`
and that exact socket, with no host/port fallback and no live credential files.

Checks cover database identity, migration inventory, CHECK TABLE, foreign-key orphans,
numbering-counter integrity, required immutable-history triggers, and each attachment
version's object existence, size and SHA-256. Every private archive byte is covered by
the verified archive checksum; current application attachment metadata references the
vault's `objects/UUID` files. The disposable process is terminated and its temporary
data removed on success/failure. The backup payload remains intact on failure.
Only a successful restore creates a private receipt and promotes the set to
`success-gha-RUN-ATTEMPT-UUID`; the sender accepts only the matching backup ID/checksum
acknowledgement. Backup config includes APP_KEY, database/migration/backup credentials,
transport settings/key, registry configuration and current release manifest: treat the
entire set as highly confidential. The actual release/Production Compose files and
Apache tuning are included too, so recovery can reproduce the installed configuration.
The separate GitHub read credential can be reissued. TLS keys and the installed host
Nginx configuration need the separately established host/certificate backup procedure.

## Controlled recovery and first installation

For a failed deployment, keep admission blocked and inspect the private history's
`result.json`, command logs, `backup.json` (if present), backup directory and recovery
marker. Confirm whether migrations began, inspect actual migration names/batches and
running image, and verify that the original database ID, mounts and documents survive.
Do not simply delete the marker or rerun a failed workflow attempt.

- If failure occurred before migrations, verify the original schema and paired files,
  then an operator can restart only the recorded original app image, verify all health
  and confidentiality checks, record recovery and clear the marker after reconciliation.
- If any migration may have run, keep maintenance and use a reviewed forward repair.
  MySQL DDL may be partially committed. An old image is safe only if its compatibility
  with the actual schema is established; the routine engine provides no downgrade path.
- For data recovery, preserve the failed live database/vault volumes first. Select a
  successful paired off-host set, compare its receipt/checksums, and copy **only the five
  payload/checksum files** into a new private `pending-UUID` directory. Re-run the fixed
  `prd-restore-check.py` as `dcms-prd-backup` before considering cutover. Restore into a
  separately provisioned isolated recovery instance/volumes, using the paired APP_KEY,
  settings and documents and matching release. Verify RBAC, audit, attachments, numbering
  and health there. Review RPO/data loss and approve a controlled cutover; never overwrite
  the live Production or UAT database automatically.
- Reconcile `current.json`, `previous.json`, active Compose image and actual schema/image
  after any interrupted success publication. Preserve partial `*.next` evidence in history
  before removing it. Record the reviewed outcome, recheck full health, then clear the
  Production recovery marker. Leave UAT and NetBox untouched.

Initial bootstrap is a separate one-time operation: approve capacity/DNS/TLS, provision
private directories and independent secrets/accounts, initialize only fresh Production
volumes/database, run reviewed initial migrations/setup, establish the full current
manifest, ingress and restore drill, and verify health/security before enabling routine
admission. No bootstrap/business import command is included in the normal entry point.

Local tests mock Docker, SSH, GitHub and disposable MySQL process boundaries. Real host
verification remains for MySQL dump grants/definers, restore with the actual schema and
data volume, startup/import timing and disk capacity, effective UID/mount ownership,
Compose `run --env-from-file` support, registry access, SSH forced-command enrollment,
DNS/TLS/proxy behavior, resource competition and a controlled recovery drill. Deployments
have a one-hour bound; backup streams/import have ten-minute bounds, off-host transfer
has a twenty-minute bound, and restore verification has its own bounded lifetime. Measure
these against Production data before activation. No operational host was contacted here.
