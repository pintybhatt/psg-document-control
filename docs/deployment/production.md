# Production configuration, deployment and recovery — Phase 12 (simplified routine deployment)

The original phases prepared configuration, the Production deployment/backup/recovery
engine and `.github/workflows/deploy-prd.yml`. Production is now installed on NetBox
192.168.0.228, according to the operator. Routine deployment uses an automatic local
pre-deployment backup; daily QNAP backups operate independently. This simplification
is prepared locally; no server, cron, runner or GitHub settings were changed and no
deployment was performed.
Historical initial-setup guidance below remains for reference; do not bootstrap the
existing installation again.

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

GitHub settings, deployment credentials and activation remain separate setup work.
Phase 12.3 prepares the workflow locally. Copying templates or passing preflight does
not authorize deployment.

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

The **Deploy DCMS PRD** workflow runs its reviewed deployment tooling from `main`,
validates its `confirm_deploy` input, resolves `ref` to a published release, and sends
a bounded JSON request on stdin containing exactly:

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
The workflow uses its own Production SSH credentials and the existing server contract
unchanged. GitHub environment secrets, runner labeling and server SSH enrollment still
need setup; no settings or operational servers were changed during implementation.

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
publication syncs files/directories before reporting success. The local backup receipt
is recorded in the private deployment history.

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
   current containers, networks, local volume drivers/labels,
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
   plus SHA-256 checksums. Stream dumps to exclusive private files, validate every
   checksum/archive and record `backup.json` with `scope=local` at stage `local_backup`.
   This fresh quiesced local backup remains mandatory before migration. Any dump,
   archive or checksum failure blocks migrations/replacement and retains recovery state.
   It is not claimed to be off-host or restore-tested.
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

## Routine backups and independent daily QNAP protection

Routine Production deployment requires only the automatic **local** pre-deployment
backup described above. There is no QNAP freshness gate, SSH backup-readiness call,
mandatory off-host restore, NFS verifier installation or backup approval command.
Use the existing **Deploy DCMS PRD** workflow with its existing inputs; **Deploy DCMS
UAT** retains its existing workflow and independent deployment/backup behavior.

The local set contains `database.sql`, `documents.tar` (document vault and private
files), `configuration.tar`, `identity.json` and `SHA256SUMS`, under
`/srv/dcms-prd/backups/gha-RUN-ATTEMPT`. Configuration includes APP_KEY,
runtime/root/migration/backup credentials, current release identity, registry
configuration, Compose files and Apache tuning. The set is private recovery material;
preserve host encryption/access controls. No QNAP SSH key, known-hosts file, backup
receiver configuration or off-host credentials are needed to create or validate it.
Older recovery archives containing those credentials remain valid for recovery.

The established daily system continues independently, unchanged:

- NetBox 192.168.0.228: 01:00 Laos time,
  `/usr/local/sbin/dcms-prd-daily-backup.sh` creates the database, document/private
  and encrypted recovery-configuration backup.
- NFS server 192.168.4.25: 01:30 Laos time,
  `/usr/local/sbin/dcms-prd-qnap-pull-backup.sh` pulls to QNAP
  `192.168.0.239:/XPPL-PRODUCTION-BACKUP`, mounted at
  `/mnt/xppl_production_backup`, under `XPPL DCMS/daily-YYYYMMDD_HHMMSS`.
  Daily sets contain `database.sql`, `documents.tar`, `configuration.tar.enc`,
  `SHA256SUMS` and `.complete` naming the completed set.
- Retention runs at 01:45 and preserves the latest verified backup. Do not edit,
  disable or replace these scripts or cron schedules as part of deployment.

Backup owners retain responsibility for daily-job monitoring, key custody, retention
and periodic isolated restores. The reported drill restored MySQL 8.4, checked 71
tables and 36 migration records, and decrypted recovery configuration; retain the
actual drill record. Routine deployment does not inspect this evidence or certify the
latest daily transfer. It can proceed while QNAP is stale or unavailable. The fresh
local set protects against deployment failure but not loss of the NetBox host; the
off-host recovery point depends on the last successful daily pull and may lose later
changes. No automatic database restore or schema downgrade is added.

