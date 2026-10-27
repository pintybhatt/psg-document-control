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

## Smoke safety hardening and administrator handoff

Starting checkpoint verified clean: `deployment-preparation-ci-cd` at `9572ac76286f67f3718ccda5077ac66866e9da84`. No history rewritten. The administrator has independently confirmed local Docker Engine 29.9.2/API 1.56, Compose 5.6.1 on Ubuntu 26.04 WSL2 AMD64 and `dcms-mysql84-tests` in `exited 0`, storage preserved. This supplied confirmation resolves the older restoration uncertainty; it is not a new Codex Docker observation. Codex's process still lacks socket/sudo authentication, and no privileged execution was retried in this safety-only pass.

Identified defects: ambient DCMS variables could override synthetic database/config/bind values; Docker client configuration and Compose behavior variables were inherited; cleanup trusted a project name without verifying resource ownership or creation time and removed evidence even after failure; application recreation did not explicitly exclude DB dependencies. These were source-review findings, not observed operational incidents.

Corrections: `smoke.sh` is a small launcher for a standard-library Python driver. `smoke.py` rejects remote/alternative host/context/config/TLS selectors, controls Docker/Compose/app interpolation while retaining ordinary operation settings, uses isolated Python and the reviewed absolute Docker binary with explicit local host/private client config for every subprocess, requires a local image ID and separate test/deletion consent, validates rendered isolated configuration, generates non-overridable UUID project/run identities, inventories before creation, checks namespace collisions, verifies all cleanup candidates against baseline/labels/expected identities before deletion, verifies project cleanup, pins application recreation to `--no-deps`, returns failures nonzero and retains redacted private evidence. No new dependency/service or business-code change. `preflight.py` and release Compose/entrypoint are unchanged; smoke does not invoke preflight.

Added `tests/test_docker_smoke.py` with daemon-free safety coverage. CI now runs these tests and supplies the explicit cleanup consent plus the built local image ID. Action pins, CI publication permissions and the inactive release workflow remain unchanged; no workflow has run on GitHub.

New verification (not inherited from prior full suites):

- **15 safety tests passed, 0 failures/skips, 0.660 seconds** in the final recorded focused run, using standard-library mocks for Docker API/cleanup and real local Compose 5.6.1 configuration rendering only.
- Covered execution/deletion opt-ins; remote endpoint/context/config/TLS refusal; tag/registry/option/newline image refusal; unknown Docker/Compose environment selectors; preserved ordinary settings; unique generated project names; explicit subprocess endpoint; refusal to delete foreign/pre-existing containers/networks/volumes; deletion limited to verified new IDs with post-check; failure with/without successful cleanup; credential redaction; synthetic secret-file permissions; hostile caller DB/project/file/bind/image inputs; entrypoint refusal without opt-in.
- Shell syntax (`sh -n`), Python AST/indentation (`tabnanny`), Compose/CI YAML parsing and `git diff --check` passed. ShellCheck/Ruff are not installed; no claim of those tool results. No PHP/frontend changes, so full Laravel/frontend suites and production frontend build were not repeated.
- Actual `smoke.sh --plan` passed without daemon access: result `plan-only`, cleanup `not-needed`. It rendered and validated synthetic settings; no image existence/runtime check or Docker resource creation/deletion occurred. Temporary secret/env files were removed; private plan summary/override evidence remains under its fresh `/tmp` path.

