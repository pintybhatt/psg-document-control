# XPPL UAT preparation — inactive operator runbook

This is local preparation, **not permission to execute these commands**. No server was contacted. Starting main: `c91557c9d071427416f7ad4b50f34c8b03691677`. Full [CI run 40495568162](https://github.com/pintybhatt/psg-document-control/actions/runs/40495568162) passed; its image was not retained/published. A new explicitly retained candidate must pass full validation before any publication/deployment. These templates do not claim UAT load capacity or acceptance.

## Architecture and boundaries

Owner-supplied host inventory: Ubuntu 22.04.4 VMware VM, Docker 29.9.2/Compose 5.6.1, 2 vCPU/4 GB/100 GB disk (~73 GB free), operational NetBox/Nginx/PostgreSQL 14/Redis. Owner approved starting sizing, **not server changes in this phase**. Reconfirm inventory/capacity with the host owner before execution. NetBox availability takes priority.

Use `compose.release.yaml` plus **one** `compose.uat.yaml` overlay, project **`dcms-uat-xppl`**. Do not use these commands for Production. Existing release image remains PHP 8.5/Apache, UID 33, immutable source, read-only root, dropped capabilities/no-new-privileges, writable named private/document/framework volumes and bounded tmpfs. Dedicated pinned MySQL 8.4 has its own `database` volume, internal backend network, secrets and health check. No shared network/volume, host networking, privileged container, source-code mount, public document link or database host port. Only an individual read-only Apache tuning file is bind-mounted. No worker/scheduler/Redis is added: current queue is synchronous with no required scheduled work.

Host Nginx **8444 HTTPS** → **127.0.0.1:18084** → app **8080** → internal MySQL **3306**. The overlay replaces inherited port mappings with loopback only, regardless of `DCMS_BIND_ADDRESS`. `docker/preflight.py --uat-profile` additionally insists on 18084, the exact UAT project/database/image repository and resource protections. Generic release templates remain portable. This overlay needs Compose >=2.24.4 for `!override`; the supplied 5.6.1 meets that requirement.

Never touch NetBox's 443 listener or 127.0.0.1:8001 upstream, PostgreSQL, Redis, Toll's 9443, or reserved future 9444. No cleanup command in this runbook deletes a network, volume, database or image. Do not run the disposable smoke driver on this operational shared host.

## Initial resource budget and monitoring

| Service | Hard RAM / total RAM+swap | CPU ceiling | Additional bounds |
| --- | --- | --- | --- |
| App | 640 MiB / 640 MiB | 0.5 CPU | 128 PIDs; 2 prefork workers; PHP 192 MiB/request; 64 MiB `/tmp`, 16 MiB bootstrap tmpfs |
| MySQL | 768 MiB / 768 MiB | 0.5 CPU | 256 PIDs; 256 MiB InnoDB buffer; 20 connections; smaller caches/temp tables |

Total container hard memory ceilings: **1,408 MiB**; CPU ceilings total **1 of 2 CPUs**. Remaining ~2.6 GiB is not a reservation for NetBox: host kernel, Docker/Nginx, page cache, existing services and backup processes still consume memory/IO. MySQL has additional dynamic allocation beyond its buffer pool; 768 MiB may be insufficient for startup or migration. Two Apache requests can exhaust memory for PDF/upload workloads. CPU quotas bound container CPU but do not isolate disk/network or guarantee NetBox latency. No load/user-count promise. Do not disable OOM killing, raise limits or enable swap automatically when checks fail.

Inherited Docker logs rotate at 10 MiB ×3 per container (~60 MiB total). Host Nginx access/error logs need separately approved rotation; avoid query strings containing confidential data. Monitor document/framework/database growth and MySQL binary logs. No backup/binlog retention schedule or automatic deletion is introduced; agree retention and off-host space before activation. Keep durability/binlogging defaults; DBA must resolve trigger creation privileges/definers, not silently set global trust or disable binary logging as a workaround.

Before and during UAT record host `free -m`, `vmstat 1 5`, `df -h`, `df -i`, swap activity and existing service health using host-owner-approved read-only commands. Inspect only UAT with `dc ps`, bounded `dc logs --since 10m --tail 100 app db`, and `ld stats --no-stream "$(dc ps -q app)" "$(dc ps -q db)"` after both IDs exist. Logs may contain sensitive application context: protect/redact before sharing. Verify effective Docker memory/CPU limits and Apache prefork/worker settings on the approved first start. Stop admission immediately for NetBox regression, persistent swap, OOM/restarts, latency, or disk pressure. Notify host owner; gracefully stop **only** `dc stop app` then, if necessary and authorized, `dc stop db`. Do not restart or clean other services. Agree monitoring intervals/thresholds and an escalation owner before users connect; a container health check alone is not monitoring.

## Approvals, GitHub and release identity

Developer branch → reviewed PR/Fast CI → main → **non-skipped Full release validation** → retained tested image → separately approved private GHCR publication → approved manual UAT installation. Use existing `.github/templates/release-image.yml.disabled`; it remains inactive. Once separately authorized, its `release-image` environment requires owner review and restricted release refs, `ENABLE_IMAGE_PUBLISHING=true`, `contents: read`/`packages: write` only on publishing, and the repository's short-lived `GITHUB_TOKEN`. It calls full CI; failed SQLite/MySQL/image checks prevent publishing. It downloads the **same run's exact smoke-tested archive** and never rebuilds before push. Inspect the resulting source/digest/migration release manifest. No `latest` authority, no server-side Composer/npm build.

Minimum future GHCR setup: private `ghcr.io/pintybhatt/psg-document-control`, approved repository/package access and visibility, retention/quota ownership, and host pull identity limited to `read:packages` plus package read access. Store its classic PAT only in an approved root-owned credential source/client config, login with `--password-stdin`, never command-line token arguments or shell tracing. Host credentials differ from publishing credentials. No credential is created here.

**UAT approval gate is an explicit operator release authorization**, identifying target, digest, full-run URL, backup/recovery decision, migration review, time and approver. Store it with deployment history. This small-team design deliberately uses authenticated manual operator execution, not an active SSH workflow. There is no GitHub deployment credential or environment today. If future GitHub-to-host execution is approved, first create an `uat` environment with required reviewers/protected release refs and environment-scoped SSH key, pinned known-host fingerprint and host pull credentials as appropriate; no PR job gets those secrets. Restrict remote account/sudo to reviewed UAT operations and repeat the same digest/health/backup gates. Do not use unverified SSH actions or auto-accept host keys. This future automation requires a separate implementation/security review; it is not activated by copying the publishing template. Production has no job, host, credential or command in this plan.

## DNS, TLS and loopback stage

Hostname is **unconfirmed**. `dcms-uat.phonesackgroup.com` is a proposal only. Final `APP_URL=https://APPROVED_HOST:8444`; certificate SAN/trust must match, with approved renewal/key custody. No port 80 listener or automated ACME server modification is included. DNS/firewall owners must approve DNS resolution, narrowly scoped inbound 8444 and outbound GHCR/image downloads; no MySQL/18084 LAN exposure. Confirm 8444 and loopback 18084 are unused before installing anything. No assumption about existing certificates.

`docker/nginx-uat.conf.template` is a **new independent server block**, not an existing file replacement/default server. Render only its four named variables with restricted `envsubst`, leaving Nginx `$host`/`$remote_addr` intact; validate hostname (DNS characters only), numeric port, and absolute certificate paths without whitespace/semicolon before rendering. Review the result privately. It overwrites forwarding headers, preserves the approved Host:8444, rejects other hosts and public storage paths, and coordinates the 27 MiB body limit with Apache/PHP's 27 MiB POST/25 MiB upload and app 25,600 KiB file limit. Forwarded host is deliberately ignored by Laravel. Trust **only the actual source/gateway address observed from this app's own ingress network**, after host-approved inspection; never `*`, a guessed subnet or the entire LAN. Health `/up` alone cannot verify forwarded scheme/session cookies: test HTTPS login and redirects after proxy activation.

Later approved rendering example (not executed):

```bash
set -euo pipefail
: "${DCMS_UAT_HOSTNAME:?approved DNS name}" "${DCMS_TLS_CERTIFICATE:?approved cert path}"
: "${DCMS_TLS_KEY:?approved key path}"
export DCMS_UAT_HOSTNAME DCMS_TLS_CERTIFICATE DCMS_TLS_KEY
export DCMS_HTTP_PORT=18084
[[ "$DCMS_UAT_HOSTNAME" =~ ^[a-z0-9]+([.-][a-z0-9]+)*$ ]]
[[ "$DCMS_TLS_CERTIFICATE" =~ ^/[a-zA-Z0-9/_.-]+$ && "$DCMS_TLS_KEY" =~ ^/[a-zA-Z0-9/_.-]+$ ]]
umask 077
envsubst '${DCMS_UAT_HOSTNAME} ${DCMS_TLS_CERTIFICATE} ${DCMS_TLS_KEY} ${DCMS_HTTP_PORT}' \
  < docker/nginx-uat.conf.template > /APPROVED/PRIVATE/STAGING/dcms-uat.conf
```

Installation, global `nginx -t` and graceful reload need **separate host-owner approval**, with before/after NetBox health checks and a rollback copy of only the new DCMS file. Do not run those here. Never edit the existing default/NetBox configuration or listeners.

Before TLS activation, technical verification may use **loopback HTTP only**: `APP_URL=http://localhost:18084`, temporarily `SESSION_SECURE_COOKIE=false`, no trusted proxies, no user access. Invoke preflight with explicit `--localhost-verification`; ordinary UAT preflight rejects this configuration. Keep APP_DEBUG=false and encrypted sessions. Access only from host localhost or a separately approved loopback-bound SSH tunnel, not a LAN bind/firewall opening. Before admitting users restore HTTPS URL/secure cookies and exact proxy allowlist, force-recreate app to rebuild caches, pass ordinary preflight and verify secure cookies/redirects. Never enable HTTPS ingress with the technical settings.

## Protected directories and configuration

After explicit host-change authorization, operator provisions `/srv/dcms-uat/` with root-only `config`, `history`, `backups`, `registry` directories (0700), and root-managed `release/` containing the reviewed release Compose files, Apache tuning file and preflight helper (readable/traversable by container UID 33 for the single Apache bind mount). Do not bind the whole directory. Keep each approved config bundle/checksums and manifest in history. Do not overwrite an earlier release's evidence. No source code build on host.

Copy `docker/uat.compose.env.example` to `config/compose.env`, and `docker/app.env.example` to `config/app.env` **outside Git**, 0600. Populate only approved image digest, fresh UAT APP_KEY and synthetic/non-operational UAT database secrets using approved out-of-band generation. No live values are generated here. `db-password`/`db-root-password` files are owner-only, nonempty, match app password, and never shared with other environments. Keep `DB_HOST=db`, port 3306, database/user `dcms_uat`, empty DB_URL/DB_SOCKET, encrypted database sessions, database cache, unique cookie/cache prefix, synchronous queue, stderr logs and array mail (no confidential mail body logging). Preflight checks these identities and suppresses resolved secret output. Do not source env files as shell scripts or print `compose config`.

DBA provides a separate `config/migration.env` (0600) containing **only** `DB_USERNAME=dcms_uat_migrate` and its DB_PASSWORD for this same UAT database and a `config/backup.cnf` (0600) for database-specific backup privileges. The initial image creates `dcms_uat` with schema DDL privileges; before serving app, DBA must provision migration/backup accounts and reduce runtime permissions to reviewed DML/trigger execution. Do not start web traffic with root/DDL serving credentials. Account/trigger provisioning SQL is a separate DBA-reviewed operation, not automatically generated or run by this package. Store APP_KEY, config and recovery credentials with encrypted off-host recovery material.

## Operator session and first initialization — future authorized execution only

The blocks below require an **administrator-authenticated root Bash session on the approved UAT host**, approved image publication/pull, protected files and a written authorization reference. Do not run from this local preparation task. Keep one shell and its lock through the complete window; no `set -x`. A nonempty reference records an organizational decision, not authentication of approval by software. Never change the fixed project to operate Production.

```bash
set -euo pipefail
umask 077
test "$(id -u)" = 0
: "${APPROVAL_REFERENCE:?set the approved UAT change reference}"
test "${DCMS_UAT_EXECUTE:-}" = approved
ROOT=/srv/dcms-uat
CFG="$ROOT/config"
RELEASE="$ROOT/release"
exec 9>"$ROOT/deployment.lock"
flock -n 9
ld() { env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/root DOCKER_CONFIG="$ROOT/registry" \
  /usr/bin/docker --host unix:///var/run/docker.sock "$@"; }
dc() { ld compose --project-name dcms-uat-xppl --env-file "$CFG/compose.env" \
  -f "$RELEASE/compose.release.yaml" -f "$RELEASE/compose.uat.yaml" "$@"; }
history="$ROOT/history/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -m 700 "$history"
printf '%s\n' "$APPROVAL_REFERENCE" > "$history/authorization.txt"
cp "$CFG/release-manifest.json" "$history/candidate-manifest.json"
image=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["image"])' "$history/candidate-manifest.json")
[[ "$image" =~ ^ghcr.io/pintybhatt/psg-document-control@sha256:[a-f0-9]{64}$ ]]
env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/root python3 "$RELEASE/docker/preflight.py" \
  --target uat --project dcms-uat-xppl --uat-profile \
  --compose-env "$CFG/compose.env" --manifest "$history/candidate-manifest.json"
# For an explicitly approved loopback technical stage ONLY, add --localhost-verification above.
# Stop here until capacity, backup/restore, migration accounts and port checks are signed off.
dc pull app db
ld image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$image" \
  > "$history/image-source.txt"
python3 - "$history" <<'PY'
import json, pathlib, sys
p = pathlib.Path(sys.argv[1])
assert (p/'image-source.txt').read_text().strip() == json.loads((p/'candidate-manifest.json').read_text())['source_commit']
PY
```

Pulling may download only the approved app digest and pinned MySQL image; it does not start services. A registry login is a separate credential-approved step using `ld login --password-stdin`. Retain the previous approved image locally; do not prune. Initial deployment must additionally confirm **this exact project has no pre-existing resources/data** via project-labelled inventories; existing objects require investigation, not reuse/deletion.

Only for an approved **new, empty UAT** project:

```bash
# Same locked shell, after empty-project and initial no-data recovery decision.
dc up -d --wait --wait-timeout 180 db
# DBA now provisions/reviews migration and backup roles and runtime grants.
# Stop if DBA has not completed this step or trigger/binlog policy is unresolved.
test -s "$CFG/migration.env"
test -s "$CFG/backup.cnf"
dc run --rm --no-deps -T --entrypoint php --env-from-file "$CFG/migration.env" app \
  artisan migrate:status --no-interaction > "$history/migrations-before.txt" 2>&1 || {
    printf '%s\n' 'STOP: review status; an initial missing migrations table needs an explicit DBA decision.' >&2
    false
  }
# An initial empty DB can legitimately lack the migrations table. Record that decision,
# then execute the next block separately; never ignore an unknown connection/status error.
```

This intentionally stops on an absent migrations table; after verifying the *same* empty database and recording initial authorization, the migration block below is the distinct next step. No `migrate:fresh`, demo seeding, DEV/UAT data copy or destructive reset is permitted. A first release has no prior app digest to roll back to: keep ingress closed, preserve failed state and use reviewed forward repair.

## Backups and subsequent releases

Before any subsequent migration: identify previous digest/schema/config, approve compatibility and paired restore decision, ensure free backup space plus encrypted off-host destination. Acquire the same lock. Save previous manifest in history. Quiesce all ingress/uploads/writers, `dc exec -T app php artisan down --no-interaction`, and wait for in-flight requests to drain. Framework maintenance marker must persist across app replacement. Do not assume the DB snapshot and files are consistent while any other writer runs.

Example backup block (same authorized shell; app is healthy enough to enter maintenance; no other writers). Container `--rm` below removes **only its own one-off helper**, not volumes or DB. It uses the verified app image, UID 33, network none and read-only source volumes; its archive streams into a private host file. Review this resource creation/removal explicitly before execution.

```bash
backup="$ROOT/backups/$(basename "$history")"
mkdir -m 700 "$backup"
# Protected client options include user/password for this UAT-only backup role.
db_id=$(dc ps -q db)
test -n "$db_id"
ld cp "$CFG/backup.cnf" "$db_id:/tmp/dcms-uat-backup.cnf"
dc exec -T db sh -eu -c 'chmod 600 /tmp/dcms-uat-backup.cnf; trap "rm -f /tmp/dcms-uat-backup.cnf" EXIT; mysqldump --defaults-extra-file=/tmp/dcms-uat-backup.cnf --single-transaction --no-tablespaces --triggers --routines --events --databases dcms_uat' \
  > "$backup/database.sql"
test -s "$backup/database.sql"
for volume in dcms-uat-xppl_documents dcms-uat-xppl_private; do
  test "$(ld volume inspect --format '{{index .Labels "com.docker.compose.project"}}' "$volume")" = dcms-uat-xppl
  test "$(ld volume inspect --format '{{index .Labels "com.docker.compose.volume"}}' "$volume")" = "${volume#dcms-uat-xppl_}"
done
ld run --rm --network none --read-only --user 33:33 --cap-drop ALL --security-opt no-new-privileges \
  --entrypoint tar \
  --mount type=volume,src=dcms-uat-xppl_documents,dst=/documents,readonly \
  --mount type=volume,src=dcms-uat-xppl_private,dst=/private,readonly \
  "$image" -cpf - -C / documents private > "$backup/documents.tar"
(cd "$backup" && sha256sum database.sql documents.tar > SHA256SUMS)
```

The helper checks both exact volumes' Compose project/volume labels and existence first; a missing/foreign volume stops the block. Docker `--mount` for a volume may create a missing volume, so this review and the held deployment lock are mandatory. UID 33 must own/read the document files; if not, stop rather than broaden permissions. If this helper is not approved, use a separately reviewed host backup tool. `mysqldump` exit failure stops the block; restrict/remove partial artifacts only after operator review. If `ld cp` succeeds but dump does not start, manually remove only that temporary client file from this UAT DB. Backup SQL/private tar contain confidential material: encrypt using the approved organization tool, transfer off-host, verify checksums and key recoverability, and **verify completion before migration**. Copy protected APP_KEY/config, release manifest, attachment hashes/metadata and cut-off time into the encrypted recovery set. No automatic retention/deletion schedule. Dumps without private files, or files without corresponding metadata, are incomplete recovery sets.

Restoration is a **separate approved drill on a fresh isolated target**, never a command against this host's existing DB/volumes. Restore matching DB/files/config/key, check triggers/definers/grants, migration state, attachment bytes/hashes/history, authorization denial, numbering monotonicity and audit/custody continuity; measure RPO/RTO. Approve storage/retention and drill evidence before user UAT.

## Distinct migrations, app replacement and health

For first initialization use the recorded empty-target recovery decision; for later releases require a verified paired backup. Preflight cannot certify SQL compatibility: review manifest inventory/changes against status and baseline, migration DDL/trigger privileges and forward/backward compatibility. Keep migration output owner-only and redact before sharing.

```bash
# Same locked shell, after the explicit backup/initialization gate above.
python3 - "$CFG/migration.env" <<'PY'
import pathlib, stat, sys
p = pathlib.Path(sys.argv[1])
assert stat.S_IMODE(p.stat().st_mode) == 0o600 and p.stat().st_uid == 0
values = dict(line.split('=', 1) for line in p.read_text().splitlines() if line and not line.startswith('#'))
assert set(values) == {'DB_USERNAME', 'DB_PASSWORD'} and all(values.values())
assert values['DB_USERNAME'] == 'dcms_uat_migrate'
PY
dc run --rm --no-deps -T --entrypoint php --env-from-file "$CFG/migration.env" app \
  artisan migrate --force --no-interaction > "$history/migrations.txt" 2>&1
dc run --rm --no-deps -T --entrypoint php --env-from-file "$CFG/migration.env" app \
  artisan migrate:status --no-interaction > "$history/migrations-after.txt" 2>&1
dc up -d --no-deps --force-recreate --wait --wait-timeout 180 app
dc exec -T app id > "$history/runtime-user.txt"
dc exec -T app apache2ctl -t > "$history/apache-config.txt" 2>&1
dc exec -T app apache2ctl -M > "$history/apache-modules.txt" 2>&1
dc exec -T app apache2ctl -t -D DUMP_RUN_CFG > "$history/apache-runtime.txt" 2>&1
dc exec -T app php artisan migrate:status --no-interaction > "$history/runtime-db-status.txt" 2>&1
dc ps > "$history/compose-status.txt"
# Confirm UID 33, prefork, two workers, effective limits, healthy DB/app and writable private mounts.
# Maintenance is still enabled after subsequent releases. Review internal evidence first.
dc exec -T app php artisan up --no-interaction
curl --fail --silent --show-error --output /dev/null http://127.0.0.1:18084/up
code=$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' http://127.0.0.1:18084/storage/private-check)
test "$code" = 403
# Do not reopen user ingress until approved HTTPS and application acceptance checks pass.
printf '%s\n' 'Internal checks passed; HTTPS/user verification and approval still required.' > "$history/result.txt"
```

`migrate:status` also verifies runtime DB connectivity but does not replace representative workflows. Verify HTTPS certificate/hostname/port (no insecure TLS bypass), login/session/secure cookies, English/Lao, incoming/outgoing/controlled documents, permitted synthetic attachment upload/retrieval and unauthorized denial, dashboard/reports/audit. Record metadata/hashes and compare document numbers/files before/after app-only recreation; never force-recreate DB or delete volumes. Health `/up` checks Laravel boot; Docker does not restart an unhealthy-but-running app automatically. Keep errors/incomplete release recorded; a failed block must not imply UAT acceptance.

On migration failure stop admission, keep maintenance, record which DDL committed and escalate for a forward repair. **Application rollback does not reverse schema/data.** Never automatically run `migrate:rollback`. If previous image supports current schema, select previous manifest/digest in protected config, validate it with UAT preflight, repeat app-only replacement/health while keeping DB/volumes. If incompatible, remain in maintenance and obtain explicit paired restore/data-loss authorization. On approved Nginx configuration failure remove/revert only the newly installed DCMS block, validate globally with host owner, and recheck NetBox; never restore a generic/default Nginx configuration over existing services. Keep deployment history, prior digest, logs, approvals and recovery results.

## Activation checklist and local limitations

Required separately: approve Git push/PR/merge; package publication and environment/reviewer controls; exact candidate full run/digest; host changes/administrator session; DBA accounts and trigger policy; DNS/hostname/TLS/8444 firewall change; validated proxy source; capacity baseline/NetBox stop thresholds; secrets/backup encryption/off-host location/retention/recovery owner; paired restore drill; migration and first-release change windows; XPPL UAT approvers. Formal UAT acceptance and later Production authorization remain separate.

Local checks render Compose and exercise plan refusal paths without contacting Docker Engine. No Nginx binary/TLS/certificate/runtime check or new constrained-container execution is claimed. MySQL memory/startup, Apache worker effectiveness, real proxy source, host resource competition, authenticated upload flows, backup/restore and rollback still need separately approved first-start/drill evidence. Current CI smoke proves the base image, **not this constrained shared-host UAT overlay**. No server settings, credentials, GitHub environment, publishing workflow or deployment was activated.

References: [Compose service limits](https://docs.docker.com/reference/compose-file/services/), [Apache worker limits](https://httpd.apache.org/docs/2.4/mod/mpm_common.html#maxrequestworkers), [Nginx proxy headers](https://nginx.org/en/docs/http/ngx_http_proxy_module.html), [MySQL memory](https://dev.mysql.com/doc/refman/8.4/en/memory-use.html), [private GHCR authentication](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry).
