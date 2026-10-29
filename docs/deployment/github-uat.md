# GitHub Actions UAT deployment (prepared, disabled)

This procedure implements **Deploy DCMS UAT** for an already initialized XPPL UAT stack. It is local preparation, not authorization to enroll a host or execute deployment. No host, GitHub setting, credential or image was changed. Production is disabled: there is no Production workflow, environment, credential or entry point in this change.

This supersedes the older manual-only automation discussion in [uat.md](uat.md) and [releases.md](releases.md). Their initial bootstrap, DBA provisioning, backup/restore, ingress and shared-host isolation procedures remain applicable. Automation never initializes a database. Preserve the independently reviewed `docker/apache-uat.conf` worker configuration; this change does not alter it or either Compose file.

## Trust and authorization

An approved operator chooses an existing successful publication run, manifest artifact and immutable DCMS digest. The main-only workflow reads current GitHub evidence with `contents: read` and `actions: read`. It retrieves the selected manifest without extracting arbitrary archive paths, checks publication/run/attempt/artifact identity, full release gates and exact-image smoke, and rechecks the run after download. Four distinct successful `ci / MySQL 8.4 (shard N/4)` jobs are required for sharded evidence. Historical releases, including RC.2, may instead have exactly one successful `ci / MySQL 8.4` job. Mixed layouts, missing shards, duplicate jobs, skipped/cancelled/failed jobs and incomplete pages fail.

SSH sends only a small JSON request on stdin. It contains request ID, exact image, release run/attempt/artifact ID and manifest SHA-256. It cannot supply shell text, a Compose project, a file path, credentials or migration commands. The forced command allows only the literal `deploy-dcms-uat`; scp, sftp, shells, arguments and forwarding are prohibited.

On the server, an independent administrator must first enroll the owner's approval/evidence in a root-only request directory. `dcms-deploy` cannot write that directory, the deployment code, Compose/config files, registry credentials or history, and has no Docker socket/group access. The entry point repeats the existing evidence/expiry verification, matches every request field against the enrolled approval, checks an owner allowlist, verifies the previous digest against both recorded and running state, and holds a local lock. It reserves a unique history directory before any Docker operation; a used/failed request cannot be replayed. GitHub concurrency also serializes this workflow. GitHub may replace an older pending run when another is queued; operators must check run status and submit one request at a time.

Owner identity is established by authenticated administrator custody and organizational review of the root-enrolled record. `approved_by` alone is not a signature. Root/Docker administrators and anyone able to alter trusted workflow code remain privileged. A compromised SSH key can invoke an unexpired, unused, already enrolled approval, but cannot create one or substitute its image. Revoke both the key and any unused approvals on suspicion of compromise. Enroll records shortly before their approved window; revoke by moving unused request directories out of `requests` under administrator control. Never edit an approval already used in history.

## GitHub setup — separate authorized administration

