# Local deployment-preparation completion report

27 October 2026. **Local preparation complete within the verification limits below; no UAT/Production readiness or deployment approval claimed.** Operational instructions and open decisions are in [preparation.md](preparation.md).

## Git checkpoint and preservation

Verified repository: `/home/bipin/projects/psg-document-control`.
Starting branch: `phase-10-administration-configuration`.
Starting HEAD: `a3870d5` (Phase 10 report), immediately after `4844ca2` (organization implementation).
Starting working tree: clean, including no untracked changes reported by Git.
New local branch: `deployment-preparation-ci-cd`.
Implementation commit: `dcf82c1` — `build(deployment): prepare portable runtime and guarded CI release templates`.
This completion report and the runbook are a separate local documentation commit; its identity and final working-tree result are recorded in the delivery message.

Phases 1–10 remain in ancestry. Diff against the starting checkpoint confirms no changes to `app/`, `resources/`, existing migrations, Composer/npm dependencies or locks, database configuration, PHPUnit configuration or the existing MySQL testing Compose file. Existing integrity tests passed. The one application packaging adjustment is proxy trust configuration in bootstrap plus its new config/test files. No workflow, authorization, localization or date-handling functionality was added.

The prior Phase 10 report's MySQL container restoration limitation is preserved: original stopped state was not confirmed restored. Docker access is denied here, so this package neither inspected nor stopped that container. This is an unresolved environment note, not a claim that it is currently running or stopped.

## Deliverables

| Files | Result |
| --- | --- |
| `Dockerfile`, `.dockerignore` | Multi-stage PHP 8.5 Apache/Node 22/Composer build; immutable release filesystem, non-root UID 33, compiled frontend, no environment/uploads copied. Required additional extensions only; no new app dependencies. Actual image build unverified. |
| `compose.release.yaml` | One combined web/PHP app and pinned MySQL 8.4; configurable loopback ingress; internal DB network; separate project volumes for DB, document vault, private files and framework state; bounded logs; no DB host port. |
| `docker/apache.conf`, `docker/php.ini`, `docker/entrypoint.sh` | Public-only web root, storage denial, coordinated upload limits, runtime caches, health strategy, no startup migrations/secrets. |
| `docker/app.env.example`, `docker/compose.env.example`, `.gitignore` | Sanitized separate app/Compose configuration, no live secrets, debug off, environment-specific identities and digest reference. |
| `bootstrap/app.php`, `config/trustedproxy.php`, `tests/Feature/TrustedProxyConfigurationTest.php` | Explicit configured ingress trust; forwarded Host ignored. Two request regressions. |
| `.github/workflows/ci.yml` | PR/main/reusable CI, lockfile installs/caches, full PHP/frontend tests, production assets, formatting, dependency audits, MySQL regressions, Docker build and isolated smoke definitions. Read-only default permissions and pinned actions. Not run on GitHub. |
| `.github/templates/release-image.yml.disabled`, `docker/release-manifest.py` | Inactive authorized release definition; reviewed source/baseline identity, required CI, private-GHCR design, version-plus-SHA tag, authoritative digest and complete migration inventory/hashes. No image published. |
| `docker/preflight.py` | Explicit UAT/Production/project selection; configuration and digest checks; optional read-only local daemon/capacity/storage/DB/status probes. No mutation/deploy capability. |
| `docker/smoke.sh` | Opt-in disposable local stack with random project/secrets and scoped cleanup; prepared only, not executed. |
| `docs/deployment/preparation.md`, this report | Architecture, CI/release process, explicit manual deployment/migration steps, concurrency lock, backup/restore and rollback runbooks, security boundaries, future Production procedure and approval decisions. |

No workers, scheduler, Redis or orchestration framework added: no current application background/scheduled work justifies them. MySQL application/backup/migration privilege separation remains an operational gate; default image initialization is not a hardened runtime-account setup.

## Actual local verification

