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

`GET /api/v1/user` uses the same browser session and returns only ID, name, email and preferred language. It returns JSON 401 for guests. There are no registration, recovery, social-login, user-management or organization-mutation endpoints. The Administration page remains a placeholder, with no privileged actions.

Login uses framework password verification, session/CSRF-token regeneration, and generic failure messages for unknown, incorrect and inactive accounts. Throttling permits five failed attempts per normalized email/IP pair and twenty attempts per IP in a minute. Cache keys hash the identifiers; passwords are never put in cache keys or logs. Logout invalidates the session and regenerates the CSRF token. Login and authenticated responses use private/no-store caching where applicable. Keep HTTPS and `APP_DEBUG=false` in production; secure session cookies default to true in production, alongside the existing HttpOnly and SameSite=Lax defaults. Use a shared persistent cache (the existing database default or shared Redis) for cross-process throttling and the bootstrap lock.

Language selection is retained in browser storage and carried in login/logout forms. Valid selections update the user's `preferred_language` on login/logout; the session/profile supplies a fallback when browser storage is unavailable. Passwords are never stored in browser storage or flashed back to forms.

### Controlled initial identity

After applying the account migration, an authorized operator can run:

```sh
php artisan user:bootstrap-admin --name='VERIFIED DISPLAY NAME' --email='VERIFIED EMAIL' --language=en
```

Replace the placeholders with the intended identity. The command requires hidden password and confirmation prompts, without a visible-input fallback. Passwords must contain at least 16 characters, at most 72 bytes (avoiding bcrypt truncation), and no null bytes. Login also rejects passwords exceeding that byte limit, preventing extra suffixes from matching a bcrypt prefix. There is no password argument, environment-variable shortcut or default password. Do not put passwords in shell arguments, scripts or Git. Noninteractive creation is refused.

Creation is transactional and coordinated through an atomic cache lock. An existing email is a no-op: no password reset, rename, reactivation or privilege changes occur. If any other account already exists, creation is refused. Default seeding creates no accounts. The bootstrap command and XPPL initializer were not executed against the development database for this phase.

**Temporary boundary:** “bootstrap admin” identifies the intended future security administrator only. This phase assigns no role, company/site access, confidential-document access or administrator bypass. The account can authenticate to the placeholder workspace just like any active account. Phase 2.2C must establish configurable roles/site assignments and an explicit, controlled initial security-administrator assignment. Names and email addresses must never determine permissions.

### Account deactivation and session revocation

Trusted application code must deactivate through `$user->deactivate()` (or a normal model save setting `active=false`). A hidden, random per-user `session_version` rotates on deactivation and model password changes. Every protected request re-reads the account and compares the login snapshot; inactive, deleted or revoked accounts are logged out with their session invalidated. Reactivating an account does not restore previously revoked sessions. Session rows on other devices may remain until expiry, but their old versions cannot authenticate to protected routes.

Do not bulk-update account active/password fields while bypassing model events: raw deactivation is denied while inactive, but raw deactivation/reactivation or password writes must also explicitly rotate `session_version` to revoke old sessions. No web mutation path for these fields is exposed. Existing users receive active/en migration defaults; their nullable initial session version is replaced on the first deactivation/password change. Newly created users always receive a random version.

The current tests use isolated SQLite and jsdom. MySQL 8.4 and manual browser/screen-reader verification remain separate review checks. Organizational integrity constraints from Phase 2.2A are unchanged. Full RBAC, site-access enforcement, confidentiality, account-management screens, password recovery and document workflows remain outside this phase.
