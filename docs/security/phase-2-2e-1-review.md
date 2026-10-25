# Phase 2.2E-1 security integration review

Reviewed on 2026-10-25 against `08cb17d8c871006c2510268a416c3c4d9712f16d`, branch `phase-2-2-organization-users-authorization`. The starting working tree was clean. Runtime versions checked: PHP 8.5, Laravel 13.38.0, PHPUnit 12.5.40 and Vue 3.5.44. Laravel and testing skills, AGENTS.md and Boost documentation informed this review; `.ai/rules` is absent.

The changes address administration identifier disclosure and remove an unused generic file transport. They add integration evidence without changing permission inheritance, introducing document delivery, adding dependencies or initializing accounts. This review does not certify future document modules.

## Reviewed architecture and trust boundaries

- **Organization:** Company, Site and Department ownership, active flags, composite foreign keys, unique keys, relationship immutability and integrity tests were inspected. Memberships and document security records constrain company/site/department combinations. Database checks/triggers supplement model validation; model events alone do not protect arbitrary SQL writes.
- **Authentication:** Login normalizes identity, uses generic credential failures and identity/IP throttling, regenerates sessions, and does not enable persistent remember tokens. Logout invalidates the session and regenerates CSRF. Password changes and deactivation invalidate existing session versions; protected requests recheck account state and version. JSON requests receive 401 when unauthenticated or revoked.
- **RBAC:** `AccessControl`, Gates, `AuthorizationScope`, role/permission assignments, requests, controllers and mutation services deny unknown/unavailable keys. Effective permissions combine active roles and assignments, checking the account and scope from the database. Global authorization can cover company/site; company can cover its sites; site cannot cover its company, sibling sites or global scope. No authorization result cache preserves revoked access.
- **Membership:** `MembershipAccess` requires an effective exact company, site or department affiliation for the requested operational context. Company membership does not imply site membership; site membership does not imply department membership or company-wide access. Inactive/revoked affiliations and inactive parent units are ineffective. Membership never supplies a missing permission.
- **Administrative exception:** Only the seven catalog keys for identity visibility, roles, assignments and memberships bypass ordinary operational membership, within the explicitly granted RBAC scope. The initial Security Administrator template has five identity/role keys; membership administration is a separate explicit maintenance action. Clearance/profile/allowlist management is outside this exception and needs its own key plus exact operational membership. Administration never supplies document-reading permission or clearance.
- **Document security:** `DocumentAccess` requires independent metadata/preview/download keys, exact membership, active resource ownership and classification entitlement. Normal needs no clearance; Confidential needs an exact Confidential clearance; Highly Confidential needs its exact clearance and an operation-specific document allowlist. Restricted resources of any classification require an allowlist. Entitlements are not interchangeable or inherited across departments. Expired, inactive and revoked grants deny access. Security management refreshes persisted grants and rejects self-grants, including reactivation/extension of the actor's own grant.
- **Resource trust:** `DocumentSecurityDescriptor` has a private constructor and retains persisted profile identity, type and key. Each evaluation reloads the profile; request arrays, unsaved profiles, unsupported operations and inconsistent scope are denied/rejected. It cannot prove that a caller paired the profile with the correct future document row: atomic server-side binding remains a module responsibility. Raw policy services accept a trusted user subject; HTTP authentication/session validation must precede them.
- **Bootstrap:** The ordinary seeder grants nothing. Repeatable catalog initialization preserves existing role permissions. Initial administrator assignment is CLI-only, requires an existing active account, explicit scope/operator and additional global confirmation, and refuses revoked assignments or an operationally augmented administrator template. Supported RBAC mutations serialize through a cache lock and database guard row, audit atomically and preserve the last effective administrator, including permissions split across roles. Raw SQL/pivot writes are outside those service protections.

## Executable authorization matrix