`prd-qnap-readiness.py` and the legacy backup receiver/restore utilities remain
standalone tools; the routine engine neither imports nor invokes the QNAP verifier
or off-host transport. Existing installations of those tools need no removal for
this change. No NFS Python/system package change or new account/service is required.
TLS keys and host Nginx remain covered by the established host backup procedure.

## Updating the installed deployment tools

The GitHub workflows invoke the installed server tools, so a new workflow checkout
or app image alone does not remove the old server-side admission gate. In the later
installation window, update `deploy-prd.py` and `prd-backup.py` together from the
reviewed revision under the existing Production deployment lock. Keep private copies
and hashes of the previous tools, root custody and non-writable ancestors, and check
syntax/imports before releasing the lock. Use atomic file replacement while the lock
is held; restore the complete prior tool set if installation fails. Coordinate with
existing daily jobs without disabling or changing them.

Do not replace active Compose settings, app images, release manifests, database or
volumes when updating these control tools. If `release` is an immutable application
bundle rather than the documented control-tools directory, resolve that installation
layout before modifying it. Preserve unresolved recovery markers for reconciliation;
do not clear them merely to rerun deployment. No NFS installation, QNAP key enrollment
or additional manual backup verification is part of the routine deployment process.
No operational installation or workflow dispatch is performed by this local change.

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
  verified QNAP daily paired set and recheck its checksums. Decrypt configuration
  using the separately held recovery key and follow the tested daily-backup recovery
  procedure in an isolated MySQL 8.4 environment. The legacy `prd-restore-check.py`
  expects its older archive format; do not point it at daily encrypted backups. Restore into a
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
have a one-hour bound and local backup streams have ten-minute bounds. Measure
these against Production data before activation. No operational host was contacted here.

## Phase 12.3 operator workflow

Use **Actions → Deploy DCMS PRD → Run workflow**, leaving GitHub's built-in workflow
branch selector on **main**. There are exactly two custom inputs:

| Input | Required | Default | Accepted value |
| --- | --- | --- | --- |
| `confirm_deploy` | Yes, string | None | Exactly `DEPLOY_PRD`, including case; no whitespace |
| `ref` | Yes, string | `main` | `main`, an eligible tag, or a full 40-character commit SHA |

There is no image/run/artifact override, environment selector or further approval
file/switch. The workflow adds no extra manual confirmation/reviewer mechanism.
GitHub's workflow branch selects trusted tooling; the custom `ref` selects application
source only. Checkout is pinned to `github.sha`, never to the administrator's `ref`.
Both shell guards and `select-prd-release.py` check the repository, main workflow path,
workflow revision, confirmation and active deployment run/attempt. The API must show
that revision as the current protected main head; a queued run that has become stale
fails before contacting Production. Dispatch a new run from current main after review.

The selector performs read-only GitHub API operations:

- `main` resolves to the checked protected main head. A full SHA is validated as a
  commit in this repository. Uppercase hex is normalized to the same lowercase SHA.
- A tag is resolved by its exact `refs/tags/NAME` reference. Lightweight tags point
  directly to commits; annotated/nested tag objects are peeled with a bounded cycle
  check. `refs/tags/NAME` is also accepted explicitly. Safe ASCII tag names may include
  dots, underscores, hyphens and slash-separated components. Bare hex names that could
  be short SHAs are refused; use an explicit tag ref to disambiguate such a tag.
- Arbitrary branches, `refs/heads/...`, short SHAs, repository-qualified refs, Git
  revision expressions and shell syntax are rejected. There is no branch fallback.
- The resolved commit must be an ancestor of the approved main revision. Reference
  resolution is repeated before writing the request; a moved/deleted tag or changed
  main stops selection rather than substituting another commit.
- Search covers main push CI publications for the exact source and retained historical
  main release-workflow publications. Only artifacts whose release name identifies
  that source are candidates. The newest candidate publication is selected by run ID;
  expired, failed, ambiguous or unverifiable selected evidence fails without falling
  back to older evidence. A run without a manifest is not a publication candidate.
  Search is bounded to ten pages of 100 runs per workflow and rejects pagination drift.
