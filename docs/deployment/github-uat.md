# Manual DCMS UAT deployment

This procedure supersedes the former OIDC/release-selection and off-server restore enrollment procedures for **UAT only**. It follows XPPL Toll's pattern: a manual GitHub Actions job invokes a fixed administrator-installed command. DCMS reuses its existing dedicated runner, pinned SSH access, Docker project, database and volumes. There is one active UAT workflow, `.github/workflows/deploy-uat.yml`; no changes to Production, NetBox or application authentication/authorization are included.

## Routine operation

1. Develop on feature branches, run focused tests, then use the existing CI/review process to merge into protected main.
2. Use the existing **Authorized release image** workflow for the approved main commit. It performs full CI and publishes the tested image. Copy the immutable `ghcr.io/pintybhatt/psg-document-control@sha256:...` reference from its release manifest. No new publisher or mutable `latest` tag is introduced.
3. Open **Deploy DCMS UAT**, select **main**, enter that digest and type `DEPLOY_UAT`. The digest's source label must equal the dispatch's main commit. If main has advanced, publish/approve its image before dispatching. No advanced rollback IDs, OIDC tokens or off-server restore configuration are required.
4. Read the deployment job outcome. On failure, keep admission closed and inspect the root-only history/recovery flag; do not automatically roll back schema or erase the flag to retry.

This is authenticated operator approval of the input digest, not an automated proof connecting that input to a successful release run. CI and main review remain the regression gate. Only trusted repository operators may dispatch this privileged workflow or edit main/workflows; keep PR approval and required CI checks. Existing GitHub environment controls remain effective; no additional approval stage is added.

## What the server does

The sole fixed SSH/sudo entry point invokes `docker/deploy-uat-manual.py`. It accepts only an immutable DCMS image, full source commit and numeric run/attempt. It pulls that image and verifies Linux/amd64 and repository/source labels, checks the existing DB container, persistent mounts, current digest and live migration baseline, and refuses edits/removal of deployed migration files.

Under the existing lock, it enters maintenance and stops only the app to quiesce synchronous web writers. It reuses `Deployment` in `docker/deploy-uat.py` to create a new root-private local backup directory under `/srv/dcms-uat/backups/gha-RUN-ATTEMPT` containing:

- `database.sql`, including triggers/routines/events;
- `documents.tar`, containing both document and private file volumes;
- `configuration.tar`, including APP_KEY and recovery credentials;
- `SHA256SUMS` for all three files.

The existing validator reads and checks all SHA-256 values, nonempty files, SQL dump completion marker, archive safety/readability and required configuration members. Local backup verification must succeed before migration. **This is backup integrity verification, not an isolated restore rehearsal or proof of recoverability.** No encryption, Metabase transfer, retention deletion, `verify-backup` hook or isolated MySQL target is required by this UAT path. Local backups and failed-deployment history are retained; administrators monitor disk capacity and manage retention separately.

Migrations use existing dedicated `migration.env` credentials. Only app is recreated with existing Compose files, `--no-deps --no-build --pull never`; the normal image entry point rebuilds config/routes/views caches. DB container identity/start time/mounts/health must remain unchanged. Loopback and configured HTTPS `/up` must return 200, public storage must remain denied, and Docker app health must pass. Failure leaves maintenance/recovery state for administrator inspection; no automatic database rollback, reset, seeding or master-data import occurs.

The approved XPPL UAT master-data import remains a separate one-time operation with its own approval and restore-tested backup requirements. Production's existing explicit authorization and backup requirements are unchanged.

## Activate once — administrator-controlled installation

Nothing in this preparation changes the server. On **192.168.0.228**, stage the reviewed repository bundle at `/root/dcms-reviewed-bundle`. The existing `/srv/dcms-uat/release` must already contain the reviewed Compose/configuration/runtime helpers from this repository. Keep the existing database, containers, files, APP_KEY, TLS hostname and credentials. Do not run `compose down`, recreate the DB or delete volumes.

Install only the changed entry point/runtime/validator files:

```sh
sudo bash <<'SH'
set -eu
BUNDLE=/root/dcms-reviewed-bundle
for name in deploy-uat-manual.py deploy-uat.py uat-backup.py; do
  install -o root -g root -m 0644 "$BUNDLE/docker/$name" "/srv/dcms-uat/release/docker/$name"
done
install -o root -g root -m 0755 "$BUNDLE/docker/uat-deploy-entrypoint.sh" /usr/local/sbin/dcms-uat-deploy
SH
```

`deploy-uat.py` remains a shared runtime library; its old OIDC `execute` function is not the privileged entry point. Legacy OIDC/selector/off-server helpers remain available for historical tests but are not called by this workflow or command. The existing forced SSH command `deploy-dcms-uat`, restricted `dcms-deploy` account and exact sudo permission for `/usr/local/sbin/dcms-uat-deploy` remain unchanged. Do not grant a shell, general sudo or Docker access. The account can invoke only this UAT deployment operation; protect its dedicated key accordingly.

Confirm existing root custody and private configuration: `config/compose.env`, `app.env`, `migration.env`, `backup.cnf`, `current.json`, registry pull credentials and `deployment.lock`; keep secret files 0600 and history/backups 0700. Do not overwrite them with examples. `migration.env` must contain only `DB_USERNAME=dcms_uat_migrate` and its password. The existing backup account must have dump privileges. `config/automation.json` still supplies `enabled`, fixed `target=uat`, `project=dcms-uat-xppl` and the approved HTTPS health URL on port 8444. Numeric OIDC operator/runner pins and the old migration opt-in are no longer consulted. Stop if a previous deployment has an unresolved recovery flag; reconcile it through administrator inspection.

In the existing GitHub `uat` environment, retain:

- `ENABLE_UAT_DEPLOYMENT=true` once installation is complete;
- `UAT_SSH_KEY` for the existing restricted deployment account;
- `UAT_KNOWN_HOSTS` containing the independently verified host key for **192.168.0.228**, including its configured port notation;
- `UAT_SSH_PORT` matching the existing SSH listener;
- the existing dedicated runner labels `[self-hosted, Linux, X64, dcms-uat-runner]`, with network access to 192.168.0.228.

The host address is fixed in the workflow; `UAT_HOST` is no longer an input. No new secrets, OIDC setup, Metabase configuration, restore server or approval files are needed. After separately approved publication/installation, run the action as described above. No deployment was performed while implementing or testing this simplification.
