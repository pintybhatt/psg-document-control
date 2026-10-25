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

The command requires the active Security Administrator template to contain exactly the initial administrative capabilities and refuses unknown/inactive users, mismatched scopes, unconfirmed global access and restoration of a revoked assignment. The same active assignment is a non-destructive no-op; once an effective administrator exists for the requested context, another initial grant must use authorized Administration instead. Transactions and the RBAC mutation lock protect initialization. The append-only audit records the CLI source, operator label, assignment, scope and timestamp; operators must retain their normal host-level execution audit as well. No credentials are created or changed, and no confidential/document capability is granted.

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

Identity lookup has a deliberate Phase 2.2D boundary: global `users.view` can find active accounts group-wide; company authority can find existing active scoped identities within that company; site-only authority can find identities already assigned at that exact site. Local administrators cannot discover or onboard unrelated/unassigned accounts. Central administration can establish the first scoped assignment. Role assignments express authority, not verified employment/department membership; formal user/site/department membership is deferred to Phase 2.2D.

Administration uses the existing shell, language switcher, bilingual database labels, reusable tables/forms/dialogs/toasts, pagination, bounded identity/role lookup, and explicit confirmation displaying the target account, role and selected scope. UI capability hints hide unavailable controls; inherited definitions and one's own role security settings are read-only. Stale authority is denied by the API and mutation controls are removed after authorization failures. Neither navigation visibility nor client capabilities authorize an operation.

### Final-administrator and audit protections

`RbacMutation` serializes writes with a shared cache lock and a database row lock on the canonical global scope, entering the database transaction before taking authorization snapshots. The global row is a coordination record, not an administrative grant. For every active persisted scope that already has an effective administrator, the transaction must leave at least one active user who collectively holds all five administrative capabilities, potentially through multiple roles and inherited grants. This is capability-based, not dependent on the Security Administrator role name/code. A closed company/site is not an active administration boundary.

The invariant protects controlled role/permission edits, assignment revocation/deletion, and normal user model deactivation (`deactivate()` or saving `active=false`). The operation rolls back if it would remove the final effective administrator. A second eligible administrator must exist before the first can be removed. APIs cannot change their own role grants to evade this rule. Web changes and CLI bootstrap append before/after audit snapshots without passwords/session tokens; audit rows reject model updates/deletes and have no mutation API.

**Trusted-write boundary:** use the RBAC services for permission-pivot changes and administrative mutations. Bulk SQL/query-builder updates or raw pivot `sync/detach` outside `RbacMutation` bypass model invariants/auditing and are not supported maintenance paths. Database constraints still enforce scope shape, valid references and uniqueness, but cannot replace application authorization or final-administrator checks. Database operators need separately controlled access and operational audits. Keep a shared persistent lock-capable cache across application processes; do not use process-local `array` cache in production.

### Verification and Phase 2.2D follow-up

The schema and integrity/security suites were executed using isolated SQLite and a disposable MySQL 8.4.11 server initialized in `storage/framework/testing/rbac-mysql`, with a dedicated Unix socket and networking disabled. The existing company databases and system MySQL data directory were not used. SQLite uses equivalent scope-shape triggers because it cannot add a table CHECK after creation; MySQL 8.4 uses a native CHECK. Both retain the composite site/company foreign key and strict unique scope/assignment keys. The disposable instance is stopped and removed after testing.

Dashboard and document placeholder pages intentionally remain authenticated-only. They expose no document operations. Actual operations must add explicit permission and resource-level company/site/department/confidentiality guards when implemented. Phase 2.2D must introduce verified user/site/department membership, department scope and resource authorization, review identity onboarding and inactive-parent maintenance, and load-test the final-administrator checks for the intended organization/user volume. Full user/company maintenance, document workflows and confidential-document policies remain unimplemented. Manual browser/screen-reader and Lao editorial review remain review tasks; automated UI tests use jsdom.
