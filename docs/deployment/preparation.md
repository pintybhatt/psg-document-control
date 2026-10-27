# XPPL DCMS deployment preparation

27 October 2026. Local engineering only. This document and its commands are templates for a separately authorized operator. They do not authorize host access, registry publication, Phase 11 UAT execution or Production deployment. No operational server or other system was accessed.

## Verified starting point

Clean repository `/home/bipin/projects/psg-document-control`, branch `phase-10-administration-configuration`, HEAD `a3870d5` with `4844ca2` immediately preceding. Read `AGENTS.md`, the full [Phase 10 report](../security/phase-10-administration-configuration.md), existing deployment/testing assets and the Laravel/testing skills. `.ai/rules` is absent. Created local branch `deployment-preparation-ci-cd`; no history rewritten. Phases 1–10 source, migrations, authorization and integrity contracts are retained; only application change is explicit configurable proxy trust, with regression tests.

Phase 10's outstanding container restoration note remains unresolved: `dcms-mysql84-tests` was originally stopped, reportedly started in an interrupted session, and could not subsequently be inspected/stopped. This package also received Docker socket permission denied. No escalation, permission workaround or operation on that container was attempted. Business-policy deferrals in the report remain outstanding. This package does not declare unconditional Phase 10 closure or UAT readiness.

## Architecture and isolation

Release flow: DEV feature branch → reviewed PR and CI → protected main → explicitly authorized release candidate commit → private GHCR digest → manually approved UAT deployment → formal XPPL acceptance → separate Production authorization → **same digest**, without rebuilding.

`Dockerfile` has Composer, Node frontend, PHP runtime, dependency and release stages. PHP 8.5 Apache on Debian Bookworm supplies the web entry point and Laravel runtime in **one service**; a separate reverse-proxy container is unnecessary for current requirements. Apache listens on unprivileged port 8080 as UID/GID 33. Only `public/` is served. PHP adds GD (JPEG/FreeType), PDO MySQL and Zip; core XML/DOM, mbstring, curl and other locked requirements come from the official PHP build. Composer checks actual platform requirements during build. Node 22 runs `npm ci --ignore-scripts` and Vite; all built assets including Lao fonts are copied. Runtime has no Node, Composer executable, PHPUnit or source bind mount. Existing direct dependencies, including Tinker, are unchanged.

Production-style safeguards: `--no-dev`, authoritative Composer autoload, runtime configuration/route/view caching after secret injection, no image `.env` or build-time configuration cache, root-owned code, UID 33 writable directories, read-only container root, dropped capabilities and no-new-privileges. Startup never migrates or generates keys. Framework storage is persistent; bootstrap caches and temporary files are ephemeral. Final image inspection/build has **not** been possible locally.

`compose.release.yaml` contains `app` and MySQL 8.4 only. No current `ShouldQueue` jobs, queued notifications/mail delivery or scheduled tasks were found; `routes/console.php` contains only `inspire`. Use `QUEUE_CONNECTION=sync`. No Redis, worker or scheduler is included. If future features require them, separately review shared storage, graceful worker recreation on digest promotion, one scheduler per environment and overlap locks before adding services.

| Boundary | Design |
| --- | --- |
| Ingress | Ordinary project-specific bridge; app publishes only configurable host interface/port. Defaults to loopback. Explicit approved TLS proxy/ingress must reach it. |
| Backend | Project-specific internal network, app + database. MySQL has no host ports and no ingress-network connection. |
| Persistent data | Project-prefixed `database`, `documents`, `private`, `framework` volumes. No external/shared volume names. |
| Document privacy | `storage/app/document-vault`, private local disk, `serve=false`, authenticated document routes. No `storage:link`; Apache denies `/storage`. |
| Environment separation | Distinct `dcms-uat-NAME` and `dcms-production-NAME` project names, host configuration directories, DB identities/passwords, APP_KEY, cookies/cache prefixes, ports, networks, volumes and release/backup evidence. |
| Logs | Apache/PHP/Laravel stdout/stderr; Compose rotation is 10 MiB × 3 files per service. This is a bounded local log buffer, **not** approved audit retention. Protected audit tables remain in DB. |

Ingress design must be approved for the chosen topology. On a dedicated VM, a host TLS proxy can reach the loopback port. On an approved shared host, allocate a distinct port and proxy route; do not attach to existing networks or touch another application's proxy without approval. For separate VMs use separate configurations. A containerized ingress requires an explicitly reviewed route/network; its loopback is not the host loopback. No fixed host, DNS, TLS certificate, firewall rule, subnet or mandatory port is selected here.

