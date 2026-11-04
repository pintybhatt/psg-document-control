## Routine DEV → UAT → Production

Develop on a local feature branch and commit locally. Run only tests covering the change, for example `php artisan test --compact tests/Feature/ImportUatXpplMasterDataTest.php` or `npm test -- tests/Frontend/UserManagement.test.js`. Do not rerun both full backend database suites during routine development. GitHub **DCMS CI** is the regression/build gate: frontend tests/build, SQLite and isolated MySQL shards; full release validation also checks the exact container image. Keep security audits and database/numbering tests enabled.

After an approved PR merges to protected `main`, run **Deploy DCMS UAT** from `main`, leaving the default **deploy** operation. The existing pipeline selects verified release evidence or requests the existing publisher. Once enrolled, it automatically quiesces writers, backs up database/private uploads/configuration, verifies the recovery set, applies eligible forward migrations with a separate account, recreates only the app (refreshing caches), and checks database identity, mounts and HTTP health. Failure preserves maintenance and the recovery lock; there is no automatic schema rollback/reset or seeding.

[UAT operation and enrollment](docs/deployment/github-uat.md) describes the required protected GitHub environments, host custody and backup verification hook. [The approved XPPL master-data import](docs/security/phase-10c13-uat-master-data-import.md) remains a separate one-time initialization with its own Product Owner approval and restore-tested backup; routine deployment never invokes it.

Production preparation reuses the release image, `compose.release.yaml`, production preflight and the same backup → migration → cache/restart → health sequence. It remains **disabled** until a Production target/entry point, separate credentials, enforced explicit approval, accepted UAT digest and recovery policy are enrolled. See the Production checklist in the UAT operation guide. No Production deployment is authorized by a UAT dispatch.

This branch predates the deployment infrastructure already on local `main` (`f66f671`); its existing workflows/runtime/helpers and their offline tests were reused without merging unrelated application changes. No dependency update is included: `composer audit --locked` reports CVE-2026-45070 in locked `symfony/mime` 8.1.7; `main` already has the 8.1.10 fix. Reconcile that approved lockfile fix and the branch's application/migration differences before release; never remove deployed migrations or weaken the audit to make deployment pass.

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Current System Administrator authority

An active **global** assignment of the existing `SECURITY_ADMINISTRATOR` role grants full System Administrator authority. The account, assignment and global role definition must all remain active. Authority is resolved from persisted records by `AccessControl::isSystemAdministrator()` on every decision, without email checks, new roles, membership requirements or stored permission expansion. Scoped assignments and other administrators continue to use configured permissions, exact memberships, clearances and document allowlists.

`AccessControl` supplies all available system capabilities and company/site contexts to this administrator. Unknown and unimplemented catalog operations remain unavailable. Future workflows must use this same authorization service and authorize server-loaded resources through `DocumentAccess` for metadata, preview and download. Do not add a blanket `Gate::before` grant: it would skip the document access audit. Resource validity, formal-registration transactions, disabled numbering configurations and immutable issued-number/audit history remain enforced.

`DocumentAccess` permits the global administrator to access Normal, Confidential and Highly Confidential documents without separate clearance or allowlisting, and records each operation with actor, profile, timestamp and `system_administrator` as the reason. Audit exceptions or cancelled inserts fail closed. Audit durability still follows the existing transaction boundary documented in the Phase 2 review. Only a System Administrator may grant, revoke or manage the privileged global role through Administration; explicit global confirmation, self-change protections and the final-administrator invariant remain in effect.

Role labels are displayed as **System Administrator / ຜູ້ບໍລິຫານລະບົບ**, including existing records with legacy stored labels. No account initialization or operational-data changes are needed. The phase descriptions below and historical security reports record earlier boundaries; this section supersedes their statements denying global System Administrator document authority. No document workflows or file-delivery infrastructure are introduced by this authorization change.

## DCMS authentication foundation — Phase 2.2B

Browser authentication uses Laravel's existing `web` session guard. `/login` is bilingual; `/logout` is POST-only. The root welcome page remains public. The seven workspace pages require authentication and an active, non-revoked account session. The development preview additionally requires the local/testing environment at request time, including when routes are cached.