`SecurityAuthorizationMatrixTest` exercises role and membership listing through HTTP, an actual Highly Confidential clearance-management mutation, and all three document policy operations. Yes means only the tested operation at its authorized target scope; it does not imply unrestricted administration.

Except where stated, role-bearing fixtures deliberately receive the tested administrative, security-management and document keys. Document fixtures have exact Highly Confidential clearance and per-operation allowlist grants to isolate role/membership decisions. These fixtures are test data, not role-template defaults. The department case uses an owning-department resource; other cases use a site resource. Company-role inheritance is exercised at the resource's site.

| Scenario | Roles list | Memberships list | Grant HC clearance | Metadata | Preview | Download |
| --- | --- | --- | --- | --- | --- | --- |
| Unauthenticated | No | No | No | No | No | No |
| Authenticated, no roles | No | No | No | No | No | No |
| Membership, no role | No | No | No | No | No | No |
| Explicit roles, no membership | Yes | Yes | No | No | No | No |
| Company role + company membership, site resource | Yes | Yes | No | No | No | No |
| Company role + exact site membership | Yes | Yes | Yes | Yes | Yes | Yes |
| Site role + exact site membership | Yes | Yes | Yes | Yes | Yes | Yes |
| Site role + exact owning-department membership | Yes | Yes | Yes | Yes | Yes | Yes |
| Target-site role + sibling-site membership | Yes | Yes | No | No | No | No |
| Target-site role + other-company membership | Yes | Yes | No | No | No | No |
| Central administrative keys only, no membership | Yes | Yes | No | No | No | No |
| Inactive account | No | No | No | No | No | No |
| Revoked session, after authentication middleware | No | No | No | No | No | No |
| Inactive role | No | No | No | No | No | No |
| Revoked role assignment | No | No | No | No | No | No |
| Inactive membership | Yes | Yes | No | No | No | No |
| Revoked membership | Yes | Yes | No | No | No | No |
| Administrative/management keys + metadata only | Yes | Yes | Yes | Yes | No | No |
| Administrative/management keys + preview only | Yes | Yes | Yes | No | Yes | No |

Existing RBAC, membership and document suites additionally cover multiple roles/memberships, company-wide operational membership, different companies/sites/departments, inactive companies/sites/departments, each classification, unavailable/unknown permissions, unknown operations, expired/revoked grants and no automatic Security Administrator content access. No document route is exercised because none exists.

## Findings and disposition

| ID | Severity | Finding, evidence and disposition |
| --- | --- | --- |
| E1-01 | Low | **Fixed: record-existence disclosure.** Implicit model binding ran before request authorization (existing record 403 versus absent record 404). Scoped administrators also distinguished foreign records, and global `exists` validators disclosed foreign identity/unit existence. Eighteen reproduction cases failed before the corrections. Controllers now resolve targets after authorization through scoped queries; assignment/unit validators restrict eligible identifiers; scope authorization checks syntax before evaluating authority without an existence-specific error. Paired existing/absent responses now match: unauthorized operation 403, hidden target 404, ineligible identifier 422. Guest/revoked sessions remain 401. No hidden record is mutated. Existing regression expectations were updated only for these status contracts. |
| E1-02 | Medium, latent delivery risk | **Fixed: generic signed local storage transport enabled.** Full route inventory exposed Laravel GET/PUT `/storage/{path}`. Installed framework handlers accept signed bearer URLs without DCMS session, membership or confidentiality evaluation. No document leak was demonstrated, and no delivery module exists. `local.serve=false` removes both generic routes; tests verify 404 and absence of their route names. Future delivery must explicitly enforce document policy. |
| E1-03 | Medium, future delivery blocker | **Verified limitation: access-attempt audits share the caller's transaction.** Both allowed and denied access rows disappear on rollback, including a denial exception escaping a transaction. They are not an independent durable audit sink. A concrete recommendation appears below; no new infrastructure was introduced. |
| E1-04 | Low, defense in depth | **Verified limitation: RBAC/membership audits have model-only immutability.** Direct query-builder UPDATE succeeds for both audit tables; document audit UPDATE/DELETE is rejected by existing database triggers. No browser audit-edit API exists. Preserve trusted database access boundaries; review an additive migration to extend database immutability and restrict write/DDL privileges. This phase records the boundary rather than expanding the schema. |
| E1-05 | Medium, future delivery contract | **Verified limitation: checks do not cancel an in-flight authorization decision.** A controlled query-event interleaving revokes clearance after its SELECT has completed: that evaluation permits, the next denies. Fresh policy reads eliminate application cache staleness, but do not promise linearizability or fresh visibility inside a long database snapshot. Delivery and ownership-change protocols need explicit integration controls below. |