## Configuration and secrets

Copy `docker/compose.env.example` and `docker/app.env.example` to **separate host directories outside the checkout** only after authorization. Production changes target to `production`, DB name/user and cookie/cache prefixes to Production-specific values. `APP_DEBUG=false` is enforced by Compose and entrypoint in every packaged environment. Set an approved HTTPS URL. Never use an example digest or key. Private document root is fixed to the existing Laravel disk and mapped to a separate volume; changing filesystem disk does not relocate existing attachments.

Inject APP_KEY and DB_PASSWORD via an owner-only `app.env` (0600, directory 0700); inject matching MySQL password/root-password files via Compose secrets (0600). Use an approved out-of-band random secret source; none is generated for UAT/Production here. Protect keys and previous keys with the backup recovery material. Do not rotate APP_KEY casually: encrypted sessions/data and historical recovery may depend on it. Separate environments must never share keys/passwords. Avoid secret CLI arguments, shell tracing, screenshots and printed `docker compose config` output. Docker administrators can inspect container environments; env-file injection is not an encrypted secret store. An approved external secret manager may supply the same environment at runtime. Do not commit populated files or bake them into images.

`TRUSTED_PROXIES` is a comma-separated explicit IP/CIDR allowlist, empty by default. Trust only the source addresses that the app actually sees from approved ingress. Never use `*` or an entire untrusted LAN. Proxy must strip incoming forwarding headers and set its own client/scheme/port; forwarded Host is deliberately ignored, so preserve the approved Host header. Restrict ingress accepted hostnames and direct app reachability. No proxy trust is inferred merely from APP_URL. Two HTTP regressions cover untrusted headers and trusted ingress with forged forwarded Host.

Sessions/cache use each environment's own DB; framework volume preserves maintenance state and compiled views across container recreation. Logging must stay on stderr unless another writable log destination is explicitly designed. Mail defaults to the non-delivering array transport because no current business mail workflow exists. Future SMTP use requires approved host/port/TLS/username/password, sender identity and recipient policy; Laravel's existing `config/mail.php` reads MAIL_MAILER, MAIL_SCHEME, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_*.

Default attachment limit is 25 MiB (`DCMS_MAX_UPLOAD_KB=25600`, PHP upload 25M, PHP POST 27M, Apache request 28,311,552 bytes). Coordinate the approved outer proxy limit and timeouts with these settings, allowing multipart overhead; PHP memory must also accommodate PDF/spreadsheet work. Defaults are examples, not capacity evidence. Test boundary uploads, errors, English/Lao fonts and exports in a disposable stack and later UAT.

## CI and image releases

`.github/workflows/ci.yml` is prepared for PRs, main pushes and reusable invocation at an explicit source commit. It installs locked PHP/JS dependencies, validates Composer/platform requirements, runs the full default PHP suite, full frontend suite, Vite production build, Pint check, whitespace check, dependency audits, full existing MySQL-compatible suite on a **fresh GitHub runner**, Docker build and a new isolated disposable smoke stack. Composer and npm download caches are keyed from lockfiles; vendor/node_modules are not reused as release artifacts. One Ubuntu runner avoids a redundant OS matrix. Read-only contents permission, timeout and cancellation limit exposure. Fork PRs receive no deployment credentials; never change this to `pull_request_target` with untrusted checkout.

Third-party actions are SHA-pinned to release refs verified with read-only upstream `git ls-remote`: checkout 4.2.2, setup-node 4.4.0, cache 4.2.3, setup-php 2.35.5 and upload-artifact 4.6.2. This verifies identity, not a full source security audit. Review updates periodically. Dependency policy: Composer advisories/abandoned-package failures and npm high/critical advisories block CI; lower npm severities remain visible for triage. Feed/network failures also fail closed. Exceptions require a reviewed, time-bounded mitigation; no blanket ignore/automatic dependency updates. Container OS vulnerability scanning and license inventory review remain a release gate before first publication; this package does not claim a scanned image.

`.github/templates/release-image.yml.disabled` is **not a registered workflow**. After separate publication authorization, copy/review it under workflows, configure private GHCR visibility and access, an environment named `release-image` with required reviewers and restricted branches, and `ENABLE_IMAGE_PUBLISHING=true`. Confirm reviewers are supported by the repository's current GitHub plan; if unavailable retain manual external approval and keep publishing disabled until a safe control is agreed. No paid service is mandatory.