- The existing authenticated artifact downloader and `verify_publication` verify the
  repository/workflow/source, full CI, MySQL checks, exact-image smoke, publication,
  immutable GHCR digest, platform, migration inventory, artifact and current attempt.
  Both modern publications and the verifier's supported historical layout are accepted.
  There is no additional seven-day UAT selection window; actual artifact expiry applies.
- The selected run and artifact are checked again and the eight-field request is
  generated automatically with the original manifest bytes' SHA-256. The request is
  private (0600), never an administrator input or uploaded workflow artifact.

See the [GitHub reference API](https://docs.github.com/en/rest/git/refs#get-a-reference),
[tag object API](https://docs.github.com/en/rest/git/tags#get-a-tag) and
[workflow contexts](https://docs.github.com/en/actions/reference/workflows-and-actions/contexts#github-context)
for the underlying API/context contracts. Selection never builds an image, dispatches
CI/publication, pushes a tag, or silently chooses a different source. If evidence is
missing or has expired, stop and resolve release retention/publication through its
existing process outside this deployment workflow.

## Phase 12.3 GitHub and runner prerequisites

Prepare the **prd** GitHub environment with these independently provisioned secrets:

| Secret | Purpose |
| --- | --- |
| `PRD_SSH_KEY` | Production-only private key for `dcms-prd-deploy@192.168.0.228` |
| `PRD_KNOWN_HOSTS` | Independently verified pinned host-key data for `192.168.0.228` on port 22 |

Do not copy the UAT key. Enroll the corresponding Production public key for the
restricted `prd-ssh-command.sh` forced command and no-argument sudo entry point on
`192.168.0.228`. The workflow can invoke only **`deploy-dcms-prd`**, sending the
validated request on stdin. It cannot select a different host, port, command, root,
Compose project or database. SSH disables ambient client configuration, proxy commands,
agent/password/interactive authentication and forwarding, and uses strict host-key
verification plus one connection attempt. Temporary key/known-host/request files are
removed on success/failure. Captured SSH diagnostics remain private and are not echoed
into CI logs; the server's protected history remains the recovery authority.

Use the existing trusted self-hosted runner infrastructure on **192.168.0.227**. Add a
`dcms-prd-runner` label to an eligible Linux/X64 runner there; no new runner or UAT
workflow modification is implemented. The job requires
`[self-hosted, Linux, X64, dcms-prd-runner]`. Labels route jobs and do not isolate secrets:
keep that runner/account restricted to trusted code and trusted deployment jobs.
Ensure Python 3, Git and OpenSSH client tools are present and that outbound GitHub API,
artifact download and fixed Production SSH routes are permitted.

Restrict the `prd` environment to main deployments and scope the two Production secrets
there. Do not add required reviewers or another confirmation gate for this workflow.
The job token needs only `contents: read` and `actions: read`; no publishing, package-write,
OIDC or repository-settings permission is requested. Keep the existing protected main
and publication process. No environment/settings/key/runner changes were made here.
The Production host still needs the Phase 12.2B engine, protected server-side GitHub read
credential, registry access, healthy initialized stack, separate migration/backup accounts,
automatic local pre-deployment backups, TLS and recovery protections described above.

Production deployments use concurrency group `dcms-prd-xppl-deployment` with cancellation
disabled. Backup, migrations, replacement and health checks run exclusively in the
server engine. Success is reported only after its SSH invocation exits successfully.
A failure/cancellation/interruption has no automatic retry: inspect protected
`/srv/dcms-prd/history/gha-RUN-ATTEMPT/result.json` and
`/srv/dcms-prd/config/recovery-required.json` if the entry point started. A transport
failure may occur before server history exists. Follow the controlled recovery procedure
before manually rerunning an uncertain deployment; the server independently refuses
replays, unresolved recovery, downgrades and already-current releases.

Local verification uses mocked GitHub metadata and fake SSH executables, full YAML
structure checks when PyYAML is available, and Bash syntax checks. It does not execute
GitHub Actions or contact either host. Real GitHub environment/runner routing, host-key
custody, SSH forced-command enrollment and end-to-end execution still require setup and
verification. Evidence or registry availability can change after runner verification;
the server independently re-verifies evidence and pulls/checks the immutable image before
maintenance, refusing unsafe deployment rather than rebuilding or substituting an image.