`GET /api/v1/user` uses the same browser session and returns only ID, name, email and preferred language. It returns JSON 401 for guests. There are no registration, recovery, social-login, user-management or organization-mutation endpoints. Phase 2.2B kept Administration as a placeholder; Phase 2.2C below adds scoped role configuration without user or organization maintenance.

Login uses framework password verification, session/CSRF-token regeneration, and generic failure messages for unknown, incorrect and inactive accounts. Throttling permits five failed attempts per normalized email/IP pair and twenty attempts per IP in a minute. Cache keys hash the identifiers; passwords are never put in cache keys or logs. Logout invalidates the session and regenerates the CSRF token. Login and authenticated responses use private/no-store caching where applicable. Keep HTTPS and `APP_DEBUG=false` in production; secure session cookies default to true in production, alongside the existing HttpOnly and SameSite=Lax defaults. Use a shared persistent cache (the existing database default or shared Redis) for cross-process throttling and the bootstrap lock.

Language selection is retained in browser storage and carried in login/logout forms. Valid selections update the user's `preferred_language` on login/logout; the session/profile supplies a fallback when browser storage is unavailable. Passwords are never stored in browser storage or flashed back to forms.

### Controlled initial identity

After applying the account migration, an authorized operator can run:

```sh
php artisan user:bootstrap-admin --name='VERIFIED DISPLAY NAME' --email='VERIFIED EMAIL' --language=en
```

Replace the placeholders with the intended identity. The command requires hidden password and confirmation prompts, without a visible-input fallback. Passwords must contain at least 16 characters, at most 72 bytes (avoiding bcrypt truncation), and no null bytes. Login also rejects passwords exceeding that byte limit, preventing extra suffixes from matching a bcrypt prefix. There is no password argument, environment-variable shortcut or default password. Do not put passwords in shell arguments, scripts or Git. Noninteractive creation is refused.

Creation is transactional and coordinated through an atomic cache lock. An existing email is a no-op: no password reset, rename, reactivation or privilege changes occur. If any other account already exists, creation is refused. Default seeding creates no accounts. The bootstrap command and XPPL initializer were not executed against the development database for this phase.

**Temporary boundary:** “bootstrap admin” identifies the intended future security administrator only. This phase assigns no role, company/site access, confidential-document access or administrator bypass. The account can authenticate to the placeholder workspace just like any active account. Phase 2.2C provides the explicit, controlled initial security-administrator assignment described below. Names and email addresses must never determine permissions.

### Account deactivation and session revocation

Trusted application code must deactivate through `$user->deactivate()` (or a normal model save setting `active=false`). A hidden, random per-user `session_version` rotates on deactivation and model password changes. Every protected request re-reads the account and compares the login snapshot; inactive, deleted or revoked accounts are logged out with their session invalidated. Reactivating an account does not restore previously revoked sessions. Session rows on other devices may remain until expiry, but their old versions cannot authenticate to protected routes.

Do not bulk-update account active/password fields while bypassing model events: raw deactivation is denied while inactive, but raw deactivation/reactivation or password writes must also explicitly rotate `session_version` to revoke old sessions. No web mutation path for these fields is exposed. Existing users receive active/en migration defaults; their nullable initial session version is replaced on the first deactivation/password change. Newly created users always receive a random version.

Phase 2.2B verification used isolated SQLite and jsdom; Phase 2.2C also verifies MySQL 8.4 as described below. Manual browser/screen-reader verification remains a review check. Organizational integrity constraints from Phase 2.2A are unchanged. Full RBAC was deferred from Phase 2.2B to Phase 2.2C below. User/site membership, confidentiality, account-management screens, password recovery and document workflows remain separate phases.

## Configurable RBAC — Phase 2.2C

### Schema and permission evaluation

The RBAC tables are `authorization_scopes`, `roles`, `permissions`, `permission_role`, `user_role_assignments` and `rbac_audits`. Company/site scopes use restrictive foreign keys, including the existing `(site_id, company_id)` integrity key. A unique canonical scope key (`global`, `company:<id>`, `site:<id>`) avoids nullable-composite uniqueness problems. Scope shape/key consistency uses a MySQL CHECK and equivalent SQLite INSERT/UPDATE triggers. Role codes are globally unique uppercase machine codes and cannot be renamed or reparented after creation. Assignments have a unique `(user_id, role_id, scope_id)` key; revocation deactivates the existing row rather than deleting its history.

