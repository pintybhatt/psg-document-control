# Isolated MySQL 8.4 verification

Verification date: 2026-10-26. Baseline: `phase-3-master-data-numbering` at `5feeafe`. This follow-up supplies MySQL evidence for the [Phase 3 integration review](phase-3-final-integration-review.md); it does not authorize deployment or begin Phase 4.

## Installed environment

Ubuntu WSL is Ubuntu 26.04 LTS (`resolute`), amd64, with an existing systemd configuration and WSL2 kernel `6.18.33.2-microsoft-standard-WSL2`. The [official Docker installation instructions](https://docs.docker.com/engine/install/ubuntu/) list this Ubuntu release as supported. The official signed `download.docker.com/linux/ubuntu` APT repository was used with the `resolute stable` suite and `/etc/apt/keyrings/docker.asc`.

Installed packages are Docker Engine/CLI 29.9.2, containerd.io 2.4.2 and Docker Compose plugin 5.6.1, plus required runtime/firewall libraries. APT installed 12 new packages, upgraded zero and removed zero. Docker Desktop, Buildx, rootless extras and proprietary software were not installed. Docker and containerd start through systemd. No WSL settings, existing application services or Docker group memberships were changed. Existing listener addresses/ports were unchanged after installation. PHP already provided `pdo_mysql`.

Noninteractive sudo was unavailable in this session. Approved installation and daemon operations used `wsl.exe --distribution Ubuntu --user root -- ...`; this did not change WSL configuration. Subsequent developers can use sudo for the same local Docker socket. Docker socket access is privileged; the setup does not make it world-writable or grant users membership automatically.

## Isolation and credentials

- Compose file: [`compose.mysql-testing.yaml`](../../compose.mysql-testing.yaml).
- Project/container: `dcms-mysql84-tests`; bridge network: `dcms-mysql84-tests-private`; volume: `dcms-mysql84-tests-data`. These resources belong only to DCMS tests.
- Official image: `mysql:8.4.13`, pinned to digest `sha256:76f0358884cb92d9a086d24e5671c38048d3fef40f2588703d73eb55392d6b69`. SQL reports **MySQL 8.4.13, MySQL Community Server - GPL**.
- Only `127.0.0.1:33084` publishes MySQL port 3306. Port availability was checked before startup; MySQL X Protocol is disabled. The dedicated bridge permits outbound traffic; it is not an air-gapped network. An internal bridge was tried, but Docker 29 did not publish its host port, so the final setup uses an ordinary dedicated bridge.
- Strong random test/root passwords are stored only under ignored `storage/framework/testing/mysql84/`, with directory mode 0700 and secret mode 0600. Compose mounts files as secrets; password values are absent from tracked files, command arguments and reports. The application test account has privileges on `dcms_mysql_test` only, including migration/trigger DDL. This is not a production runtime privilege model.
- The launcher fixes the host, port, database and user, probes the saved container server UUID, and requires MySQL 8.4 Community plus explicit `DCMS_MYSQL_TESTING=1`. A failure aborts before Laravel boots; there is no alternate server fallback. All Docker commands explicitly select `unix:///var/run/docker.sock`.
- `.env`, `config/database.php`, `phpunit.xml` and the local SQLite database are unchanged. All test users, organizations and registration fixtures are synthetic and confined to the disposable database. No operational database or existing application was inspected or modified.

## Reproduce

Run from the repository root. The first command requires the test port to be free, so use it before starting this container. Existing generated secrets are preserved.

```sh
php tests/prepare-mysql.php
sudo docker --host unix:///var/run/docker.sock compose -f compose.mysql-testing.yaml up -d --wait --wait-timeout 180 --quiet-pull
sudo sh tests/capture-mysql-identity.sh
DCMS_MYSQL_TESTING=1 php tests/mysql-artisan.php migrate --no-interaction
DCMS_MYSQL_TESTING=1 vendor/bin/phpunit --configuration phpunit.mysql.xml
```

The identity helper checks the Compose project label before recording the server UUID. A changed UUID is rejected rather than silently accepted. Preserve the ignored credential and identity files with this test volume; recreating the volume requires deliberately re-establishing its identity. Do not run this suite in parallel with another suite using the same test database: tests intentionally recreate its schema.

The separate migration launcher supports only migration commands and rejects connection overrides. The MySQL PHPUnit configuration runs the normal PHP unit/feature suite except `NumberingConcurrencyTest` (SQLite-specific worker) and `SetupPhaseThreeDemoTest` (deliberately SQLite-only development fixture). Both remain covered by the normal SQLite suite. The new MySQL concurrency tests skip under normal SQLite execution.

MySQL DDL implicitly commits. The test base creates `FormalRegistrationRecord`'s test-only table on `MigrationsEnded`, before transactional tests start; otherwise its existing lazy table creation would invalidate MySQL test transactions. No application model, migration or authorization behavior changes for this adjustment.

Stop only this test container when finished; its dedicated volume and private credentials remain available:

```sh
sudo docker --host unix:///var/run/docker.sock compose -f compose.mysql-testing.yaml stop
```

## Executed checks

The server uses InnoDB, foreign-key checks enabled, strict SQL mode, `utf8mb4_unicode_ci` and default `REPEATABLE READ`. `log_bin_trust_function_creators=1` is explicit for this isolated test server so the database-scoped migration user can create protection triggers with binary logging enabled.

Fresh migration applied all 12 migrations. Final verification:

| Check | Result |
| --- | --- |
| Focused MySQL Phase 3 regressions | 92 tests, 401 assertions passed. |
| Complete MySQL-compatible PHP suite | 490 tests, 2,224 assertions passed, including all seven new concurrency/isolation cases. |
| Complete default SQLite PHP suite | 502 passed, 2,327 assertions; seven MySQL-only cases skipped (509 total). |
| Pint | `vendor/bin/pint --dirty --format agent` passed. |
| Whitespace | `git diff --check` passed. |

No application compatibility defect required a functionality change. Initial concurrency test failures were corrected in test orchestration/assertions: Laravel's wrapped timeout type and the placement of the deadlock barrier. After verification, the dedicated database was freshly migrated to remove synthetic records and its container stopped; the volume, credentials and server identity were retained for reuse. Docker/containerd remain installed and active. Hash comparisons confirmed the development environment file, SQLite database and database configuration were unchanged.

Independent PHP processes exercise the actual `DocumentNumbering::register()` service and persisted test records:

- Four synchronized workers contend for the initially absent annual counter: three commit unique numbers with sequences 1–3 on distinct MySQL connections, and one rolls back. The committed record count, issuance count and counter all equal three.
- A held registration causes error 1205 on another connection. The failed attempt leaves no record or consumed sequence; the next registration receives sequence two. Laravel wraps this nested timeout in `DeadlockException`, with the original `QueryException` as its cause.
- A configuration change times out behind issuance's series lock. After commit, the change succeeds; the next identifier uses the new format and sequence two while the first identifier stays unchanged.
- Two workers hold opposite register locks inside registration callbacks before requesting the other register. MySQL selects one deadlock victim. An explicit two-attempt **outer** transaction retries the complete database-only operation; the workers take three attempts in total and leave four records/issuances, with sequences 1–2 and counter two in each series. The service itself still uses one attempt and never retries a callback automatically.
- Separate connections revoke membership after the first authorization read. Under `REPEATABLE READ`, the open transaction retains its original authorization snapshot; under `READ COMMITTED`, a subsequent decision sees revocation. After the transaction ends, a fresh decision denies access in both cases.
- Phase 3 migration rollback and reapplication preserve Phase 2 user/membership data and restore the master-data/numbering tables. Other PHP regressions cover authorization, memberships, confidentiality and audits, composite ownership constraints, annual/continuous numbering, protected history, REPLACE rejection and unsafe counter changes.

## Remaining limits

This is local compatibility evidence, not a production readiness certificate. Production-equivalent migration/trigger definer privileges, binary logging policy, runtime credentials, backup/restore and crash/failover/commit-acknowledgement behavior still require deployment review. MySQL DDL rollback is not transactionally atomic. Collation and strict typing differ from SQLite as described in the [database README](../../database/README.md).

Concurrent revocation is **not linearizable** with registration. Ordinary policy reads can retain a snapshot even when numbering uses current locking reads. Keep registration transactions short and settle the workflow revocation boundary before deployment; do not infer immediate revocation from a transaction or change the application's isolation policy based solely on this test. The Phase 2 transaction-independent audit-sink limitation also remains.

Observed MySQL lock records show shared/next-key locking from history-protection trigger reads, including contention between different series. The deliberate deadlock barrier therefore sits inside the callback before the first issuance-ledger insert. Correctness tests pass, but production-like multi-series throughput and lock ordering across all future workflow resources remain unverified. Prefer a consistent ordering and retry only a whole, explicitly idempotent transaction without external side effects. Lock timeout/deadlock errors must abort registration.

The separate-process matrix exercises same-period annual allocation and two-register contention; annual rollover, continuous counters, purpose separation and duplicate formal records are covered sequentially by the normal suite on MySQL. Concurrent rollover, internal/official allocation to one production record, competing prefix claims, record-level workflow idempotency, register deactivation during lock waits and full revocation interleavings remain future targeted checks. No document workflows or file storage were implemented.