The disclosure correction deliberately replaces implicit model binding where its lookup order exposed existence. Management services still refresh and reauthorize mutations inside their existing serialization/transaction boundary. Inherited shared role definitions remain visible where intended; local administrators still cannot edit an ancestor's definition or delegate keys they do not hold.

Additional boundaries: role codes remain a globally unique namespace, so an authorized role creator can detect a code collision; this does not reveal the role's configuration or assignments. Boost's development log collector is configuration-dependent, as detailed in the endpoint inventory. Neither behavior is represented as document authorization.

## Endpoint inventory and request boundaries

Inventory used `php artisan route:list` with vendor routes included, expanded middleware, and a separate `APP_ENV=production APP_DEBUG=false` invocation. After the storage correction that production inventory has 31 route entries; the local inventory additionally includes the Boost collector. No route/config cache was changed.

| Category | Existing endpoints and boundary |
| --- | --- |
| Public | GET `/` welcome; GET `/login` and POST `/login` under guest middleware; GET `/up` framework health. Login POST requires web CSRF and applies authentication throttling. |
| Authenticated pages | GET `/dashboard`, `/incoming`, `/outgoing`, `/controlled-documents`, `/document-search`, `/reports`, `/administration`. Authenticated, active, current-version sessions; these shells/placeholders are **not resource-authorized document workflows**. POST `/logout` uses the same session boundary and CSRF. |
| Authenticated context APIs | GET `/api/v1/user`, `/api/v1/authorization/contexts`, `/api/v1/organization-contexts`. Return explicit current-user fields and filtered effective authorization/operational contexts. No unrestricted user directory. |
| Role APIs | Under `/api/v1`: GET `/roles`, `/roles/{role}`, `/permissions` require `roles.view`; POST `/roles`, PATCH `/roles/{role}` require `roles.manage`. Scope, controlled catalog, delegation and self-change checks apply. |
| Role-assignment APIs | GET `/role-assignment-users` requires `users.view`; GET `/role-assignments` requires `assignments.view`; POST `/role-assignments` requires `assignments.manage` + `users.view`, eligible target, delegation and explicit global confirmation. DELETE `/role-assignments/{assignment}` requires scoped `assignments.manage` with self/delegation protections. |
| Membership APIs | GET `/memberships`, `/membership-units` require `memberships.view`; GET `/membership-users`, POST `/memberships` require `memberships.manage` + `users.view`; PATCH/DELETE `/memberships/{membership}` require `memberships.manage`. Visibility, eligibility, exact unit ownership, self-change and lifecycle checks apply. |
| Development preview | GET `/dev/ui-components`: local/testing only, then authentication and active/versioned session. Returns 404 even to administrators in staging/production; guests receive 401 for JSON in local/testing. |
| Vendor development collector | POST `/_boost/browser-logs` has no authentication/CSRF boundary. Boost registers it only when enabled, outside unit tests, and either local or `app.debug=true`. It is absent in the inspected production/debug-false inventory. Production must use debug false and exclude development tooling; debug true can enable this collector outside local. |
| Deliberately absent | No document discovery/content/file-delivery, upload, clearance-management, public registration or unrestricted user-management APIs. Generic signed local-file GET/PUT routes are now disabled. |