The future manual release event requires a full reviewed source SHA, baseline release SHA and version/RC. Identity job checks main ancestry; reusable CI tests that SHA. Publishing job has narrowly scoped packages:write, logs in via stdin, builds from the source checkout, publishes a version-plus-SHA tag and records the registry digest. Version tags are descriptive; only `ghcr.io/...@sha256:...` is authoritative. No `latest` tag is used. Manifest artifact records digest, source, baseline, platform (`linux/amd64`), build timestamp/run ID, all migration paths and hashes, changes since baseline and empty acceptance/authorization fields. Initial release inventory may include all pending migrations even with baseline=source. Editing/deleting a historical migration requires explicit review and should normally be rejected.

Runtime base image defaults are supported tags, not fully reproducible locked OS inputs. Before an authorized release, review and pin PHP/Node/Composer build arguments to tested upstream digests, record their identities with build evidence, and inspect licenses/SBOM and vulnerabilities. MySQL is pinned to the existing verified 8.4.13 digest. Do not substitute architecture silently: current workflow prepares linux/amd64 only. A different architecture requires its own validated release artifact.

No new Composer/npm package was added. Proposed runtime components are commercially usable open source: PHP PHP License, Apache Apache-2.0, Debian component licenses, MySQL Community GPLv2; Node MIT and Composer MIT are build tools. GitHub actions used here are MIT-licensed projects. Preserve license notices and review redistribution obligations. Existing mPDF is GPL-2.0-only and PhpSpreadsheet MIT; do not interpret commercial use as exemption from distribution obligations. Existing font licenses/notices remain required.

Git process: short local feature branches → reviewed PR → CI-validated protected main → explicit release candidates. No permanent UAT branch needed. Later recommended settings: prohibit force pushes/deletions, require reviews and named CI checks on main, restrict bypass/admin exceptions, review workflow changes with designated owners, protect release tags, restrict registry writes and release environments, and separate UAT deploy approval, XPPL acceptance and Production approval. No GitHub setting was changed here. Never rebuild for Production promotion: archive/copy the UAT manifest with acceptance evidence and deploy its exact digest.

## Read-only preflight and deployment template

`docker/preflight.py` validates a selected `uat` or `production` plan with an explicit project, protected Compose env file and release manifest. It cannot deploy, pull, run migrations or create services. Default only renders Compose in memory, checks matching image/source identity, configuration, permissions, HTTPS and isolation assumptions without printing secrets. `--probe-local` additionally reads the **local Unix Docker daemon only**, verifies the already-present image/source label, reports free memory/disk, checks current DB health/storage paths and lists current migration status. It does not access remote Docker contexts. Current migration status must be compared with candidate inventory; it does not establish compatibility. It does not authenticate approval evidence.

Future invocation, only in an approved environment:

```sh
python3 docker/preflight.py --target uat --project dcms-uat-APPROVEDNAME \
  --compose-env /APPROVED/UAT/compose.env --manifest /APPROVED/UAT/release-manifest.json
# Add --probe-local only after host access is separately approved.
```

Do not execute the following operator template during this work package. There is deliberately no one-command deployment engine. Keep an environment-specific protected evidence directory holding manifest, previous manifest/digest, approval reference, backup IDs, migration logs and outcomes, UTC times and operator. Set no implicit default target. Never loop across environments.

