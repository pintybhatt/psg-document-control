# Production configuration foundation — Phase 12.2A

This phase prepares local configuration only. Nothing has been installed, initialized,
published or deployed. Production deployment automation and the initial server setup
procedure remain separate work; there is no `deploy-prd.yml` workflow.

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
It does not layer the UAT overlay or use UAT deployment scripts. UAT remains project
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
paths. The app database password must match `db-password`; the root password must
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
Phase 12.2A. Copying templates or passing preflight does not authorize deployment.