`App\Authorization\AccessControl` is the central evaluator and backs explicit Laravel Gates for catalog keys. Callers must supply an `AuthorizationScope`; omitting scope denies access. User names, IDs, email addresses, role labels and Vue state never imply privilege. A role's code is used for template initialization/bootstrap only, never as an authorization bypass.

Inheritance is deliberate:

| Assignment scope | Grants apply to | Never implies |
| --- | --- | --- |
| Global | Global, company and site contexts | Confidential document access |
| Company | That company and its sites | Another company or the global context |
| Site | Only that exact company/site pair | Its parent company, a sibling site or global scope |

Role definitions have their own immutable owning scope. A definition may be assigned only within that scope or its descendants. Global templates can be read/assigned in scoped contexts, but can be edited only in global context by an authorized actor. A company/site administrator can create and edit definitions owned by their own scope. New role permissions must be a subset of the actor's effective permissions at that owning scope. Editing an existing role also requires authority over its current permissions, preventing a partial manager from altering a more powerful definition.

Permission checks query current database state and ignore stale loaded relationships. They require an active user, role, assignment, company and site as applicable, plus an available catalog key and active permission row. Role/assignment revocation takes effect on the next protected administrative request without logging out an otherwise active user. Parent deactivation makes its scopes unavailable; reopening a parent restores still-active assignments. Revoking an assignment is a separate explicit action.

`PermissionCatalog` controls the machine keys and availability. Currently operational keys are `users.view`, `roles.view`, `roles.manage`, `assignments.view` and `assignments.manage`. `users.view` exposes only eligible identity fields for role assignment. `users.manage` and the document view/register/control keys are reserved, unavailable and ungrantable through the APIs. Even a forged database permission row cannot introduce an unknown ability. There are no permission-create/update APIs, wildcard abilities or broad administrator bypasses.

### Definition initialization and the initial administrator

These are operator-only procedures for a reviewed deployment; neither command is called by normal seeding or migrations:

```sh
php artisan rbac:initialize --no-interaction
```

This initializes bilingual permission labels and four unassigned role templates: Security Administrator, Document Controller, Department User and Viewer. Existing labels, permissions and active flags on role definitions are preserved on reruns. Security Administrator initially has only the five identity-visibility/authorization permissions above. The other templates intentionally have no effective document grants until document operations are implemented. Newly introduced catalog keys are not silently added to existing roles.

The Phase 2.2B `user:bootstrap-admin` command still creates only an unprivileged identity. To assign the initial security administrator, use an explicitly verified, existing active account. For a site-scoped initialization:

```sh
php artisan rbac:bootstrap-admin --email='VERIFIED EXISTING EMAIL' --scope=site --company=VERIFIED_COMPANY_ID --site=VERIFIED_SITE_ID --operator='VERIFIED OPERATOR LABEL' --no-interaction
```

Company scope requires `--scope=company --company=VERIFIED_COMPANY_ID` and no site. Global scope requires **both** `--scope=global --confirm-global`, no company/site IDs, and the operator label. There is no implicit global default. Replace all placeholders with reviewed values; do not copy organization IDs from another application.

The command requires the active Security Administrator template to contain all five initial administrative capabilities and only those capabilities plus the optional membership-administration pair and refuses unknown/inactive users, mismatched scopes, unconfirmed global access and restoration of a revoked assignment. The same active assignment is a non-destructive no-op; once an effective administrator exists for the requested context, another initial grant must use authorized Administration instead. Transactions and the RBAC mutation lock protect initialization. The append-only audit records the CLI source, operator label, assignment, scope and timestamp; operators must retain their normal host-level execution audit as well. No credentials are created or changed, and no confidential/document capability is granted.

No real role-definition initialization, account bootstrap or role-assignment command was run against the development database during Phase 2.2C. All privilege fixtures used for verification were restricted to isolated test databases.

### Administration APIs and UI

All endpoints use the existing active/revocable browser session and Laravel CSRF protections. JSON guests receive 401; authenticated actors without the required permission receive 403. Permission checks precede target-identifier validation for normal valid contexts, and write services recheck authority inside the serialized transaction.