1. Confirm written host/environment/release authorization; reject the prohibited operational host unless separately authorized in a later task. Confirm project, absolute paths, image digest/source, approved migration inventory and acceptance scope. Production additionally requires XPPL UAT acceptance tied to that digest and separate Production authorization.
2. Acquire and hold an exclusive host-side lock for **this project** throughout backup, migration, replacement and verification (`flock -n /APPROVED/ENV/deployment.lock`). A single operator shell must retain the descriptor; do not release between steps. On a shared host also coordinate capacity/ingress changes across owners. Record authorization and target before any mutation.
3. Check Docker/Compose versions, available disk/memory against approved sizing, filesystem capacity/inodes, volume ownership UID 33, mount identity and backup space; verify both ingress and DB readiness. Account for old/new images, MySQL working set, uploads, export memory and concurrent workloads. Preflight reports are evidence, not sizing approval.
4. Preserve previous digest/configuration and inspect rollback compatibility. Explicitly pull only the approved digest after registry read authorization. Verify RepoDigests, OCI source label and candidate manifest inventory/hashes. Use preflight with `--probe-local` after pulling. If the app is absent, follow initial-bootstrap branch below. No tag substitution.
5. Quiesce all writers/uploads at ingress and use `php artisan down --no-interaction` in the current app. Keep the framework volume and maintenance marker across replacement. Wait for in-flight uploads/transactions; no worker exists currently. Keep writers blocked if any step fails.
6. Take a paired consistent DB/document backup and record its verified recovery decision. An untested/unavailable recovery path blocks deployment. Do not assume MySQL DDL is transactionally reversible.
7. As a **distinct approved migration step**, use the candidate digest in a one-off `app` container with controlled migration credentials. First list status and review `migrate --pretend` output against the current schema (pretend is review, not a safety guarantee). Review triggers, definers and grants with DB owner. Then explicitly run `php artisan migrate --force --no-interaction`, capture protected output/exit status, and list status again. Do not use normal root credentials for serving requests. No automatic migration runs on startup.
8. Only after successful migrations, replace this project's app with the candidate digest (`compose up -d --no-deps --force-recreate app`, using the explicitly selected env file/project and `compose.release.yaml`). Keep MySQL image upgrades a separately reviewed operation; do not inadvertently recreate DB. Check container health, source label, DB connectivity, pending migrations and private mounts. Execute `php artisan up --no-interaction` after internal verification, then verify approved HTTPS ingress, login, English/Lao UI, exports and synthetic permitted attachment access/denial. Compare authorized attachment metadata/hashes in acceptance checks.
9. Record health evidence, digest, migration result, backup identity, operator and time. Monitor error rate, latency, capacity, authentication and failed DB operations. Reopen ingress only after required checks. A failed health check does not trigger schema rollback or automatic maintenance exit. Keep evidence and escalate for a controlled recovery decision.

In the steps above, `compose` means `docker --host unix:///var/run/docker.sock compose --project-name EXPLICIT_TARGET_PROJECT --env-file /ABSOLUTE/ENV/compose.env -f /ABSOLUTE/CHECKOUT/compose.release.yaml`. It is notation, not an installed wrapper. Review every command's target before running. Authorization is organizational approval, not merely typing a flag.

Initial bootstrap is a separate authorized operation: empty/new project resources, no shared data, root/password custody, MySQL health, migration-role provisioning and trigger privileges, paired recovery plan, candidate migration run, then app start/health and controlled account/organization initialization. No DEV/UAT database, demo seed or document copy into Production. MySQL image entrypoint grants its initial application user schema-level DDL: before operational use, a DBA must separate migration and runtime accounts and reduce runtime grants to approved DML/required trigger execution. Existing trigger creation with binary logging may need privileged migration identity or approved `log_bin_trust_function_creators` policy. This template does not silently enable that server policy. Existing MySQL regression settings are not Production defaults.

## Migration and rollback policy

Existing migration files remain byte-for-byte unchanged. Issued numbers, atomic counters, released revisions, attachment versions/hashes, custody history, audit history and organization/scope protections stay under existing application and DB constraints. Inventory target status, server version/collation/SQL mode, triggers/definers and migration-account rights before applying changes. Preserve UTC application dates and existing Lao display handling.

Use expand/contract schema evolution: add compatible nullable structures, release readers/writers safely, backfill through a reviewed procedure, remove obsolete structures only in a later approved release. Document the compatibility interval for every release. MySQL DDL may partially commit before failure; record exactly what completed and diagnose before retry. Never use `migrate:fresh`, destructive reset, database replacement or automatic `migrate:rollback` as deployment recovery.

Application rollback can restore the **previous digest** only if that version supports the resulting schema and data. Keep maintenance and ingress controls, select the previous manifest/config, replace app alone and reverify. If incompatible, use a reviewed forward repair or a separately approved paired restore with an explicit data-loss decision. Do not invent reversibility. Do not delete persistent volumes when replacing or stopping the stack. Environment-specific DB restore credentials and registry read access must be available before the maintenance window.

## Storage, backup and recovery runbook

App replacement preserves `documents` and `private` volumes and their metadata DB. Never expose these paths through Apache/proxy aliases. File/database consistency matters: a database dump alone cannot recover document binaries; a file archive alone cannot reconstruct authorization/history. Treat metadata hashes/version relations and append-only audit/custody histories as recovery contracts.