1. Merge reviewed tooling to protected `main` only after owner approval. Restrict workflow changes and required PR/CI checks; review anyone who can change deployment code or secrets. Do not run this workflow from the feature branch. No push or merge is included in this work.
2. **Before enabling dispatch**, precreate environment `uat`, restrict deployment branches to `main`, require an owner reviewer, prevent self-review, and disable administrator bypass where supported. Naming an environment in YAML does not protect it. GitHub private-repository required reviewers depend on the plan; if unavailable, keep deployment disabled until the required protected approval control is available. Root-side approval supplements that control; it does not justify silently omitting it. See [GitHub environment availability](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments).
3. Environment secrets: `UAT_SSH_KEY` is the dedicated UAT private key, never reused for Production or personal login. `UAT_KNOWN_HOSTS` is a complete known-hosts entry verified by the host owner over an independent trusted channel. For a nonstandard port use `[hostname]:port keytype public-host-key`; for port 22 use the hostname form. Its hostname must match `UAT_HOST`. Do not trust an unattended `ssh-keyscan` result. No key is generated/provided by this repository.
4. Environment variables: `UAT_HOST` is the approved DNS name or IPv4 address; `UAT_SSH_PORT` is the explicit approved SSH port. There is no configurable SSH username; it is `dcms-deploy`.
5. Provision a separately approved host registry reader, `read:packages` and package Read ACL only, root-owned `/srv/dcms-uat/registry/config.json` mode 0600. Follow [registry custody/rotation](releases.md#registry-retrieval-choose-a-dedicated-host-read-credential). The workflow has no package write permission and never transfers its GitHub token to the host.
6. Establish an approved network route from `ubuntu-24.04` GitHub-hosted runners to SSH. A private LAN address will not be reachable by default. Do not open broad public SSH or install a runner on the shared NetBox host to bypass this prerequisite. If an isolated runner/network design is required, review its workflow/credential boundary separately before activation.
7. Only after server enrollment, reviewed environment controls, network access and rehearsal, set **repository** variable `ENABLE_UAT_DEPLOYMENT=true`. It is deliberately absent/off by default. Set it false to prevent new workflow deployments; independently set server `automation.json` `enabled=false` to reject direct invocations. Disabling does not terminate an in-flight deployment.

The SSH client uses an isolated config, one explicit identity, `StrictHostKeyChecking=yes`, an independently pinned known-hosts file, no agent/password authentication, no PTY and no forwarding. It never auto-accepts or updates host keys. Temporary key files are 0600 inside a private runner directory and removed by a trap; ephemeral runner teardown is the fallback after hard termination. Rotate the environment key and root-managed public key together, test through the same protection process, then revoke the old key. Verify changed host keys out of band before updating the pin.

## Server enrollment — design only, do not execute during this task

A host administrator must authorize and review enrollment separately. Follow the existing UAT inventory/capacity/NetBox baseline and initial bootstrap runbook first. This automation requires healthy existing UAT MySQL and an existing app; a missing stack stops. No `up db`, database image pull, volume creation/deletion, Docker cleanup, global Nginx change or NetBox command exists in the entry point.

Install the reviewed bundle under root-owned `/srv/dcms-uat/release`:

- `compose.release.yaml`, `compose.uat.yaml` unchanged;
- `docker/apache-uat.conf` unchanged, readable/traversable by UID 33 as already documented;
- `docker/preflight.py`, `docker/verify-uat-release.py`, `docker/deploy-uat.py`.

All parents and executable/config files must be root-owned, without symlinks or group/other write permission. Release paths may be 0755 directories / 0644 files for the Apache bind mount. Keep `config`, `registry`, `requests`, `history`, `backups` at 0700 and their confidential files at 0600. Do not grant `dcms-deploy` traversal of private directories. No writable source checkout is used on the host.

Administrator installation mapping (review against an approved bundle/checksums; no automatic installer is provided):

| Repository source | Installed root-owned path / mode |
| --- | --- |
| `docker/uat-ssh-command.sh` | `/usr/local/libexec/dcms-uat-ssh` / 0755 |
| `docker/uat-deploy-entrypoint.sh` | `/usr/local/sbin/dcms-uat-deploy` / 0755 |
| `docker/deploy-uat.py` | `/srv/dcms-uat/release/docker/deploy-uat.py` / 0644 |
| `docker/uat-automation.example.json` | `/srv/dcms-uat/config/automation.json` / 0600, completed by owner |

Provision `/srv/dcms-uat/deployment.lock` once as a root-owned regular 0600 file. Keep it permanently; deleting/replacing a live lock can defeat locking. `current.json` at `config/current.json` must be the actual last successfully deployed release manifest, validated against the running digest/tested ID, schema and volumes. Existing `compose.env` must reference that same digest and exactly `/srv/dcms-uat/config/app.env`, `db-password`, and `db-root-password`. Enrollment preserves the existing APP_KEY, DB users/accounts, documents/private/framework/database volumes and UAT settings. Do not copy example secrets over them.

Complete `automation.json`: retain target `uat` and project `dcms-uat-xppl`; set the explicit approved owner identifiers in `owners`; set the real `https://APPROVED_HOST:8444/up` health URL; choose positive `minimum_free_memory_mib` and `minimum_free_disk_bytes` thresholds with the host owner for both Docker storage and `/srv/dcms-uat`, accounting for image pull/old-image retention and NetBox. Null/example values deliberately block deployment. Only after all enrollment checks set `enabled=true`. The automation uses the local Unix Docker socket and a cleared environment, never a remote Docker context.

Create `dcms-deploy` as a dedicated unprivileged account with a locked password, no other authorized keys, no interactive login mechanism, no membership in docker/sudo/admin groups and no sudo grants except the exact wrapper below. A valid `/bin/sh` account shell is needed for OpenSSH forced commands; setting `nologin` can prevent them from running. Root manages the authorized keys outside the account home.

Proposed OpenSSH account restriction, to merge carefully with host policy and test with `sshd -t` / `sshd -T -C ...` **before** an administrator reloads SSH (do not overwrite host configuration):

```text
Match User dcms-deploy
    AuthorizedKeysFile /etc/ssh/authorized_keys/dcms-deploy
    ForceCommand /usr/local/libexec/dcms-uat-ssh
    AuthenticationMethods publickey
    PasswordAuthentication no
    KbdInteractiveAuthentication no
    DisableForwarding yes
    PermitTTY no
    PermitUserRC no
    PermitUserEnvironment no
```

The root-owned authorized-keys file must contain only the approved key, prefixed with `restrict,command="/usr/local/libexec/dcms-uat-ssh"`. Add a reviewed `from="..."` source allowlist when stable runner egress exists. Keep the file and all parent directories root-owned and non-writable by the account. `ForceCommand` covers the account even if a key option is accidentally omitted. Do not grant access via another SSH identity, certificate principal or password.

Proposed root-owned `/etc/sudoers.d/dcms-uat-deploy` (0440), validated with `visudo -cf` before activation:

```sudoers
Defaults:dcms-deploy env_reset
# Empty quoted argument string means NO arguments, not arbitrary arguments.
dcms-deploy ALL=(root) NOPASSWD: /usr/local/sbin/dcms-uat-deploy ""
```

Do not use wildcards, `SETENV`, sudo access to Python/shell/Docker/Compose/install/systemctl, or writable wrapper/helper paths. The wrapper rejects all arguments, clears the environment and invokes isolated Python at one fixed path. The Python entry point also rejects arguments and bounds stdin size/time. Test denied shell/scp/sftp/forwarding/extra arguments and denied sudo Docker/shell commands under administrator supervision; do not test arbitrary host commands through the deployment identity. The SSH and sudoers designs are **not installed or activated** by adding these files to Git. See [OpenSSH forced commands and key restrictions](https://man.openbsd.org/sshd.8).

## Enroll a release request

An authenticated maintainer retrieves the successful publication manifest and current attempt's run/jobs/artifacts directly from GitHub, using the handoff in [releases.md](releases.md#uat-evidence-and-approval-handoff). Fetch **all pages** and preserve originals. Prefer `runs/RUN/attempts/ATTEMPT/jobs?per_page=100` for jobs. The run's workflow SHA can differ from the candidate source SHA. Verify reviewed main ancestry and the release workflow's trust; no retagging or rebuild is allowed. Expired/deleted manifest evidence blocks automation even if the image remains available.

Owner completes `docker/uat-approval.example.json` outside Git. It reuses the existing target/project/decision/image/source/hash/run/attempt/artifact/previous/recovery/migration-review/time fields. Automation additionally requires:

- `request_id`: unique 3–64 character identifier, letters/digits/underscore/hyphen, starting with a letter/digit;
- `operation`: `deploy` or `rollback`;
- `schema_mode`: `unchanged` or `reviewed-applied`;
- `schema_compatible`: literal `true` only after review, and `schema_compatibility_reference`;
- `approved_by`: an owner in the independently enrolled allowlist; `decision=approved` and an explicitly approved timezone-aware validity window.

`previous_image` must be the exact current UAT digest, including for rollback; null is supported only by the old manual initial-bootstrap verifier and cannot authorize automation. A nonempty recovery and migration-review reference remains mandatory for app-only releases. Review recovery readiness and paired backup policy before every release. No app setting, APP_KEY, user account or document data is reset or seeded by automation.

A separate administrator stages the reviewed files at `/srv/dcms-uat/requests/REQUEST_ID/`, root-only:

```text
manifest.json        exact downloaded release-manifest.json bytes (do not reformat)
approval.json        completed owner authorization
run.json             authentic publishing run metadata
jobs.json            complete current-attempt jobs object
artifacts.json       complete release artifacts object
backup-review.json   additionally required for reviewed-applied schema mode
```

Never transfer these files via `dcms-deploy`; it intentionally cannot upload files. Use the organization's authenticated administrator channel. Verify the manifest SHA-256 and approval file custody immediately before enrollment. Store publication evidence and authorization before opening the approved window. The workflow's fresh request must match the root record exactly; reruns, changed artifacts/manifests, expiry or changed previous state require renewed owner review and a new request ID.

## Schema changes: separate, reviewed work

For `unchanged`, the candidate and current complete migration inventories must be identical. The candidate's actual migration file hashes must match its manifest, and the live database migration table must exactly match those migrations. Pending or unexpected migrations stop before app replacement. The read-only schema probe uses the candidate PHP runtime with the existing runtime DB credentials; it never executes migration classes.

For schema changes, do not dispatch until a separate administrator/DBA window has:

1. Blocked writers, drained traffic and kept maintenance active from the paired backup through migration and app replacement.
2. Taken a consistent database + document/private + config/APP_KEY recovery set, verified checksums/off-host recovery access, reviewed a restoration plan and isolated restore drill, and approved the data-loss/recovery decision.
3. Reviewed exact candidate migration files, privileges/triggers and compatibility; obtained explicit migration authorization; applied only those reviewed migrations using the dedicated migration account through the existing manual runbook. No GitHub SSH identity receives that account.
4. Recorded any partially committed MySQL DDL and resolved it before dispatch. Leave maintenance active. The automation checks maintenance for `reviewed-applied`, live migrations and hashes; it will not finish pending work.

Set `schema_mode=reviewed-applied` and supply `backup_reference`, `restoration_plan_reference`, `migration_authorization_reference`. `backup-review.json` must repeat these three approved references and the exact previous/candidate `previous_image`/`image`; identify an allowed `reviewed_by`; set `verified`, `paired_database_documents_private_config`, `off_host_verified` to literal true; and give a nonempty `restore_drill_reference`. Keep the actual backup checksums, cut-off, inventory, retention, restoration procedure and drill evidence in those protected review records. These are authenticated operator attestations; the tool cannot prove an off-host restore worked. A missing/false/mismatched field blocks execution.

The automation never runs `migrate`, `migrate:fresh`, schema rollback, destructive resets, seeding or database replacement. It cannot grant migration privileges. If review cannot establish safety, remain in maintenance and use the incident procedure.

## One-click operation after enrollment

1. Open Actions → **Deploy DCMS UAT** → Run workflow → branch **main**.
2. Select the owner-enrolled `request_id`, successful `release_run_id`, exact `release_artifact_id`, and complete `ghcr.io/pintybhatt/psg-document-control@sha256:...` reference. These identifiers select an existing publication, not a build. No `latest`, mutable tag or unrelated repository is accepted.
3. Required owner reviewer inspects the digest, source, previous digest, approval window, migration/recovery decisions and workflow revision in the `uat` environment gate, then approves.
4. Review the workflow result and the protected server history. Representative HTTPS login/secure-cookie, English/Lao, document numbering, allowed/denied synthetic attachment, export and audit checks plus formal XPPL acceptance remain required. A green technical deployment is not formal UAT acceptance.

The fixed engine validates configuration/isolation, approved capacity, current healthy DB, previous app identity, persistent volume names/labels and Apache bind mount. It pulls **only** the selected app digest, verifies its tested image ID/OCI source/platform, and probes migration hashes/live status without executing migrations. It enters maintenance, replaces only `app` with `compose up -d --no-deps --no-build --pull never --force-recreate app`, verifies unchanged app mounts and DB container ID/start time/mounts/health, Apache config, writable private paths, DB status and schema again. It exits maintenance only after those checks, requires loopback and approved HTTPS `/up` HTTP 200 without redirects/insecure TLS, public `/storage/...` HTTP 403, and Docker app health. See [Compose app-only dependency behavior](https://docs.docker.com/reference/cli/docker/compose/up/).

Image identity checks require the Docker Buildx plugin in the root execution environment. Using the existing protected `/srv/dcms-uat/registry` authentication, `buildx imagetools inspect --raw` retrieves only the approved immutable digest; the raw manifest SHA-256 must match that digest and its configuration descriptor must match the authenticated release's `tested_image_id`. Only single-platform OCI/Docker schema-2 image manifests are accepted; indexes, unavailable registry evidence or missing Buildx stop deployment without a fallback. Local platform/source-label and repository-digest checks remain mandatory. Docker's local image ID may be the configuration digest (classic store) or manifest digest (containerd store); previous and replacement containers must reference the exact approved digest, match that verified local ID and carry the approved source labels. The release manifest and owner approval are not rewritten to accommodate either image store. See [Buildx raw manifest inspection](https://docs.docker.com/reference/cli/docker/buildx/imagetools/inspect/).

Requests and their evidence, previous manifest, timestamps, root-only command logs and outcomes are retained at `history/REQUEST_ID`; no cleanup/prune/retention deletion is automated. Current manifest and the image line in `compose.env` are updated only after checks. App env/secrets are preserved. Logs may contain sensitive operational details: redact before sharing and apply the approved retention/encryption policy.

## Incidents and safe rollback

A failed health/replacement check requests maintenance again and records failure. There is **no automatic rollback**. A hard kill, host crash, lost SSH connection, Docker command timeout or interrupted state update can leave uncertain state; maintenance is best effort if the container is unavailable. Block UAT admission through the separately authorized ingress procedure, inspect only UAT and verify NetBox's existing baseline through the host owner. Do not assume a cancelled/timed-out workflow means Docker stopped. Keep the local lock file/history and investigate before another request; never delete history to retry a used request.

Administrator checks root-only result/logs, actual running image, DB ID/start time/health/mounts, maintenance marker, schema, volumes and current manifest/compose digest. There are two state file replacements; a crash between them intentionally causes a subsequent mismatch/refusal. Reconcile these root-owned records to observed state under an explicit incident approval; do not blindly retry or claim a failed candidate as accepted. Preserve all evidence. No global Docker cleanup, database recreation, volume deletion, fresh migration/reset or NetBox/Nginx replacement is authorized.

For safe app rollback, owner selects the previous **published digest**, not a tag/build, and issues a **new** request/approval with `operation=rollback`, `previous_image` equal to the currently running digest, and explicit compatibility/recovery review. For an identical schema use `unchanged`. For compatible additive schema left in place, use `reviewed-applied`, supply the backup/restore/migration authorization records and an explicit `live_migrations` list of the reviewed resulting migration names (file stems, without `.php`). It must include every candidate migration and exactly match the live table. Candidate file hashes still must match the old publication manifest. This permits only an explicitly reviewed compatible app rollback; no schema is reversed.

If the previous publication artifact expired, automation refuses it. Use a separately reviewed administrator recovery procedure with preserved authentic evidence, or a reviewed forward repair; do not weaken evidence checks. If schema/data compatibility is unknown, keep maintenance/ingress closed and seek a forward fix or a separately authorized paired restore with a documented data-loss decision. Restore drills and actual restore execution remain separate from this deployment identity.

## Future Production promotion — disabled

A future workflow must have its own protected `production` environment, separate owner/XPPL acceptance approval, credentials, host enrollment, lock, project, configuration, backup/migration/recovery policy and explicitly reviewed server entry point. Require formal UAT acceptance evidence binding **exactly the UAT-deployed and accepted digest**, source and manifest hash. Promote that digest without rebuilding, retag substitution or resolving a mutable tag. Require independent Production previous-digest and schema/recovery authorization. No Production path, secret, account, switch or deploy job is enabled here; never change the UAT entry point's fixed project/paths to promote.

## Local validation and remaining prerequisites

Offline Python regressions cover shard evidence, trusted publication download, bounded request parsing, owner/previous/expiry/replay gates, schema/backup/rollback review, app-only replacement, DB restart detection and failure maintenance behavior. Compose rendering tests do not access the daemon. Shell parsing, workflow validation, Python syntax and whitespace checks accompany the change. Real UAT/network/SSH/registry/health/load/backup/restore tests are deliberately not performed in this work.

Activation still requires owner-approved repository changes, protected environment capability/settings, reviewed server/SSH/sudoers enrollment, a dedicated credential and independently verified host pin, approved runner connectivity, trusted publication evidence, actual current-state enrollment, capacity/NetBox thresholds and monitoring, paired recovery/drill evidence, migration/trigger accounts and authorization where needed, approved HTTPS/DNS/TLS/8444 and proxy settings, plus an authorized rehearsal window. No credentials should be put in Git, issues or chat.