All application APIs use the web session and active-session middleware. Their mutation requests require CSRF; new tests exercise every administration mutation verb without a token (419). Permission checks are in Form Requests/services, not visible as separate permission middleware in `route:list`. Frontend control visibility is not an authorization boundary.

## Audit integrity, rollback and durability

| Event type | Current behavior | Evidence |
| --- | --- | --- |
| RBAC, membership and document-security mutations | State and its audit are atomic in the same transaction. An outer rollback removes both; audit exceptions abort mutations. | New rollback cases for role, membership and clearance; RBAC audit-write failure; existing membership/document mutation tests. |
| Allowed sensitive access | Audit INSERT must succeed before the policy returns permission. A later caller rollback removes that row. | Explicit caller rollback, successful transaction and allowed-write-failure cases. |
| Denied sensitive access | A false result/authorization exception follows an audit INSERT. A caught denial keeps the row on the current connection; a later rollback, or exception escaping `DB::transaction`, removes it. Audit failure raises instead of producing an allowed response. | Explicit denied rollback, exception, caught-denial and denied-write-failure cases. |
| Ordinary administration denials | Do not universally generate RBAC/membership mutation audits; they are not sensitive-document access attempts. | Failed administration requests leave mutation audit counts unchanged. |
| Immutability | All three audit models reject update/delete; only document security audits additionally have database UPDATE/DELETE triggers. Triggers do not prevent transaction rollback or privileged DDL. | Existing model/trigger tests and new direct-query boundary tests. |

These tests use isolated SQLite with test transactions/savepoints. Retaining a row inside the test connection is not proof of a disk commit, crash durability or external delivery. No transaction-independent denial logging is claimed. Access events store actor/profile IDs, operation, outcome/reason and timestamps; security mutation snapshots contain security attributes. They do not include passwords, tokens, document contents or attachment data. Future `resource_key` values must be opaque document identifiers, never filenames or payloads.

**Recommended durable access-attempt architecture, requiring review before implementation:** separate transactional mutation auditing from an append-only access-attempt sink. Use a synchronous acknowledged write to an independently committed connection/store or collector, carrying an event UUID, actor ID, opaque resource ID when safely resolved, operation, allowed/denied outcome, bounded reason and timestamp. Apply append-only credentials, restricted readers, retention and capacity monitoring, bounded timeouts and idempotent retries keyed by event UUID. Do not tie external audit records to uncommitted application rows by foreign keys. A failed required acknowledgement must block protected delivery; define denial failure handling without disclosing resource details. A same-transaction outbox or `afterCommit` callback cannot preserve denials from rolled-back transactions. Test rollback, collector outage, duplicate acknowledgement, retry and crash behavior before adopting it. Nothing in this review installs such a sink.

## Required controls for persisted resources and delivery

1. Bind each future persisted document to its security profile atomically; validate resource family/key and ownership from server-loaded rows. Never construct a descriptor from a browser-selected profile and assume it belongs to the requested document. Keep ownership/classification changes in an audited, serialized service. Current model writes intentionally reject ownership/declassification changes; raw fixture updates in the tests only simulate future authoritative changes.
2. Authenticate and validate the current session, then authorize the exact metadata, preview or download operation immediately before generating protected headers, metadata or bytes. Do not derive download from preview or discovery. Fail closed on unknown/inconsistent resources and audit-write failure. Central security administration remains outside document entitlements.
3. Apply equivalent visibility constraints before search results, pagination totals, exports and relationship expansion. Do not leak filenames, content lengths or restricted record existence through errors or headers. Do not use an access-auditing policy call as an unbounded search filter; design bounded discovery/query authorization and its audit contract explicitly.
4. Avoid long-lived repeatable-read snapshots around policy evaluation. Recheck using current committed state immediately before delivery; define locking/security-version protocols for ownership changes and delivery if stronger concurrency guarantees are required. An already authorized/streaming response cannot be retroactively withdrawn. The new interleaving test is deterministic and same-connection, not a multiprocess or MySQL isolation proof.
5. Keep protected storage private, with no public symlink/bucket route or generic temporary URL bypass. Any future signed delivery mechanism needs an explicit session, operation, expiry and revocation design. Ensure response/cache behavior cannot preserve revoked authorization.
6. Review the independent audit sink and RBAC/membership database immutability, plus production database/cache permissions, shared mutation locks and deployment configuration, before operational rollout. Audit reading/reporting needs its own scoped permissions and disclosure rules.

