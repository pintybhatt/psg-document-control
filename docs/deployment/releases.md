# Private GHCR and controlled UAT release

> The prepared, disabled GitHub Actions deployment path is now documented in [GitHub UAT automation](github-uat.md). It supersedes manual-only automation statements below; existing bootstrap, backup and ingress procedures remain applicable. No server or GitHub control was activated.

Preparation candidate: `a7e0a5ded145319c5c60fd7e122cc42e14599c10`, verified against clean local main, origin/main and GitHub main on 2026-10-28. Includes SOP B1 numbering PR #5 and MySQL assertion correction PR #6. **Publication and deployment remain off.** This change moves the disabled publisher into `.github/workflows/release-image.yml` for PR review; no dispatch, registry write, setting change or server access is authorized by this preparation.

## Candidate evidence and GitHub readiness (2026-10-28)

| Evidence/control | Observed state / required action |
| --- | --- |
| PR Fast CI | [PR #6 run 40636342386](https://github.com/pintybhatt/psg-document-control/actions/runs/40636342386) succeeded for `f0077dd9bd1c62f0063236c69174c9b013cb1e02`; PR full checks are deliberately skipped and are not release evidence. |
| Exact-source push validation | [Run 40637101021, attempt 1](https://github.com/pintybhatt/psg-document-control/actions/runs/40637101021) is a successful main push for the candidate. Frontend, PHP / SQLite, Fast CI, MySQL 8.4, Image / smoke and Full release validation all succeeded; exact-image smoke succeeded. |
| Retained-image release | Not established: archive retention/upload steps were skipped in that run; artifacts contain only frontend and SQLite/MySQL results. No candidate release manifest/digest exists in this evidence. Require a new authorized manual release run with same-run full validation, retained archive, successful publish and manifest upload. |
| Publishing switch | Repository Actions variables list is empty: `ENABLE_IMAGE_PUBLISHING` is absent, so publication is off. Leave it absent/false through PR review. Environment list is also empty. |
| Main protection | GitHub main reports `protected=false`, protection endpoint says branch not protected, repository rulesets list is empty. PR/reviewer/Fast CI/no-force-push controls below remain pending. |
| Actions permissions | Enabled, allowed actions `all`, SHA pinning not enforced by repository setting. Default token permissions are `read`; Actions cannot approve PRs. Workflow actions remain pinned by SHA. Owner should decide whether to restrict allowed actions/enforce pinning. |
| Release environment | No environments exist; `release-image` lookup returns 404. Owner must configure approved main-only environment controls before publication; a workflow name alone provides no protection. |
| Actual account plan | Authenticated `pintybhatt` account API returned `plan: null`; plan entitlement could not be established. Owner must verify billing/feature availability. Do not infer reviewer enforcement from a private repository or successful environments-list request. |
| Private package and ACLs | Not verified by this preparation. Owner must confirm package identity, privacy, repository Actions write access and all existing tags with authorized package metadata access. No registry credential created. |

Proposed initial version: **`v1.0.0-rc.1`**, subject to owner approval and unused-tag check. Proposed source and initial baseline are both `a7e0a5ded145319c5c60fd7e122cc42e14599c10`; equal baseline yields no migration diff, so review the entire migration inventory for an empty first target. The release workflow revision will be the reviewed main commit containing this activation, distinct from the application source candidate. Reusable CI runs from that reviewed workflow revision and checks out the selected source.

Outstanding owner decisions: approve source/baseline/version and publication change reference; verify plan and choose enforceable environment review or an explicitly documented manual approval process; configure main protection and main-only release environment; confirm initial package absence/privacy/ACLs and authorize `allow_initial_private_package` separately. A metadata 404 alone never proves package absence. Keep package administrators from concurrent visibility/tag changes; this workflow serializes its own releases, not external writers. No public-package fallback or overwrite is permitted.

Publication gate: only after reviewed PR/merge and the above controls/owner approval may a separately authorized operator enable the publishing switch and manually dispatch from main. This preparation authorizes neither action. Deployment gate: even a successful publication requires separate exact-digest UAT approval, verified evidence, migration/recovery review and host-change authorization under the UAT runbook; Production remains outside scope.

Next operator action: review this local commit and obtain authorization to push the feature branch/open the activation PR, keeping publication off. Resolve the readiness items before any publication authorization. Do not dispatch or access UAT as part of PR preparation.

## Release identity and inactive publishing

1. Reviewed feature PR passes **Fast CI**, merges to main. An owner then approves source SHA, baseline SHA, version and publication change reference. Candidates must include this tooling; an older checkout lacking helpers must fail rather than bypass checks.
2. Review `.github/workflows/release-image.yml` and its updated safety tests in the activation PR. The disabled template has been moved, avoiding two publisher copies. Merge only through the approved review process; keep the repository publishing switch absent/false until separate publication approval and control setup are complete.
3. The dispatcher must run from `main` in `pintybhatt/psg-document-control`. The identity job validates full source/baseline SHAs, ancestry on main, version and change-reference syntax. Manual dispatch/reference is a recorded owner decision, not software authentication of a reviewer.
4. Reusable CI checks out that exact source, performs complete SQLite/frontend/security/MySQL regressions, builds the single Linux AMD64 image **once**, and smokes that image ID. No publication permission is granted to those jobs. Only successful smoke can archive it. The full aggregate requires all validation jobs to succeed before publishing can start.
5. Same-run artifact includes Docker archive, archive SHA-256, tested image ID and source SHA. Publication verifies the checksum, loads that archive, checks image ID and OCI source/revision, then tags **that image**. It never calls Docker build or reinstalls dependencies. Artifact expiration requires a new full candidate run, not an unverified rebuild under an old approval.
6. A read-only policy check refuses a public package or an existing version-plus-source tag. For the first push only, the owner must separately confirm the package is absent and explicitly select `allow_initial_private_package`; a 404 is ambiguous and is **not** proof of absence. GitHub documents that newly created packages default private. The job checks private visibility again immediately after push. Any HTTP/permissions/rate error fails closed; it never changes visibility. Package administrators must not change visibility concurrently. A post-push failure cannot undo disclosure or retract a pushed image automatically; keep all deployment blocked and involve the owner.
7. Query that tag's registry manifest digest, pull by the resulting digest and require its image ID to equal the smoke-tested image ID. A Docker image ID is the config/rootfs identity; it is **not the registry manifest digest**. Both are recorded. No arbitrary first `RepoDigests` entry or `latest` tag is accepted as release identity.
8. Manifest records immutable repository digest, tested image ID, source, baseline, version, platform, release run/attempt/workflow commit, publication approval and migration inventory/hashes. Publish job success includes manifest upload. If push succeeded but later checks/upload failed, this is **not an accepted release**. Do not overwrite the tag or treat a failed run as valid; investigate and approve a new version/full run.

The job is additionally conditional on `ENABLE_IMAGE_PUBLISHING=true` and names the `release-image` environment. The workflow is prepared for review; the publishing variable and environment remain unconfigured as recorded above. After activation, only publishing receives `packages: write` plus `contents: read`, using its short-lived repository `GITHUB_TOKEN`. Checkout credentials are not persisted. Registry login uses stdin; no tracing/token arguments. The GHCR policy reads this token only from `GH_TOKEN`, makes fixed GitHub API metadata requests, refuses redirects and prints no response body/credential. Package API support/permissions and the first real archive/registry round trip remain to be verified in an authorized release; tests use fakes, not registry publication.

## GitHub protection recommendations and plan limits

For the private `main` branch recommend: require PRs; one approving colleague; dismiss stale approvals or require approval of the last push; resolve conversations; require **Fast CI** from GitHub Actions with the PR up to date; forbid force-push and branch deletion; apply rules to administrators/bypass actors, with a documented owner emergency process. Review workflow/release-helper changes carefully. Do not require the deliberately skipped PR **Full release validation** as if it were executed: UAT instead requires a **successful non-skipped** full release run for the selected source and matching release manifest. No permanent develop/uat branch is needed.

GitHub private-repository protected branches require Pro/Team/Enterprise; Free private repositories do not offer the same enforcement. Private environments/secrets require Pro/Team/Enterprise, while required reviewers/wait timers on private repositories are not available on Free/Pro/Team according to current GitHub documentation. Observed controls and the inconclusive account-plan lookup are recorded above; reviewer enforcement remains unverified. A private repository must not be made public to obtain reviewers. If supported, configure release-environment reviewers, restrict to main, prevent self-review and restrict bypass. Merely naming an environment may create an unprotected one on first use; it is not an approval gate.

If private environment reviewers are unavailable, the owner must approve a documented manual publication/change-record process (or an appropriate plan change) before enabling publication. Do not claim an enterprise-strength technical two-person gate from a dispatch input or repository variable. Keep all UAT execution manual and separately owner-approved; no UAT deployment job or credentials exist. GitHub settings/secret/environment changes require explicit subsequent authorization. Read-only control inspection is recorded above; no controls were changed.

## Registry retrieval: choose a dedicated host read credential

| Approach | Permissions and storage | Trade-off |
| --- | --- | --- |
| Actions-driven retrieval/deployment | Job-scoped `packages: read`, repository package read access, and supported environment-scoped SSH credentials/known-host pin; secret access only after approved release gate | Short-lived registry token, but needs approved remote execution, credential transfer and rotation/error handling. Job token expires and must not become a persistent host credential. Not implemented. |
| **Recommended initial UAT: manual operator + dedicated host reader** | Dedicated approved GitHub account, classic PAT with **only `read:packages`**, package read ACL, root-only host Docker client config | Simple, no GitHub SSH key or remote runner. Long-lived token needs expiry/rotation/revocation ownership. Never grant package write/delete or repository write. |

After separate approval, package owner grants the reader **Read** on this private package without broad organization/repository membership where possible. Credential custodian creates the classic PAT in the approved account's GitHub settings, minimum `read:packages`, explicit expiry, clear UAT-only purpose, SSO authorization if applicable. Check the actual scopes before saving; no `write:packages`, `delete:packages`, unnecessary `repo`, or administrative scopes. Never paste token/password into chat, Git, tickets, command arguments or CI logs. Use an approved secret manager/secure operator terminal outside this session.

On UAT, Docker config is `/srv/dcms-uat/registry/config.json`, directory 0700/file 0600. Without a credential helper Docker stores reversible base64, **not encryption**; root/Docker administrators can read it. Prefer an approved helper if available, but none is required/installed by this package. With separately authorized authenticated administrator access and the `ld` function from the UAT runbook, login without displaying the credential:

```bash
# FUTURE ONLY: protected token file has been provisioned out of band, 0600, root-owned.
set -euo pipefail
set +x
: "${DCMS_REGISTRY_READER:?approved dedicated reader login}"
token_file=/APPROVED/PRIVATE/EPHEMERAL/ghcr-read-token
test "$(stat -c %a "$token_file")" = 600
test "$(stat -c %u "$token_file")" = 0
ld login ghcr.io --username "$DCMS_REGISTRY_READER" --password-stdin < "$token_file"
chmod 600 /srv/dcms-uat/registry/config.json
# Remove the temporary token source only via the custodian's approved procedure.
# Pull only after the release/approval checks in the runbook; no test container required.
```

Rotate before expiry or on operator/access changes: create a new least-privilege credential, replace via stdin, verify **approved digest pull** without deploying, then revoke the old PAT in GitHub and update custody records. On suspected exposure revoke immediately, remove affected registry login/config via approved host procedure, investigate and provision a new reader; changing a password or Docker logout alone is not token revocation. Keep credential inventory (account, non-secret token identifier, scope, custodian, expiry, last rotation), package ACL reviews, release approvals/digest pulls and host sudo/operator logs. Review GitHub security log/token last-use metadata and organization audit log where available; do not promise per-pull audit completeness or a retention period without confirming plan/support. Never log token values.

## UAT evidence and approval handoff

After a successful **authorized publishing run**, a maintainer uses already-authorized GitHub CLI access on their workstation (repository Actions **read**, separate from host package reader) to retrieve metadata and the manifest. These read-only commands do not push, publish or connect to UAT. They are shown for future review, **not executed here**. `release_run`/version/source must be selected from the approved publishing run, not a PR CI run:

```bash
set -euo pipefail
umask 077
: "${release_run:?approved numeric release run ID}" "${version:?approved version}"
: "${source:?approved full source SHA}"
[[ "$release_run" =~ ^[1-9][0-9]*$ && "$source" =~ ^[a-f0-9]{40}$ ]]
[[ "$version" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-rc\.[0-9]+)?$ ]]
evidence_dir=$(mktemp -d /tmp/dcms-uat-release-evidence-XXXXXXXX)
repo=pintybhatt/psg-document-control
gh api "repos/$repo/actions/runs/$release_run" > "$evidence_dir/run.json"
gh api "repos/$repo/actions/runs/$release_run/jobs?filter=latest&per_page=100" > "$evidence_dir/jobs.json"
gh api "repos/$repo/actions/runs/$release_run/artifacts?per_page=100" > "$evidence_dir/artifacts.json"
gh run download "$release_run" --repo "$repo" --name "release-manifest-$version-$source" --dir "$evidence_dir/manifest"
sha256sum "$evidence_dir/manifest/release-manifest.json" > "$evidence_dir/manifest-file.sha256"
```

Inspect successful non-skipped `ci / Full release validation`, `ci / MySQL 8.4 (shard 1/4)` through `(shard 4/4)` (or the historical single `ci / MySQL 8.4`), `ci / Image / smoke` including the exact-image smoke step, `ci / Fast CI` and `publish`; verify source against the approved main ancestry and manifest. The workflow run's `head_sha` identifies the **workflow revision on main**, which can differ from the explicitly selected source ancestor. The trusted release workflow verifies that source through CI/archive/OCI checks. API evidence must be fetched directly from the approved GitHub repository and current run attempt; if job count exceeds 100, obtain all pages and merge to one complete jobs object before verification. No best-effort acceptance of truncated jobs.

Owner reviews `docker/uat-approval.example.json`, fills **outside Git** with exact digest/source, manifest file hash, release run/attempt/artifact ID, previous UAT digest (or explicit null for first release), recovery and migration-review references, approver/reference, timezone-aware approval time and explicitly approved expiry. Default decision is **pending**. The owner must make the real authorization decision; never generate an `approved` record merely because tests passed. Approval period/backup retention are organizational decisions, not defaults imposed here.

`docker/verify-uat-release.py` checks the protected approval against downloaded manifest/run/jobs/artifacts, exact source/digest/hash, successful trusted manual-main publishing workflow/current attempt/full gates/smoke, artifact identity/expiry and approval window. It refuses Production/unrelated projects. It never contacts GitHub/Docker, downloads, starts containers, migrates or cleans up. It checks evidence consistency, **not a cryptographic signature or the approver's identity**; root or a writer able to forge the inputs can bypass it. Authentic API retrieval, trusted workflow review and owner approval/custody remain mandatory. Retain the downloaded originals and approval in UAT history; refresh/reapprove after reruns, expiry, any changed manifest or rollback target. Validate immediately before pulling/replacement; if an approved window expires mid-maintenance, keep ingress closed and obtain renewed authorization.

After approval, transfer only reviewed release configuration/helpers and the owner-only evidence bundle through an approved channel. Do not install a self-hosted Actions runner on this shared host. Server flow is [host readiness/directories/authentication](uat.md#future-host-readiness-and-directory-sequence), plan/release evidence → approved digest pull/identity → dedicated DB/bootstrap roles → separate migrations → app health → separately approved 8444 HTTPS → representative application verification. Back up before subsequent migrations; verify an isolated paired restore and rollback compatibility before user admission. The shared host's **NetBox takes priority**; 1,408 MiB/one-CPU DCMS limits are not a capacity guarantee. All health, migration, backup, rollback and history blocks remain in the [UAT runbook](uat.md).

References: [GHCR authentication/default privacy](https://docs.github.com/en/packages/working-with-a-github-packages-registry/working-with-the-container-registry), [package metadata API](https://docs.github.com/en/rest/packages/packages), [protected branches](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/managing-protected-branches), [private environment plan limits](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/manage-environments), [registry manifest inspection](https://docs.docker.com/reference/cli/docker/buildx/imagetools/inspect/).