| Endpoint | Required permission / behavior |
| --- | --- |
| `GET /api/v1/authorization/contexts` | Authenticated; returns only currently authorized active contexts and capability hints, or an empty list |
| `GET /api/v1/roles`, `GET /api/v1/roles/{role}` | `roles.view`; includes applicable shared definitions, scoped pagination and bounded search |
| `GET /api/v1/permissions` | `roles.view`; controlled catalog labels, availability and delegation hints |
| `POST /api/v1/roles`, `PATCH /api/v1/roles/{role}` | `roles.manage` at the exact owning scope; known/delegatable permissions only |
| `GET /api/v1/role-assignment-users` | `users.view`; eligible active accounts only, excluding the actor |
| `GET /api/v1/role-assignments` | `assignments.view`; only assignments made in the exact selected scope |
| `POST /api/v1/role-assignments` | `assignments.manage` plus `users.view`; target eligibility and permission delegation checked; global grants additionally require `confirm_global=true` |
| `DELETE /api/v1/role-assignments/{assignment}` | `assignments.manage`; exact scope, non-self target and authority over the role's permissions; deactivates the row |

Every scoped request supplies `scope_type` and the applicable `company_id`/`site_id`; irrelevant, missing or mismatched organization identifiers are rejected. The server resolves scope independently of Vue. User/role IDs must exist, and an active account/role is required for assignment. Self-assignment/revocation and changes to the security settings of one's own assigned roles are forbidden; display metadata can be edited when otherwise authorized.

Identity lookup has a deliberate Phase 2.2D boundary: global `users.view` can find active accounts group-wide; company authority can find existing active scoped identities within that company; site-only authority can find identities already assigned at that exact site. Local administrators cannot discover or onboard unrelated/unassigned accounts. Central administration can establish the first scoped assignment. Role assignments express authority, not verified employment/department membership. Phase 2.2D-1 below adds explicit organizational membership and extends eligible-account lookup to effective members within the same authorized boundary; the legacy scoped identity lookup remains available for reviewed onboarding.

Administration uses the existing shell, language switcher, bilingual database labels, reusable tables/forms/dialogs/toasts, pagination, bounded identity/role lookup, and explicit confirmation displaying the target account, role and selected scope. UI capability hints hide unavailable controls; inherited definitions and one's own role security settings are read-only. Stale authority is denied by the API and mutation controls are removed after authorization failures. Neither navigation visibility nor client capabilities authorize an operation.

### Final-administrator and audit protections

`RbacMutation` serializes writes with a shared cache lock and a database row lock on the canonical global scope, entering the database transaction before taking authorization snapshots. The global row is a coordination record, not an administrative grant. For every active persisted scope that already has an effective administrator, the transaction must leave at least one active user who collectively holds all five administrative capabilities, potentially through multiple roles and inherited grants. This is capability-based, not dependent on the Security Administrator role name/code. A closed company/site is not an active administration boundary.

The invariant protects controlled role/permission edits, assignment revocation/deletion, and normal user model deactivation (`deactivate()` or saving `active=false`). The operation rolls back if it would remove the final effective administrator. A second eligible administrator must exist before the first can be removed. APIs cannot change their own role grants to evade this rule. Web changes and CLI bootstrap append before/after audit snapshots without passwords/session tokens; audit rows reject model updates/deletes and have no mutation API.

**Trusted-write boundary:** use the RBAC services for permission-pivot changes and administrative mutations. Bulk SQL/query-builder updates or raw pivot `sync/detach` outside `RbacMutation` bypass model invariants/auditing and are not supported maintenance paths. Database constraints still enforce scope shape, valid references and uniqueness, but cannot replace application authorization or final-administrator checks. Database operators need separately controlled access and operational audits. Keep a shared persistent lock-capable cache across application processes; do not use process-local `array` cache in production.

### Verification and Phase 2.2D follow-up

The schema and integrity/security suites were executed using isolated SQLite and a disposable MySQL 8.4.11 server initialized in `storage/framework/testing/rbac-mysql`, with a dedicated Unix socket and networking disabled. The existing company databases and system MySQL data directory were not used. SQLite uses equivalent scope-shape triggers because it cannot add a table CHECK after creation; MySQL 8.4 uses a native CHECK. Both retain the composite site/company foreign key and strict unique scope/assignment keys. The disposable instance is stopped and removed after testing.