## Verification and remaining handoff

| Check | Result |
| --- | --- |
| New `SecurityEndpointBoundaryTest` | 32 cases, 108 assertions passed; identifier comparisons, CSRF, inactive/revoked sessions, development preview and disabled generic transport. |
| New `SecurityAuthorizationMatrixTest` | 19 scenarios, 141 assertions passed. |
| New `SecurityAuditReviewTest` | 14 cases, 37 assertions passed, including explicit tests of documented limitations. |
| New `SecurityPolicyIntegrationTest` | 6 cases, 15 assertions passed; reused descriptor ownership/classification, forged loaded management scope and in-flight revocation. |
| `php artisan test` | 335 tests, 1,346 assertions passed. Includes existing organization, authentication, RBAC/bootstrap, membership and document-security regressions. |
| `npm test` | 118 tests across 9 files passed, including existing English/Lao administration and membership behavior. |
| `npm run build` | Passed. No frontend source changes. |
| `vendor/bin/pint --dirty --format agent` | Passed. |
| `git diff --check` | Passed. |
| SQLite | Full isolated test suite migrated and exercised constraints/triggers; no application database migration was executed. |
| MySQL 8.4 | Not rerun in this phase; no existing disposable instance was used and none was provisioned. No migration/schema changes were introduced. Prior phase evidence is not a substitute for rerunning the new integration tests on deployment's database/isolation configuration. |

Read-only schema inspection found the actual development database has the RBAC tables but has not applied the membership/document-security migrations. Before/after read-only counts remained zero for users, roles and user-role assignments. No account, privilege, membership, clearance or organization initializer was executed against that database. This runtime schema lag is preserved for the operator's reviewed migration process.

Changed files (9 modified, 5 new):

- `app/Http/Controllers/MembershipController.php`
- `app/Http/Controllers/RoleAssignmentController.php`
- `app/Http/Controllers/RoleController.php`
- `app/Http/Requests/AssignRoleRequest.php`
- `app/Http/Requests/MembershipRequest.php`
- `app/Http/Requests/RbacScopeRequest.php`
- `config/filesystems.php`
- `tests/Feature/MembershipAdministrationTest.php`
- `tests/Feature/RbacAdministrationTest.php`
- `tests/Feature/SecurityAuditReviewTest.php` (new)
- `tests/Feature/SecurityAuthorizationMatrixTest.php` (new)
- `tests/Feature/SecurityEndpointBoundaryTest.php` (new)
- `tests/Feature/SecurityPolicyIntegrationTest.php` (new)
- `docs/security/phase-2-2e-1-review.md` (new)

The two existing test files adjust denial status expectations; four new integration test files and this report supply evidence. No dependencies, migrations or operational modules changed. Changes remain uncommitted and unstaged on the required branch at the starting HEAD. Deployment must rebuild applicable configuration/route caches and confirm the generic storage routes remain absent; no runtime caches were rebuilt here.

Recommended Phase 2.2E-2 handoff: review audit sink/durability requirements, additive audit-table protections, the globally unique role-code disclosure tradeoff and the future persistence/delivery contracts above. Recommended Phase 2.2E-3 handoff: isolated MySQL 8.4 and concurrent-worker verification, deployment/debug/tooling/storage checks and reviewed acceptance evidence. These are recommendations, not implementations or claims that those later phases are complete.