For an approved backup window: block writers/uploads, drain in-flight operations, enter maintenance, record release/schema identity, take an InnoDB consistent snapshot/dump including triggers (and any routines/events in use) with a purpose-limited backup account, and capture the matching document/private volumes while writes remain blocked. Check command status, sizes and cryptographic archive checksums; record a shared backup set ID and UTC cut-off. Do not put passwords in command arguments; use protected client option files or approved secret injection. Physical DB backups require a MySQL-compatible tool and procedure; copying a live `/var/lib/mysql` directory is not a backup method.

Encrypt in transit and at rest, store off-host under separate access controls, preserve keys/config/digest/manifest and restore instructions with restricted recovery custody. Establish approved RPO/RTO, storage location, retention and legal-hold rules with XPPL; **no schedule or deletion policy is implemented here**. Test restoration in a fresh isolated environment: restore DB plus matching document set, verify triggers/grants/foreign keys, migration status, version rows, byte counts and attachment hashes, access denials and authorized retrieval, number immutability/counter monotonicity, revision/custody/audit continuity and bilingual output. Record actual recovery time and gaps. Never run restoration tests against existing DEV/UAT/Production data, and never copy UAT data into Production. Production recovery is a separate authorization.

`/up` tests Laravel boot only, not database or attachment readiness. MySQL health executes authenticated SELECT 1. Operational readiness also needs application DB connectivity (`migrate:status`), mounts, upload/retrieval authorization, approved external HTTPS path and error monitoring. Docker does not automatically restart a merely unhealthy running container; monitoring and operator response remain to be selected. Read logs with bounded `compose logs --since ...`; redact document content/credentials, restrict access and forward off-host only after approval. Use source label and manifest for version identification; no public version endpoint added.

## Local verification and remaining decisions

Actual verification results are recorded in [the local completion report](completion.md). Docker build/runtime, disposable MySQL/container smoke, image layer inspection and shutdown isolation remain **unverified** due to socket permission denial. `docker/smoke.sh` is opt-in (`DCMS_DISPOSABLE_SMOKE=1`), local Unix daemon only, fresh random project/temporary secrets/ephemeral published port, with cleanup confined to its own resources. It checks migration/connectivity, non-root runtime, absence of environment/dev artifacts, health, private path denial, document persistence through app recreation and shutdown. It disables binary logging only for its disposable database, so it cannot prove operational trigger-definer/binlog compatibility. It has not been run locally. Do not use it on an operational Docker host. CI's existing MySQL harness runs only on fresh GitHub-hosted runners and must not be executed against this workstation's existing regression infrastructure.

Outstanding approvals/decisions:

- GitHub repository ownership/access, protected main/release controls and workflow reviewers.
- Private GHCR visibility/read/write permissions, release publishing authority and vulnerability/license policy.
- Final UAT hosting topology and final Production topology: dedicated VM, separate VMs or explicitly approved shared host.
- VM capacity, architecture, resource budgets, load/large-file tests and monitoring ownership.
- DNS/TLS owner, ingress source addresses, port/interface allocation and firewall/network approval.
- Backup location/encryption custody, RPO/RTO, retention/legal holds and successful restore evidence.
- Secret-management owner, migration/runtime/backup DB accounts and trigger/binary-log policy.
- UAT deployment approvers, formal XPPL UAT acceptance owners and separate Production release approvers.
- Previous Phase 10 container-restoration confirmation and remaining business-policy/native Lao review gates.

No publication, push, PR, merge, UAT/Production activation, server access, live migration, operational secret, DNS/TLS/firewall or other-system change is part of this package.