Dashboard and document placeholder pages intentionally remain authenticated-only. They expose no document operations. Actual operations must add explicit permission and resource-level company/site/department/confidentiality guards when implemented. Phase 2.2D-1 implements explicit company/site/department memberships below. Resource authorization and confidentiality remain Phase 2.2D-2 work; production-scale testing of final-administrator checks remains an operational review task. Full user/company maintenance, document workflows and confidential-document policies remain unimplemented. Manual browser/screen-reader and Lao editorial review remain review tasks; automated UI tests use jsdom.


## Organization membership — Phase 2.2D-1

### Storage and lifecycle

`organization_memberships` records an existing user, membership level (`company`, `site`, `department`), the company/site/department IDs, an active flag, trusted creator/updater/revoker IDs, revocation timestamp and normal timestamps. No global membership level exists. Company foreign keys and composite site/company and department/site/company foreign keys use restrictive actions. The additive migration gives departments a unique `(id, site_id, company_id)` parent key without changing existing organization records.

The database generates `unit_key` from the membership level and unit ID. A second generated `current_key` equals that key while the row is not revoked and becomes NULL on revocation. Unique `(user_id, current_key)` prevents duplicate current memberships, including inactive memberships. Revoked versions retain independent IDs and history. MySQL CHECK constraints and equivalent SQLite triggers enforce valid level/ID shape and complete revocation metadata with inactive status.

Identity and creator fields are immutable through normal model writes. Revoked records cannot be restored, edited or deleted through models/APIs. An authorized administrator can make a new grant for the same user/unit only with explicit `confirm_regrant=true`; the old row remains revoked. Updating `active` temporarily suspends/reactivates a non-revoked row without changing affiliation. Inactive users or organizational parents make membership ineffective without altering its historical flags. Reactivation requires an active account and all applicable units.

`membership_audits` appends assignment, modification and revocation snapshots with the acting user, target user, membership and timestamps. Changes and audit writes share the existing serialized RBAC transaction; audit failure rolls back the affiliation change. Audit fields are rejected from client input. Audit models reject updates/deletes and have no mutation API. Trusted maintenance must use `ManageMemberships`; raw SQL can bypass model immutability and application auditing, although database ownership/uniqueness/shape constraints remain enforced.

### RBAC plus membership

`MembershipAccess` evaluates fresh database state, and `AccessControl` combines it with current role grants. Membership never creates a role or permission, and role assignment never creates membership. There is no membership or permission cache that can preserve revoked access. Changes take effect on the next evaluation/request in an existing session; in-flight work is not retroactively cancelled.

| Operational context | Required membership | Role authorization |
| --- | --- | --- |
| Company | Exact company-level membership | Permission effective at that company through an explicit company/global assignment |
| Site | Exact site-level membership | Permission effective at that site under existing RBAC inheritance |
| Department | Exact department membership, with matching company/site | Permission effective at the owning site; the caller supplies the department ID |
| Global | Never an operational membership context | Always denied for operational access |

Membership levels do not substitute for one another: company membership does not replace site/department membership, site membership does not replace company/department membership, and department membership does not widen to its site or a sibling department. Multiple explicit memberships are supported. Existing global → company → site **role** inheritance is unchanged.

`permissions()` and `allows()` return effective capabilities. `allowsOperational()` additionally rejects administrative keys. Laravel Gates accept `[scope, departmentId]` where department scoping is required. `grantedPermissions()` reports only underlying role configuration, for permission-delegation checks; it is not an operational authorization decision. Administrative configuration/delegation still requires explicit administrative permissions and may configure only catalog permissions already granted to the actor. Future resource handlers must derive company/site/department context from the resource, rather than trusting client IDs or a prior context selection.

A fixed allowlist of the five existing identity/role-administration keys plus `memberships.view` and `memberships.manage` is evaluated without ordinary membership. This preserves explicitly scoped central and local security administration, including first-member onboarding by central identity administrators. It does not grant any key, expand an assignment's scope, or exempt an operational/document permission. The existing final-administrator invariant continues to use the original five capabilities, so membership revocation cannot lock out that workflow.

### Controlled capability rollout

The catalog adds `memberships.view`, `memberships.manage` and `organization.access`. The latter permits use of an assigned organization context only; it grants no document or business-operation capability. No existing role gains any of these keys on ordinary `rbac:initialize` reruns. Migrations and normal seeding create no memberships, assignments or organization data. Existing roles are deliberately not treated as verified memberships.

