# GitHub Actions UAT deployment (prepared, disabled)

This procedure implements **Deploy DCMS UAT** for an already initialized XPPL UAT stack. It is local preparation, not authorization to enroll a host or execute deployment. No host, GitHub setting, credential or image was changed. Production is disabled: there is no Production workflow, environment, credential or entry point in this change.

This simplified OIDC flow supersedes the earlier per-release root-enrolled request/required-reviewer design and the older manual-only automation discussion in [uat.md](uat.md) and [releases.md](releases.md). Their initial bootstrap, DBA provisioning, backup/restore, ingress and shared-host isolation procedures remain applicable. Automation never initializes a database. Preserve the independently reviewed `docker/apache-uat.conf` worker configuration; this change does not alter it or either Compose file.

## Trust and authorization

An authorized repository operator selects an existing successful publication run and its release-manifest artifact in **Deploy DCMS UAT**. There is no image input, separate reviewer workflow, per-release root approval or uploaded request bundle. Publication authorization in `release-image.yml` is unchanged. The dedicated `dcms-uat-runner` runs only the main-only manual deployment job.

The job reads authenticated GitHub publication evidence, derives the immutable DCMS digest from that manifest, and requests a short-lived GitHub OIDC token. Its audience includes the SHA-256 of the complete canonical deployment request: deployment run/attempt, release run/attempt/artifact, manifest hash, image, operation and rollback review reference. Changing any field invalidates authorization. `id-token: write` permits minting this OIDC token; the job otherwise has only `contents: read` and `actions: read`.