| Check | Actual result |
| --- | --- |
| Installed requirements | PHP 8.5.4, Laravel 13.38.0, Vue 3.5.44, Vite 8.3.5, Node 22.23.1; Composer direct dependencies inspected. |
| Full backend, `php artisan test --compact` | **769 passed, 7 MySQL-only skipped, 0 failed; 776 total, 4,906 assertions; 99.857 s.** Existing isolated in-memory SQLite harness; no live migration. |
| New proxy tests | **2 passed**, then included in the full backend run. Untrusted header rejection and trusted ingress behavior including forwarded-Host rejection. |
| Full frontend, `npm test` | **27 files, 288 tests passed; 7.62 s.** |
| Production assets, `npm run build` | Passed; 58 modules, 712 ms. Manifest and bundled English/Lao font assets generated. |
| Composer validation/platform | `composer validate --strict` and `composer check-platform-reqs` passed. |
| Vulnerability feeds | `composer audit --locked`: no advisories. `npm audit --audit-level=high`: zero vulnerabilities. Initial sandbox DNS failure was resolved by authorized read-only network checks with `/tmp` caches; no dependency update. |
| PHP style and diff | `vendor/bin/pint --dirty --format agent` passed; `git diff --check` passed. |
| Compose | Docker Compose 5.6.1 rendered/validated sanitized synthetic configuration with `config --quiet`, without daemon access. Debug override remains false even with true in the supplied synthetic app env. |
| YAML/shell/Python | All three YAML definitions parsed; shell syntax and Python AST checks passed. No full GitHub-hosted or actionlint run claimed. |
| Preflight | Synthetic UAT configuration/manifest passed without daemon access. Invalid project, unsafe secret-file permissions and mutable image references refused. |
| Manifest | Synthetic immutable reference produced matching source identity, complete migration hashes and zero migration changes against Phase 10. It is test output, not an actual release. |
| Smoke guard | Invocation without explicit opt-in refused before Docker/resource operations. |
| Docker daemon/build/runtime | **Unavailable: permission denied on local Unix socket.** No workaround attempted. No build, running-stack test, image-layer inspection, MySQL integration, in-container health/private-storage persistence test or shutdown isolation test was executed. Existing MySQL regression infrastructure was untouched. |
| Source/integrity preservation | Diff confirms business code, migrations, frontend, locks and existing DB/test configuration unchanged. Full default regression suite passed. |

CI defines the remaining Docker/MySQL checks on fresh hosted runners; definitions are not evidence that those checks pass. No UAT acceptance test was performed. No Production-equivalent DB trigger/definer/binlog, capacity, ingress, backup or restore claim is made.

## Security and boundaries

Tracked examples contain placeholders only. No UAT/Production key, password, token or certificate was generated, read or modified. Existing `.env` was not printed or copied into deliverables. `.dockerignore` excludes all environment files, storage, caches, Git/tool state, local DBs, keys, archives, dependencies and development assets; explicit COPY paths further limit inclusion. Synthetic validation values were temporary and are not operational secrets. Image-content/layer verification remains unavailable until a build is possible.

Read-only upstream action-ref and vulnerability-feed lookups were the only external verification. No operational host, NetBox, LVC Inventory or XPPL Toll access; no DNS/TLS/firewall/network setup; no operational DB or real XPPL data; no live migration, restoration, GitHub settings change, push, PR, merge, registry login/publication or deployment occurred.

## Status and remaining gates

- **Implemented and locally verified:** source/configuration definitions, default PHP/frontend regressions, production frontend assets, dependency/platform/audit checks, Compose render and safe plan/helper behavior as listed above.
- **Prepared, not activated:** runtime image, Compose environments, release publishing template, disposable container smoke and manual UAT/Production deployment/recovery procedure. Docker runtime evidence still required on an authorized local Docker environment.
- **Requires GitHub configuration:** ownership/access, branch/tag protections, required CI, workflow review, private GHCR visibility/permissions and publishing approval/environment controls. Review base-image pins, OS vulnerabilities and license inventory before first release.
- **Requires infrastructure approval:** UAT/Production topology, VM sizing/architecture, ingress/DNS/TLS/firewall allocation, backups/RPO/RTO/retention, secrets custody, DB accounts/trigger privileges, monitoring and tested recovery. All remain configurable or explicit gates.
- **Requires XPPL authorization:** UAT deployment approval and execution, formal acceptance of that exact image digest, separate Production release authorization and operational data initialization. Phase 11 and Production execution have not begun.

Final working-tree status is checked after the documentation commit and reported with that commit in the delivery message. No push or merge follows this package.