After reviewing the Security Administrator definition and every affected assignment, an authorized host operator can explicitly enable the administrative pair:

```sh
php artisan rbac:initialize --enable-membership-administration --operator='VERIFIED OPERATOR LABEL' --confirm-global --no-interaction
```

This adds only the two membership-administration keys to the active global Security Administrator definition. Its existing five administrative capabilities must be active. The global confirmation acknowledges that changing a shared definition affects all existing assignments at their existing scopes. It creates no accounts/assignments/memberships, does not widen scopes, and is idempotently audited in `rbac_audits`.

Operational context permission has a separate reviewed rollout. To add only the fixed `organization.access` catalog key to a verified existing active role:

```sh
php artisan rbac:initialize --organization-context-role=VERIFIED_EXISTING_ROLE_CODE --operator='VERIFIED OPERATOR LABEL' --confirm-global --no-interaction
```

This is a separate, audited opt-in, never combined with the administrative rollout. Review all affected users and role scope before use. No membership is created; a holder still has no operational context access without exact matching membership. Use a reviewed operational or delegation role as appropriate. The initial administrator bootstrap continues to reject templates augmented with operational keys. Normal web delegation retains its permission-subset checks. Neither command accepts arbitrary permission keys or confers document access.

These commands were exercised only inside isolated test databases. No real capability-rollout, administrator bootstrap, membership assignment or organization initialization was executed against the development database.

### APIs and Administration UI

All endpoints retain active/revocable session authentication and CSRF enforcement. Guests receive 401 and actors lacking the applicable permission receive 403. Every administrative request supplies a selected `scope_type` and applicable `company_id`/`site_id`. Assignment targets use separate `unit_type`, `unit_company_id`, `unit_site_id` and `unit_department_id` fields, preventing confusion between administration scope and target affiliation.

| Endpoint | Required authority / behavior |
| --- | --- |
| `GET /api/v1/memberships` | `memberships.view`; paginated current and historical memberships within selected scope, including effective status |
| `GET /api/v1/membership-units` | `memberships.view`; bounded, searchable active units in scope; site administrators cannot select company membership |
| `GET /api/v1/membership-users` | `memberships.manage` plus `users.view`; eligible active identities, excluding the actor |
| `POST /api/v1/memberships` | `memberships.manage` plus `users.view`; valid active target and units, scope containment, non-self target and explicit regrant confirmation when applicable |
| `PATCH /api/v1/memberships/{membership}` | `memberships.manage`; update active status only, within scope, non-self and non-revoked |
| `DELETE /api/v1/memberships/{membership}` | `memberships.manage`; terminal revocation within scope, non-self; preserves history |
| `GET /api/v1/organization-contexts` | Authenticated; returns only the caller's effective memberships that also pass `organization.access`; no content or document operations |

Company administrators can manage company memberships and site/department memberships inside that company; site administrators can manage only their site's site/department memberships. Selected scopes never override role authority. Global identity visibility can find unassigned active users for onboarding; local lookup uses effective memberships or the existing scoped identity anchors, never an unrestricted account directory. A legacy role anchor is discoverability only, not evidence of effective operational membership. Global administrators can revoke historical memberships under inactive parents by selecting global scope; reactivation remains forbidden until the account and units are active.

Administration adds a distinct User Organization Membership section with English/Lao names, company/site/department columns, membership level, effective/inactive/revoked badges, pagination, bounded account/unit search, active-status updates, terminal revocation, explicit regrant choice and target-specific confirmation. Read-only actors do not load account directories. Scope changes clear candidates and dialogs; stale/unauthorized responses remove mutation controls. Backend checks remain authoritative. Validated administration scope is passed directly to role/membership resources so their UI control hints survive Form Request serialization.

### Verification and Phase 2.2D-2 boundary

Verification uses isolated SQLite and disposable MySQL 8.4.11 with a repository-local data directory, private Unix socket and networking disabled. It covers the full PHP suite, fresh migrations, membership ownership/shape/uniqueness constraints, audit rollback and security regressions. The new migration's rollback and reapplication are also tested on that disposable instance; no existing MySQL database or development data is used. Frontend tests use jsdom; browser/accessibility and Lao editorial review remain manual review tasks.