References used for preparation: [official PHP image source](https://github.com/docker-library/php/blob/master/8.5/bookworm/apache/Dockerfile), [Docker PHP container guide](https://docs.docker.com/guides/php/), [GitHub workflow triggers](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/trigger-a-workflow), and installed-version Boost documentation for Laravel deployment, maintenance mode and trusted proxies. Action release revisions were checked directly against their upstream repositories. These references do not substitute for testing the actual built artifact.

## Local access prerequisite confirmed in verification continuation

At continuation checkpoint `069919c`, the existing local Engine service was active, context `default` selected `unix:///var/run/docker.sock`, and Docker/Compose CLIs were available. Outside sandbox isolation the socket is `root:docker` mode `0660`, and user `bipin` is not in the Docker group. Approved sandbox escalation still received socket permission denied; the approved `sudo -n` read-only probe required interactive authentication. This is a local authorization blocker, not evidence of a remote-context or stopped-daemon problem. Do not install Docker Desktop, switch endpoints, alter the socket/groups or use privilege workarounds to bypass it.

An administrator-authenticated local execution facility must be arranged before resuming Docker verification. No password should be sent through chat. Start resumed work with read-only inventory and verification of the old `dcms-mysql84-tests` state; its final state remains unknown. No Docker mutation or disposable run took place in this continuation. Image build/runtime and recovery evidence remain outstanding as detailed in [completion.md](completion.md). Before executing smoke, also confirm caller-provided DCMS/Compose environment variables cannot override its generated isolated settings. This prerequisite does not authorize any UAT/Production access or deployment.

## Hardened disposable smoke and administrator execution handoff

The administrator has reported successful local Docker 29.9.2/API 1.56/Compose 5.6.1 checks on Ubuntu 26.04 WSL2 AMD64, and `dcms-mysql84-tests` restored to `exited 0`, with storage intact. This user-confirmed observation resolves the earlier restoration report; Codex has not independently inspected it. Codex still cannot authenticate interactively. Do not restart or modify that container. The commands below are **for review only** and require separate confirmation before the administrator runs the build or smoke.

`smoke.sh` now launches the standard-library `smoke.py` driver. A Python driver makes environment, subprocess and resource-identity checks testable without a shell cleanup framework. It does not call `preflight.py`. The release Compose architecture, application and entrypoint remain unchanged.

- Both execution opt-in (`DCMS_DISPOSABLE_SMOKE=1`) and disposable-deletion consent (`DCMS_SMOKE_CLEANUP=1`) are required for a real run. `--plan` only renders configuration and does not need deletion consent or daemon access.
- Nonlocal DOCKER_HOST, non-default DOCKER_CONTEXT, supplied DOCKER_CONFIG and Docker TLS overrides are rejected before invoking Docker. The launcher uses isolated Python mode; every Docker invocation uses the reviewed `/usr/bin/docker` binary, approved Unix host and a fresh private, empty client-config directory. No privilege escalation, context fallback or registry login exists in the driver.
- Docker/Compose/DCMS and app/database selector environment variables are controlled in child processes, including future variables under those prefixes. Ordinary PATH, HOME, locale, proxy and certificate variables remain available. Caller Compose files/profiles/env files/project names cannot enter the generated configuration.
- Input is an existing local `sha256:` image ID; tags, registry names, options and newline injection are refused. Application pull policy is `never`; only the pinned public MySQL image may be downloaded if absent. Synthetic DB/key files are mode 0600 in a mode 0700 directory, with fixed `dcms_smoke` database/user and `DB_HOST=db`. Ingress is explicitly loopback with an ephemeral port. No host data-directory bind mounts, shared/external names or caller volume overrides are allowed.
- UUID project names and run labels cannot be supplied by callers. Before creation the driver inventories existing resource IDs/names and refuses namespace collisions. Cleanup first inspects **all** candidate containers/networks/volumes: they must be absent from the baseline, match both project and run labels, and match expected names/service identities. Only those exact IDs/names can be removed. No `compose down`, `--remove-orphans`, image deletion or prune command is used. It verifies the project has no remaining resources afterward. A failed identity check refuses deletion and retains evidence for review.
- App recreation uses `--no-deps`, preserving the disposable DB container. Failure/interrupt returns nonzero, collects bounded redacted synthetic logs, attempts only approved cleanup, and retains private summary/log evidence. If cleanup fails, protected synthetic configuration is retained for diagnosis; never paste the whole directory. Successful cleanup removes credential/env files while retaining evidence. SIGKILL/host failure cannot be trapped; do not issue broad cleanup if interrupted that way.

These controls are not a defense against a malicious Docker administrator concurrently tampering with resources. Run the test without another operator manipulating its random project. Container boot, permissions, health and real Docker cleanup remain pending actual execution.

Run these **separate reviewed blocks in the same Bash terminal**, only after confirmation. Stop on any failed command. The build uses a fresh evidence/client-config directory and no tag, so it cannot overwrite an existing image tag. `local_docker` uses interactive sudo in the administrator terminal, an explicit Unix endpoint and a minimal reviewed CLI environment. It does not depend on Codex inheriting `sudo -v`. If approved network proxy/certificate settings are needed for the build, review their injection separately; do not change endpoint controls.

1. Prepare local evidence and read-only confirmation. Creates local `/tmp` directories/files only; no downloads or Docker mutations. Expected existing container output: `/dcms-mysql84-tests exited 0`.

```bash
cd /home/bipin/projects/psg-document-control
umask 077
set -o pipefail
verification_dir="$(mktemp -d /tmp/dcms-docker-review-XXXXXXXX)"
mkdir "$verification_dir/docker-config"
source_commit="$(git rev-parse HEAD)"
printf '%s\n' "$source_commit" > "$verification_dir/source-commit"
printf 'Evidence directory: %s\n' "$verification_dir"
local_docker() {
  sudo env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    /usr/bin/docker --host unix:///var/run/docker.sock \
    --config "$verification_dir/docker-config" "$@"
}
local_docker version
local_docker inspect dcms-mysql84-tests \
  --format '{{.Name}} {{.State.Status}} {{.State.ExitCode}}'
git status --short
```

2. Build the current clean source. Downloads missing PHP/Node/Composer base images, APT packages, locked Composer/npm dependencies and build font assets as needed. Creates a local image and build cache; does not publish, start containers or delete existing resources. Expected exit code 0 and a `sha256:` image ID in `image-id`. The read-only inspect records size and source label; registry RepoDigests may be empty for an unpublished local build.

```bash
local_docker build --build-arg SOURCE_COMMIT="$source_commit" \
  --iidfile "$verification_dir/image-id" . 2>&1 | tee "$verification_dir/build.log"
image_id="$(cat "$verification_dir/image-id")"
local_docker image inspect "$image_id" \
  --format 'ID={{.Id}} bytes={{.Size}} source={{index .Config.Labels "org.opencontainers.image.revision"}} digests={{json .RepoDigests}}'
```

3. Execute smoke **only after approving both resource creation and scoped deletion**. This creates fresh app/DB/one-off migration containers, two networks, four named volumes and synthetic temporary files. It may download the pinned MySQL image, never the application image. It migrates only its newly created synthetic database, tests health/private-path denial/persistence, recreates the disposable app and stops the stack. The explicit cleanup consent authorizes removal of only new resources that pass the ownership checks. It does not remove images/build cache or the protected regression container. Expected exit 0, `Result: passed; cleanup: verified-empty` and a private summary path. A failure must remain a failure even if cleanup succeeds.

```bash
sudo env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
  DCMS_DISPOSABLE_SMOKE=1 DCMS_SMOKE_CLEANUP=1 \
  /bin/sh docker/smoke.sh "$image_id" 2>&1 | tee "$verification_dir/smoke.log"
smoke_status=${PIPESTATUS[0]}
printf 'Smoke exit status: %s\n' "$smoke_status"
test "$smoke_status" -eq 0
```

4. Inspect evidence and verify cleanup without modifying Docker resources. These commands do not download or delete anything. Expected summary is `passed` / `verified-empty`; the three project lists are empty; the original container still reports `exited 0`. On failure, review the private summary/log and stop for guidance; do not delete resources by broad prefix or rerun with weaker checks.

```bash
smoke_summary="$(sed -n 's/^Smoke evidence: //p' "$verification_dir/smoke.log" | tail -n 1)"
case "$smoke_summary" in
  /tmp/dcms-smoke-*/summary.json) ;;
  *) printf '%s\n' 'No valid smoke summary; stop and review the log.'; false ;;
esac
sudo cat "$smoke_summary"
smoke_project="$(sudo python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["project"])' "$smoke_summary")"
[[ "$smoke_project" =~ ^dcms-smoke-[a-f0-9]{32}$ ]]
local_docker container ls --all --filter "label=com.docker.compose.project=$smoke_project"
local_docker network ls --filter "label=com.docker.compose.project=$smoke_project"
local_docker volume ls --filter "label=com.docker.compose.project=$smoke_project"
local_docker inspect dcms-mysql84-tests \
  --format '{{.Name}} {{.State.Status}} {{.State.ExitCode}}'
```

Provide the build result, image inspect output, smoke exit/status summary, project-list outputs and protected-container state for review. Runtime testing stays pending until these results are supplied. Neither local success nor these commands authorize Phase 11, UAT or Production.