Exact administrator-reviewed build/smoke/evidence/cleanup-verification commands and resource/download/deletion explanations are in [the handoff procedure](preparation.md#hardened-disposable-smoke-and-administrator-execution-handoff). Build and smoke commands were **not executed** and require the user's review/confirmation before administrator execution. No image ID/size, container boot, actual health/persistence or real cleanup success is claimed. Cleanup safety is tested with simulated resource responses, not a live daemon.

All application source, dependencies, migrations, private documents and existing databases/resources are unchanged. No local/remote Docker mutation, socket/group/sudo/daemon change, operational infrastructure access, push, merge, publication or deployment occurred. This is a focused local safety-change commit; its hash and final clean-tree status are reported in the delivery message. Remaining blockers: administrator execution and supplied output, any demonstrated build/runtime packaging defect, and subsequent evidence review. UAT/Production authorization remains separate.

## Legacy builder entrypoint compatibility correction

Verified clean starting checkpoint: `deployment-preparation-ci-cd` at `d41de61580dcb54d24f9713b17860edd1a224ca1`. The administrator reports that the authorized local build completed PHP runtime preparation, production Composer installation/package discovery/platform checks, Vue asset build/copying and application packaging through step 36. Step 37 of 43 failed at `COPY --chmod=755` with `the --chmod option requires BuildKit`. The sanitized local Docker client configuration selected the legacy builder. These are administrator-supplied build results, not a build run by Codex; no final release image ID or completed runtime test is available.

Replaced that instruction with ordinary `COPY` followed by `RUN chmod 755 /usr/local/bin/dcms-entrypoint`, before `USER www-data`. The executable mode, root ownership, non-root runtime, storage permissions, read-only Compose configuration and other security controls are preserved. Reviewed every Dockerfile instruction: the remaining copy option is standard multi-stage `--from`; there are no other BuildKit-only instruction flags, mounts or heredocs. The existing syntax directive is a comment to the legacy builder and remains usable by BuildKit.

Available static checks passed: all Dockerfile shell-form RUN instructions and the entrypoint pass `sh -n`; the portable copy/chmod ordering and final non-root user were checked; `git diff --check` passed. No application or dependency changes, no repeated PHP/frontend suites and no added test tools. Only `Dockerfile` and this completion report changed. Codex did not retry Docker access, rebuild, start containers, run smoke, touch MySQL, change permissions, push or publish. The administrator must rerun the build from the new committed source; success, image metadata and runtime verification remain pending. The focused commit hash and final clean-tree result are recorded in the delivery message.

## CI performance phase — local implementation for review

Verified clean `main` at `5cfa1ab3caa18835b721ea5300472aab095d8a58`; created `chore/ci-performance-optimization` without modifying main/history. Read project/testing instructions; `.ai/rules` remains absent. Reviewed the successful [run 40489175039](https://github.com/pintybhatt/psg-document-control/actions/runs/40489175039), which tested source `3848cee` now incorporated into main. It provides actual successful Docker build/smoke evidence and supersedes older CI verification limitations above for that baseline. It does not verify the new job graph, UAT or Production.

Measured baseline: **11m 49s** from run start to completion update; approximately 11m 39s of active job logs. Dependency install/audits 16.23s; frontend tests 37.57s, frontend build 1.26s (combined step 40.34s); default backend 93.90s (PHP/format step 104.43s); smoke safety 0.557s; MySQL tests **394.683s**, 751 passed / 4,755 assertions (startup/migration/test step 425.27s); Docker build approximately 66.62s; smoke/cleanup approximately 31.97s. MySQL is the primary bottleneck. Both dependency caches were cold and saved only at the successful run's end. Grouped install output does not permit an exact independent PHP/npm wall-time split.

Implemented in the existing workflow:

- Ordinary PRs to main run complete existing SQLite backend, frontend tests/build, syntax/formatting, dependency/platform/security and smoke safety checks, summarized by **Fast CI**. No security/lifecycle test class was removed.
- Main pushes, manual dispatch and reusable calls also run the complete unchanged MySQL suite and exact-image Docker build/smoke, summarized by **Full release validation**. Working jobs are independent where possible; PHP/MySQL receive real frontend assets from the same run to preserve Vite-rendering behavior. Aggregate shell checks were tested to refuse failed, skipped or cancelled dependencies.
- Composer download caches use the actual configured location with compatible lockfile fallback; npm retains locked cache/install behavior. Official SHA-pinned, metadata/license-reviewed artifact/Buildx actions provide asset transfer and scoped Docker caching. Retained release candidates build clean; all image builds resolve base images with pull enabled. No credentials/database/vendor trees enter download caches.
- Removed only the redundant standalone MySQL migration before the test harness's own first refresh. Schema-reset logic, server-identity checks, dedicated credentials and test coverage are untouched. New JUnit timing artifacts enable a later measured sharding decision. No local MySQL/container operation was performed.
- Inactive release template requests retention of the exact smoke-tested image, downloads it after full validation, verifies ID/source and would publish it **without rebuilding**. No publishing workflow was activated. Large image retention is explicit and three days; small frontend artifacts one day; test/smoke diagnostics three days. Extra automatic Docker build-record artifact upload is disabled.

Two MySQL shards were evaluated but **not implemented**: the baseline has no per-class distribution. The ideal test-only bound is 197.3s per balanced shard, with a projected full lane of 4.5–6 minutes after independent setup, but imbalance could erase that benefit. First collect new full-run JUnit timings and runner usage. Any future two-shard design must preserve the complete test union with disjoint whole-class partitions and independent GitHub-hosted runners/databases/volumes/UUIDs; fixed local regression infrastructure must never be shared between shards. LVC/Toll were not accessed. The [current preparation document](preparation.md#ci-strategy-measured-timing-and-image-releases) contains the complete comparison, event matrix and future approval controls.

New local verification:

| Check | Actual result |
| --- | --- |
| Complete default backend with JUnit output | **769 passed, 7 MySQL-only skipped, 0 failed; 4,906 assertions; 104.391s.** JUnit contains all 776 cases. |
| Complete frontend | **27 files / 288 passed; 9.56s.** |
| Production frontend build | Passed, 58 modules, 819ms; English/Lao font assets and both manifests present. |
| Smoke safety and Compose rendering | **19 passed, 0 failures/skips; 3.291s**, including real configuration rendering without daemon access. |
| GitHub Actions syntax | **actionlint 1.7.12 passed** on the active workflow and inactive release template; downloaded to `/tmp` from its official release and checksum verified. ShellCheck/Pyflakes integration was disabled because those tools are not installed. |
| Gate logic | **80 result combinations passed** for the two aggregate shell gates; only all-success combinations pass. Static inspection confirms full-event conditions, exact source references, unchanged read-only permissions and no active publication/deployment. |
| Shell/PHP/configuration | Bash syntax passed for all 22 embedded workflow scripts; entrypoint/smoke shell syntax, Composer strict validation, Pint dirty check and Git whitespace checks passed. No PHP source was changed. |
| Coverage/source preservation | Application, frontend source, production migrations, tests, PHPUnit configurations, dependency lockfiles, Dockerfile and Compose configurations are unchanged. |
| MySQL/Docker runtime for the new workflow | Not rerun locally: this task prohibits altering existing Docker resources. The full unchanged suites remain in Level B; new job/artifact/cache execution requires the first approved GitHub run. |

Projected new times, **not measurements**: Fast CI 2–4 minutes; unsharded full validation 7–9 minutes, subject to runner availability/cache/network and opt-in image-archive overhead. Full events may consume comparable or higher total runner time because setup occurs on multiple runners; normal PRs avoid the long database/image jobs. No guaranteed speedup or hosting cost is claimed. The first new PR/main/manual run must validate artifact transfer, BuildKit caching and exact-image retention before relying on the projections.

Files changed: `.github/workflows/ci.yml`, `.github/templates/release-image.yml.disabled`, `docs/deployment/preparation.md`, this report. No new application dependency, service, secret, environment, branch rule or GitHub repository setting. No local Docker mutations, operational host/NetBox/Toll/LVC access, push, merge, image publication or deployment. Final commit and clean-tree confirmation are recorded in the delivery message.

Outstanding owner decisions: approve required-check transition from old `validate` to Fast CI; decide the explicit full-validation/release-candidate approval policy; review Actions/artifact/cache quotas and first-run timing evidence; approve private GHCR package permissions and future publishing controls; settle UAT host isolation/capacity, ingress/DNS/TLS/firewall, backup/retention/recovery, migration accounts and secrets/approver ownership. The shared NetBox host remains unapproved and untouched. Production promotion requires separate authorization and the exact UAT-accepted digest, without rebuilding.