Dashboard and placeholder document pages remain authenticated-only and contain no document operations. Document confidentiality, document-content policies, registration/control workflows, resource-specific policies and department-based document rules remain Phase 2.2D-2 work. That phase must derive context from actual resources, use the central effective checks, and separately define confidentiality and broader company/department operation rules. No blanket administrator or membership bypass is provided here. Production-scale query/lock performance and multi-process concurrency remain deployment review work.

## Confidentiality and document access policies — Phase 2.2D-2

This phase adds security infrastructure only. There are no document registration workflows, numbering rules, document-content tables, uploads, file storage, preview/download implementations, or new routes. Existing placeholder pages remain authenticated-only and expose no document data. Permission initialization adds controlled catalog definitions but gives no existing role new grants; Security Administrator retains no automatic document or clearance authority.

### Classification and resource contract

`Confidentiality` defines stable values `normal`, `confidential` and `highly_confidential`, with English/Lao localization keys for labels and descriptions. Classification is independent of role names and cannot be edited through a browser API. A security profile must specify classification explicitly; unknown classifications never fall back to Normal.

`document_security_profiles` is a security sidecar, not a document/workflow table. It stores an opaque resource family/reference (`incoming`, `outgoing`, `controlled`), company/site/optional owning department, classification, allowlist requirement, active flag and trusted creator/updater IDs. It contains no document title, number, text, filename, attachment locator or contents. Unique family/reference prevents duplicate profiles. Restrictive company/site and department/site/company foreign keys enforce valid ownership. All resource profiles require a site; there is no global document security scope.

`DocumentSecurityDescriptor::fromProfile()` accepts only an existing persisted profile bound to the resource loaded by server code. It discards loaded model attributes, re-reads persisted identity and validates organization ownership. Its private constructor retains only profile ID and stable resource identity. `DocumentAccess` resolves that reference from the database again on every decision, so descriptor reuse cannot preserve stale ownership, classification, restriction or active state. Raw browser arrays and raw model objects are unsupported policy inputs and deny access.

`ManageDocumentSecurity::registerProfile()` is for trusted future module integration only. Resource family/reference and ownership must come from the server-loaded persisted document, not request security fields. Phase 2.2E must atomically bind each actual document to its correct profile and verify that both describe the same resource/ownership. This phase cannot verify a foreign key to document tables that do not yet exist. A profile reference supplied by a browser must never select security for an independently loaded document. Descriptors must not be serialized as a substitute for authorized metadata.

### Independent operation policies

| Operation | Controlled permission | Behavior |
| --- | --- | --- |
| Metadata discovery/view | `documents.metadata.view` | Authorize before returning metadata, identifiers, search results, counts or suggestions |
| Content preview | `documents.content.preview` | Independent content permission; does not imply metadata or download rights |
| Attachment download | `documents.attachments.download` | Independent download permission; never inferred from preview |

`DocumentAccess::allows()` returns a decision and records an audit; `authorize()` throws the same generic `Document access denied.` message for every denial. Laravel Gates for the three permission keys require a `DocumentSecurityDescriptor`. Scope-only `AccessControl::allows()`, `allowsOperational()` and capability lists deliberately exclude these resource-only abilities. Underlying `grantedPermissions()` remains available for controlled role configuration, not as proof of document access. Legacy planned `documents.view/register/control` keys remain unavailable.

Every operation requires a currently active account/profile/organization, an active RBAC grant effective at the resource's owning site under existing scope inheritance, and exact effective site membership or owning-department membership when a department is present. Company membership alone does not replace site/department affiliation. Confidentiality and document restrictions then apply:

| Classification | Additional requirements |
| --- | --- |
| Normal | No clearance; any explicit document allowlist still applies |
| Confidential | Exact Confidential clearance for that site/department, plus any explicit allowlist |
| Highly Confidential | Exact Highly Confidential clearance **and** an operation-specific allowlist grant for that exact document |

Confidential and Highly Confidential clearances are separate entitlements; neither silently includes the other. Clearance, membership and allowlist grants never create an RBAC permission. Technical/global administration does not imply any clearance or document permission. The existing administrative exception remains restricted to identity/role/membership administration; it does not exempt these policies.

### Clearances, restrictions and lifecycle