## Focused local Docker verification continuation — access blocked

Continuation checkpoint: branch `deployment-preparation-ci-cd`, HEAD `069919c8ab2a29728303efc7d5e90b39b7210537`, immediately following `dcf82c1`. The working tree was clean before diagnostics. Read `AGENTS.md`, both deployment reports, Laravel/testing skill instructions and all requested Docker/Compose/helper/CI files. `.ai/rules` remains absent. This continuation did **not** meet the Docker verification completion criteria.

Read-only diagnostics established:

- Current WSL user is `bipin`, UID/GID 1000. Outside sandbox isolation its groups are `bipin`, `adm`, `cdrom`, `sudo`, `dip`, `plugdev` and `users`; it is **not** a member of `docker` (GID 986).
- Docker CLI `/usr/bin/docker` is version 29.9.2; Docker Compose is 5.6.1. Selected context is `default`, endpoint `unix:///var/run/docker.sock`. No DOCKER_HOST or DOCKER_CONTEXT override was present. No remote endpoint was used.
- Sandbox UID/GID mapping reports the socket as `nobody:nogroup`; outside the sandbox the actual socket ownership is `root:docker` (0:986), mode `0660`. The sandbox ownership display alone is not a daemon ownership defect.
- Outside-sandbox `systemctl show docker` reports `ActiveState=active`, `SubState=running`, MainPID 293849. This confirms the local Engine service is running; Docker Desktop is not required to explain this blocker.
- Ordinary and approved outside-sandbox Docker API checks both returned permission denied on that same Unix socket. Container list and inspection of `dcms-mysql84-tests` were denied too.
- A separately requested elevated, read-only `sudo -n docker --host unix:///var/run/docker.sock version` check returned **`sudo: interactive authentication is required`**. Sandbox escalation retains the user identity and does not supply root authentication. The execution mechanism allowed the check; no automatic approval rejection occurred.

Diagnosis: local Unix-socket authorization is unavailable to this process, even with the sandbox restriction removed. The daemon is running and context is local; changing contexts or installing another Docker implementation would not resolve the demonstrated user-permission boundary. No socket ownership/mode, daemon configuration, groups or user privileges were changed. No sudo password was requested, supplied or stored, and no alternative endpoint or privilege workaround was attempted.

| Required evidence | Continuation result |
| --- | --- |
| Build / image ID, registry digest, size / extension and asset inspection | Not performed; no image artifact produced. |
| Apache/PHP boot, health, MySQL connectivity and isolated migrations | Not performed; authorization blocked before resource creation. |
| Login/session, bilingual UI, correspondence, controlled documents, attachments, authorization, dashboard/report integration | Not performed in containers. Prior package regressions above are historical evidence only, not rerun or container results. |
| Runtime debug/secrets, read-only permissions, DB port isolation and private-file access denial | Definitions reviewed; no new runtime verification claimed. |
| Document/DB/numbering persistence, restart recovery and scoped cleanup | Not performed; disposable smoke was not invoked with opt-in and no disposable resources were created. |
| Existing `dcms-mysql84-tests` final state | **Unknown**; denied read-only inspection. No stop/restart/recreation/reset or volume access attempted. Prior restoration note remains unresolved. |
| Existing Docker-resource preservation | No Docker mutation command executed. No images pulled/built, containers/networks/volumes created or removed, or cleanup/prune performed. A before/after inventory could not be obtained. |
| Changes and checks | Only these two deployment reports updated. `git diff --check` passed. No packaging/application changes or repeated test suites; container blocker prevented required integration checks. |

Next step requires an administrator-authenticated execution path for the **same local Unix Docker daemon**, explicitly approved for this development verification. Do not grant broad Docker access or change permissions automatically. An administrator must arrange the approved authentication/execution facility outside this agent's noninteractive session; do not send passwords through chat. Once available, resume with a fresh Git/access check and read-only Docker inventory, resolve the old MySQL container-state note safely, review smoke isolation against ambient environment overrides, then build/test using explicitly identified random disposable resources and record cleanup. No container test result or UAT readiness may be inferred from this blocked continuation.

This evidence update is a separate local documentation commit. Its hash and final working-tree status are reported in the delivery message. No push, publication, workflow activation, remote host access, operational secret access, live migration or deployment occurred.