The server verifies RS256 with OpenSSL and the current keys from the fixed HTTPS GitHub OIDC JWKS endpoint. It accepts only the pinned repository ID/name, private repository, manual dispatch, main ref, exact deployment workflow path/revision, `uat` environment subject, allowed actor ID, self-hosted context and exact run/attempt. It rejects untrusted JWT algorithms/key URLs, invalid signatures, duplicate JSON keys, future/expired tokens and tokens issued more than five minutes ago. No user-supplied identity string, SSH key alone or GitHub read token alone authorizes a deployment. See [GitHub's OIDC identity claims](https://docs.github.com/en/actions/reference/security/oidc).

The job's transient read-only `GITHUB_TOKEN` is sent with the OIDC token on pinned SSH stdin so the server can independently retrieve GitHub evidence. Tokens are never command arguments or deployment history. The runner envelope is a private temporary file, removed on completion/failure; root holds credentials only in memory, with signature/public-key verification files in a private temporary directory. Docker/app subprocesses receive a cleared environment without either token. Root/runner administrators can access process memory and are trusted. No new persistent GitHub API credential is required on UAT; the existing root-managed GHCR reader remains separate.

The server checks the repository's live main `protected` flag and requires the deployment workflow SHA to equal current main. It verifies the actual running job's signed check-run identity, current attempt, allowed runner ID and dedicated labels against authenticated GitHub API data. The original actor and rerun initiator must be authorized. It fetches the manifest and all job/artifact pages directly from GitHub, requires successful publication/full gates/exact-image smoke, rejects expired artifacts/reruns and enforces the root-configured release age. Sharded MySQL evidence must include all four successful jobs; valid historical single-job evidence remains understood. Candidate source and release-workflow revisions must belong to main ancestry. A normal deployment cannot downgrade source.

The existing root-only current manifest and running app must agree. Schema inventories must be identical and the live migration table must exactly match. A root-only lock serializes all mutations; `history/gha-RUN-ATTEMPT` is reserved once and cannot be replayed even with a newly minted token. GitHub concurrency also serializes dispatch. Policy/main/job/release/artifact freshness are rechecked during deployment. OIDC is checked at admission; that authorization covers one bounded 15-minute server operation, not an indefinitely renewable session. API failure, policy change, cancellation, removed evidence or stale main stops progress. No anonymous/public-package fallback exists.

SSH still accepts only the literal `deploy-dcms-uat`, with a bounded JSON envelope on stdin. It cannot select shell commands, paths, Compose projects, targets or migration commands. The account has no shell/scp/sftp/forwarding access and no Docker-group/socket privileges. The fixed sudo wrapper and all its helpers remain root-owned. Production is never an accepted target.

## One-time GitHub and runner setup — future authorized administration

1. Review/merge the tooling onto protected `main` only after separate Git approval. Enforce PR/workflow-change review, prohibit force pushes and tightly limit bypass actors. If protected-main enforcement is unavailable, **do not enable this design**; the server will refuse an unprotected branch. A protected flag alone does not prove that branch rules or administrator behavior are adequate; repository ownership and rules require administrative review.
2. Keep the `uat` environment for scoped secrets and main-only deployment branch restrictions. Required environment reviewers are **not required or assumed available**. Dispatch by an allowed operator is the UAT authorization. Do not configure a separate approval workflow. No PR job may access UAT credentials or OIDC authority for this workflow.
3. Keep the existing dedicated, restricted runner `[self-hosted, Linux, X64, dcms-uat-runner]`. Pin its numeric runner ID in the server policy. Restrict its runner group/repository access and workflows where supported, keep its OS/account patched, and ensure it never executes PRs, fork code, unrelated workflows or builds. Prefer an isolated disposable runner. A persistent runner compromise can steal a valid authorized job's credentials; neither OIDC nor labels make a compromised runner safe. Inability to isolate/trust this runner is an activation blocker.
4. Environment secrets remain `UAT_SSH_KEY` (dedicated UAT key) and `UAT_KNOWN_HOSTS` (complete host-owner-verified known-hosts entry). Environment variables remain `UAT_HOST` and explicit `UAT_SSH_PORT`. There are no new persistent secrets. Verify host keys out of band; never automatically trust `ssh-keyscan`. The username is fixed to `dcms-deploy`.
5. The server needs outbound verified HTTPS to `api.github.com`, `token.actions.githubusercontent.com`, GitHub's signed Azure/Actions artifact download hosts and GHCR. Metadata/OIDC redirects are refused. Artifact redirects are accepted only from the authenticated API to approved HTTPS artifact-host suffixes, without forwarding Authorization. Unknown download hosts fail closed pending a code review. Install root-trusted OpenSSL and Docker Buildx; no new Python package is required.
6. Keep **repository variable `ENABLE_UAT_DEPLOYMENT=false`** and server **`automation.json` `enabled=false`** throughout this work/review. Neither live value is read or changed by this task. Only a separately authorized rollout may enable both after policy enrollment and a controlled rehearsal. Naming the environment or merging this code does not activate deployment. Changing server policy during an operation causes the next verification to stop; disabling the repository variable prevents new jobs but is not guaranteed to stop an active process.

Keep the existing package reader limited to `read:packages` and package Read access, root-owned `/srv/dcms-uat/registry/config.json` mode 0600; follow [registry custody/rotation](releases.md#registry-retrieval-choose-a-dedicated-host-read-credential). Do not give the deployment account these credentials. The pinned SSH client uses one identity, strict host checking, no agent/password, no PTY or forwarding. Temporary credentials are cleaned in traps and an always-run final cleanup; isolate and sanitize a persistent runner after hard termination. Rotate/revoke any suspected key or runtime-token exposure and disable deployment while investigating.

## Server enrollment — design only, do not execute during this task

A host administrator must authorize and review enrollment separately. Follow the existing UAT inventory/capacity/NetBox baseline and initial bootstrap runbook first. This automation requires healthy existing UAT MySQL and an existing app; a missing stack stops. No `up db`, database image pull, volume creation/deletion, Docker cleanup, global Nginx change or NetBox command exists in the entry point.

Install the reviewed bundle under root-owned `/srv/dcms-uat/release`:

- `compose.release.yaml`, `compose.uat.yaml` unchanged;
- `docker/apache-uat.conf` unchanged, readable/traversable by UID 33 as already documented;
- `docker/preflight.py`, `docker/verify-uat-release.py`, `docker/github-uat-auth.py`, `docker/deploy-uat.py`.

All parents and executable/config files must be root-owned, without symlinks or group/other write permission. Release paths may be 0755 directories / 0644 files for the Apache bind mount. Keep `config`, `registry`, `history`, `backups` at 0700 and their confidential files at 0600. Do not grant `dcms-deploy` traversal of private directories. No writable source checkout is used on the host.

Administrator installation mapping (review against an approved bundle/checksums; no automatic installer is provided):

| Repository source | Installed root-owned path / mode |
| --- | --- |
| `docker/uat-ssh-command.sh` | `/usr/local/libexec/dcms-uat-ssh` / 0755 |
| `docker/uat-deploy-entrypoint.sh` | `/usr/local/sbin/dcms-uat-deploy` / 0755 |
| `docker/deploy-uat.py` | `/srv/dcms-uat/release/docker/deploy-uat.py` / 0644 |
| `docker/uat-automation.example.json` | `/srv/dcms-uat/config/automation.json` / 0600, completed by owner |

Provision `/srv/dcms-uat/deployment.lock` once as a root-owned regular 0600 file. Keep it permanently; deleting/replacing a live lock can defeat locking. `current.json` at `config/current.json` must be the actual last successfully deployed release manifest, validated against the running digest/tested ID, schema and volumes. Existing `compose.env` must reference that same digest and exactly `/srv/dcms-uat/config/app.env`, `db-password`, and `db-root-password`. Enrollment preserves the existing APP_KEY, DB users/accounts, documents/private/framework/database volumes and UAT settings. Do not copy example secrets over them.

Complete `automation.json` **once**, under separately authorized administration: retain `enabled=false`, target `uat`, project `dcms-uat-xppl` and authorization `github-oidc`. Pin the immutable numeric `repository_id`, allowed numeric GitHub user `operator_ids`, and approved numeric `runner_ids` (obtain through authenticated GitHub administration, not names supplied by dispatch). Set `maximum_release_age_hours` to the approved positive limit, at most 8,760 hours; the example uses 168 hours. Record `recovery_reference` for the maintained, tested paired backup/restoration procedure. Set the real `https://APPROVED_HOST:8444/up` URL and positive `minimum_free_memory_mib` / `minimum_free_disk_bytes` thresholds, covering Docker and `/srv/dcms-uat` storage, image pull/retention and NetBox headroom. Empty IDs, null placeholders and old `owners`-only policies block authorization. Policy changes are one-time/access-policy administration, not per-release enrollment. No script fills these values or enables the server automatically.

An upgrade must install the new helper and static policy together; there is no legacy approval-bundle fallback. Existing root request directories may remain archived but are not read. `config/previous.json`, when present, is the exact manifest for the last successful release replaced by automation; initialize it only from authenticated, observed history under administrator control. It is maintained automatically afterward. Leave it absent rather than inventing a rollback target. Existing APP_KEY, database accounts/credentials, storage and Apache settings are preserved. The automation uses only the local Unix Docker socket and a cleared environment.

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

## Release and schema eligibility

No per-release server files, approval.json, request directory, handwritten digest or owner enrollment are needed. Select IDs from an existing **Authorized release image** run: it must be completed/successful on main, with an unexpired manifest artifact for its current attempt. A push that succeeded inside an otherwise failed workflow is ineligible. Artifacts are retrieved by exact ID and must contain only `release-manifest.json`; manifests are not extracted into arbitrary filesystem paths. The server authenticates GitHub API evidence itself and saves the originals in deployment history.

There is no rebuild, mutable-tag resolution or arbitrary GHCR image input. The release's immutable digest, tested configuration digest, source labels, platform, migration file hashes and exact-image smoke remain mandatory. Replaced/tagged packages or a supplied digest alone cannot authorize deployment. Stale workflow revisions require a new dispatch from current main. Old/expired releases require a fresh authorized publication with retained evidence; never loosen checks to reuse unavailable evidence.

Automated UAT deployment requires **the same complete migration inventory/hashes as current UAT**, and an exact matching live migrations table. A new/changed/removed/pending/unexpected migration stops deployment and reports that separate controlled migration/recovery is required. The tool never runs migrations, changes schema, seeds users, resets a database, grants privileges or reverses schema. This applies to rollback as well as forward deployment, even for claimed additive compatibility.

Schema work remains a separately authorized administrator/DBA window using the established [manual runbook](uat.md): quiesce writers; verify paired database/document/private/config/APP_KEY backups, off-host recovery and restore drill; review exact migration files/privileges/triggers, compatibility and restoration plan; authorize and apply only that controlled change. Leave maintenance/ingress closed on failure. After controlled schema/application work, an administrator must reconcile authenticated current/previous manifests, actual runtime and live schema before ordinary app-only automation resumes. Do not edit inventories to bypass a mismatch. Recovery procedures and ongoing backup readiness remain mandatory; the static `recovery_reference` records the established procedure, not proof that a particular backup succeeded.

## Run workflow

1. In GitHub Actions open **Deploy DCMS UAT** → **Run workflow**, selecting **main**.
2. Enter the successful publication's `release_run_id` and its `release_artifact_id`. Leave operation **deploy** and the rollback reference blank for a normal release. There is no request ID or image digest to type.
3. Click **Run workflow**. No separate reviewer approval or root enrollment step follows. Your numeric GitHub user ID must be allowed by the server policy. The workflow derives the digest and requests OIDC; the server verifies identity and independently authenticates the release before any Docker operation.
4. Read the Actions result. Technical success still requires representative HTTPS login/secure-cookie, English/Lao, permitted/denied synthetic attachment, numbering/export/audit checks and formal XPPL acceptance. A green deployment is not formal UAT acceptance. Submit one dispatch at a time and check run status; GitHub can replace an older pending run when another is queued.

The fixed engine validates configuration/isolation, approved capacity, current healthy DB, previous app identity, persistent volume names/labels and Apache bind mount. It pulls **only** the selected app digest, verifies its tested image ID/OCI source/platform, and probes migration hashes/live status without executing migrations. It enters maintenance, replaces only `app` with `compose up -d --no-deps --no-build --pull never --force-recreate app`, verifies unchanged app mounts and DB container ID/start time/mounts/health, Apache config, writable private paths, DB status and schema again. It exits maintenance only after those checks, requires loopback and approved HTTPS `/up` HTTP 200 without redirects/insecure TLS, public `/storage/...` HTTP 403, and Docker app health. See [Compose app-only dependency behavior](https://docs.docker.com/reference/cli/docker/compose/up/).

Image identity checks require the Docker Buildx plugin in the root execution environment. Using the existing protected `/srv/dcms-uat/registry` authentication, `buildx imagetools inspect --raw` retrieves only the approved immutable digest; the raw manifest SHA-256 must match that digest and its configuration descriptor must match the authenticated release's `tested_image_id`. Only single-platform OCI/Docker schema-2 image manifests are accepted; indexes, unavailable registry evidence or missing Buildx stop deployment without a fallback. Local platform/source-label and repository-digest checks remain mandatory. Docker's local image ID may be the configuration digest (classic store) or manifest digest (containerd store); previous and replacement containers must reference the exact approved digest, match that verified local ID and carry the approved source labels. The authenticated release manifest is never rewritten to accommodate either image store. See [Buildx raw manifest inspection](https://docs.docker.com/reference/cli/docker/buildx/imagetools/inspect/).

Public request metadata, verified OIDC claims (without signature/token), authenticated release evidence, static policy/recovery reference, previous manifest, timestamps, root-only command logs and outcomes are retained at `history/gha-RUN-ATTEMPT`; no cleanup/prune/retention deletion is automated. Current manifest and the image line in `compose.env` are updated only after checks. App env/secrets are preserved. Logs may contain sensitive operational details: redact before sharing and apply the approved retention/encryption policy.

## Incidents and safe rollback

A failed health/replacement check requests maintenance again and records failure. There is **no automatic rollback**. Before requesting maintenance, the server writes root-owned `config/recovery-required.json`; only a completely successful deployment clears it. A failed/interrupted maintenance/replacement or partially updated state leaves that flag, blocking further dispatches until an administrator inspects and reconciles the incident. This is incident recovery, not a per-release approval gate.

A hard kill, host crash, lost SSH connection or Docker timeout can leave uncertain state; maintenance is best effort if the app is unavailable. Do not assume cancellation means Docker stopped. Close UAT admission through the separately authorized ingress procedure, inspect only UAT, and check NetBox's existing baseline with its host owner. Preserve the lock/history and incident flag. Never delete history to retry a used run/attempt.

Administrator checks the failed run's root-only logs/result, actual running image, DB identity/start time/health/mounts, maintenance marker, schema, volumes and current/previous manifests/Compose digest. State updates use separate file replacements; interruption can leave a mismatch, intentionally stopping another deployment. Reconcile these records with authentic observed state under incident authorization. Clear `recovery-required.json` only after documenting the resolution and safe next action. A fresh dispatch/attempt gets a new history ID; it does not repair unknown state. No global Docker cleanup, schema reset, volume deletion or NetBox/Nginx replacement is authorized.

For an eligible app rollback, select **rollback** and the recorded previous publication's run/artifact IDs. Supply `rollback_compatibility_reference` identifying the operator's review confirming that the previous app supports the current data and unchanged schema. This is an explicit authenticated operator attestation, not software proof of data compatibility. OIDC binds it to the exact request. The server requires the candidate manifest to equal protected `config/previous.json`, identical migration inventories and exact live schema, along with all normal publication/freshness/OCI/health checks. It does not permit an arbitrary old release, source downgrade through normal deploy, or rollback across schema changes. Restore/forward-repair authorization remains separate.

An expired previous artifact, stale publication, missing compatibility review, schema/data incompatibility or unknown runtime blocks automated rollback. Keep maintenance/ingress closed and use a separately reviewed administrator recovery, compatible forward fix or paired restore with an explicit data-loss decision. Do not disable verification as a recovery shortcut.

## Future Production promotion — disabled

A future workflow must have its own protected `production` environment, separate owner/XPPL acceptance approval, credentials, host enrollment, lock, project, configuration, backup/migration/recovery policy and explicitly reviewed server entry point. Require formal UAT acceptance evidence binding **exactly the UAT-deployed and accepted digest**, source and manifest hash. Promote that digest without rebuilding, retag substitution or resolving a mutable tag. Require independent Production previous-digest and schema/recovery authorization. No Production path, secret, account, switch or deploy job is enabled here; never change the UAT entry point's fixed project/paths to promote.

## Local validation and remaining prerequisites

Offline tests verify real OpenSSL RS256 signatures using ephemeral synthetic test keys and mocked HTTPS, identity/actor/runner/main/job rejection, authenticated release selection, stale evidence, request binding/replay, schema mismatch, rollback restrictions, OCI/containerd identity and failure recovery. They do not use live credentials or contact GitHub/UAT. Existing release-policy and Compose rendering tests remain applicable. Actionlint uses the existing custom runner label; syntax, credential-persistence and whitespace checks accompany the change.

No live server, GitHub setting/secret, runner or variable is changed. Required before separately authorized activation: enforceable protected main and trusted workflow changes; isolated/restricted runner and pinned numeric IDs; static disabled server policy/new helper installed under root custody; approved SSH pin/key/network access; server HTTPS egress/OpenSSL/Buildx and synchronized UTC clock; authentic current-state enrollment; capacity/NetBox thresholds; maintained tested recovery procedure; and a controlled identity/runtime rehearsal. If any of these controls cannot be established, keep both deployment switches false and treat that as a blocker. Production promotion still needs separate implementation and authorization.