`confidentiality_clearances` stores the target user, exact company/site/optional department, one protected classification, active/expiry/revocation state, trusted actor IDs and timestamps. No Normal or global clearance exists. `document_access_grants` stores the target user, exact profile, one operation and the same lifecycle information. Generated current keys prevent duplicate non-revoked grants, including inactive or expired rows. Revocation releases the current key while preserving the old row, so an explicitly authorized new grant has separate history.

Only `ManageDocumentSecurity` is a supported management path. There are no clearance management APIs or CLI grant commands in this phase. Services require both management permission and exact effective manager membership at the affected resource scope:

- `clearances.confidential.manage` may manage Confidential clearances only.
- `clearances.highly-confidential.manage` may manage Highly Confidential clearances only.
- `document-access.manage` may create security profiles, tighten allowlist requirements and manage operation-specific allowlist grants; it grants no read access.

Targets must have matching effective membership when a grant is issued or reactivated. Self-grant, self-reactivation/extension and self-revocation are refused. Managers do not need read clearance to perform explicitly authorized administration and gain no read permission by doing so. Role or scope revocation is rechecked inside the serialized RBAC transaction. Actual policy-role provisioning remains a separately reviewed integration/operator action; the default initializer and existing bootstrap do not grant these capabilities.

An inactive, expired (`expires_at <= now()`) or revoked grant never satisfies the policy. NULL expiry means no scheduled expiration. Inactive users/units make otherwise current grants ineffective; reactivating those parents can restore still-active, unexpired, non-revoked grants, so use explicit revocation for permanent removal. Revoked records cannot be edited, restored or deleted through models/services. Profile identity, ownership and classification are immutable through normal writes; allowlist requirements may only be tightened. Declassification, ownership transfer and loosening restrictions require a future dedicated reviewed workflow and are not exposed here.

Every evaluation queries current state; no authorization/clearance cache preserves revoked access across an existing session. Revocation affects the next evaluation, including with a previously created descriptor or stale loaded user relationships. It does not retroactively cancel an already authorized in-flight response. Future delivery code must authorize immediately before returning metadata or bytes and must not rely on an earlier navigation check or an unchecked long-lived file URL.

### Security audit and integration boundaries

`document_security_audits` distinguishes allowed access, denied access, profile registration/restriction changes, clearance changes and allowlist changes. Access records contain actor/profile IDs, operation, bounded internal reason and timestamps, without titles, resource references, content, filenames, passwords, tokens or attachment data. Caller-facing denials disclose no classification, organization name or denial reason. Administrative records contain target/subject IDs and before/after security snapshots only. Audit failures prevent an allowed result; mutation and audit writes are atomic.

Models reject audit updates/deletes, and SQLite/MySQL triggers reject UPDATE/DELETE even when model events are bypassed. Restrictive foreign keys retain referenced identities and security history. The migration requires permission to create triggers. Database operators able to alter schema remain outside application-level protection. There is no public audit browsing API; reporting and retention access need separate authorization in Phase 2.2E.

Access audits use the current database connection. Future controllers should authorize outside any surrounding transaction that will roll back on an authorization exception; an outer rollback also rolls back its audit insert. If module workflows need denial evidence independent of their transactions, provide and verify a separate durable audit sink during integration. Do not catch an audit-write failure and continue delivery.

### Verification and Phase 2.2E work

Verification covers SQLite and an isolated MySQL 8.4.11 instance with repository-local data, a private Unix socket and networking disabled. It exercises the full PHP suite, fresh migrations, native ownership foreign keys, generated uniqueness, clearance/allowlist lifecycle, classification rules, append-only audit triggers and rollback/reapplication of the new migration. All account, role, membership, resource-profile and clearance fixtures are fictional and confined to test databases. No development/production database, real grant, account bootstrap or organization initialization was changed. The disposable instance is stopped and removed after testing.

Phase 2.2E must implement the real document/profile linkage, resource-specific policies/controllers and authorized discovery queries, enforce these policies before serialization or file delivery, prevent metadata leaks through counts/headers, define attachment-to-document ownership, and retain the existing active/revocable authentication middleware. Policy-role provisioning, protected clearance administration UI/APIs if needed, reviewed reclassification/transfer workflows, audit reporting/retention and transaction-independent denial logging remain integration work. Production-scale query/lock performance and concurrency require deployment testing. English/Lao terminology is tested through the existing localization system; Lao editorial review remains a manual review task.
