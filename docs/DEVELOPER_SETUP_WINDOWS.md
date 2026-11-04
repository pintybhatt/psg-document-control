# Native Windows developer setup — XPPL DCMS

Use native Windows PowerShell (5.1 or 7), Git and PHP with Visual Studio **Open Folder** or VS Code. This is a Laravel 13 / Vue 3 / Tailwind 4 application, not a .NET solution. Developers familiar with XPPL Toll can use the same feature-branch habits. WSL, Docker and MySQL are not required for ordinary development.

XPPL developers already develop and maintain the XPPL Toll Collection App on native Windows laptops using Visual Studio/VS Code and Codex or Claude Code, while Toll is deployed on Linux with MySQL. **XPPL DCMS must support the same developer working arrangement:** native Windows remains the standard developer environment; WSL and Docker are not required for routine local development. Local DCMS development may use SQLite, while UAT and Production will run on Linux/MySQL. GitHub CI handles the required MySQL compatibility checks. Developers use feature branches and PRs, just as they do for the Toll App. The setup instructions below retain this arrangement; the documented Windows verification limits still apply.

These instructions were checked against the repository manifests, locked package engines, command implementations and Artisan help on Linux. **They have not been executed on native Windows.** Installation, hidden password prompts, file locking and SQLite backup behavior still need a Windows smoke check. Stop at any failed command; do not paste later steps over an error.

## 1. Install or check native tools

- **PHP 8.5 x64** to match CI/project guidance. Although `composer.json` declares `^8.3`, the locked dependencies can require a newer PHP; do not rely on that root constraint alone. Use [official Windows PHP builds](https://www.php.net/downloads.php?os=windows), or your team's existing native PHP installation. CLI does not require Apache/IIS. Install the matching Microsoft Visual C++ runtime if the PHP distribution requires it.
- **Composer 2**: [Windows installer](https://getcomposer.org/doc/00-intro.md#installation-windows), selecting the same `php.exe` used by your terminal.
- **Node.js 22, at least 22.12** and its bundled npm, matching CI's Node 22 major. The lockfile's Vite and jsdom engines require this minimum on Node 22. Use the official [Node downloads](https://nodejs.org/en/download).
- **Git for Windows**: [official download](https://git-scm.com/downloads/win). Authenticate using your existing GitHub access; do not embed tokens in clone URLs.
- **SQLite PHP drivers**: enable both `pdo_sqlite` and `sqlite3`. No SQLite server or separate SQLite CLI is needed.

Open a new PowerShell terminal after installation:

```powershell
Get-Command php, composer, node, npm.cmd, git
php -v
php --ini
php -m
composer --version
node --version
npm.cmd --version
git --version
```

Edit the **loaded CLI php.ini** reported by `php --ini`, not another installation's configuration. For a ZIP PHP installation, create it from the supplied development template if absent and set `extension_dir` to that installation's `ext` directory. Enable the distribution's matching DLLs for `pdo_sqlite`, `sqlite3`, `mbstring`, `gd`, `fileinfo`, `zip`, `openssl` and preferably `curl`; never download arbitrary DLLs from third parties. The application and tools also need PDO, ctype, DOM, XML, XMLReader, XMLWriter, SimpleXML, tokenizer, filter, hash, iconv, JSON, session, Phar, PCRE and zlib; many are bundled. Composer's platform check below is authoritative for locked packages. `sqlite3` is additionally needed by the optional DEV importer even though Composer does not enforce it.

Use `npm.cmd` in this guide to avoid PowerShell blocking the `npm.ps1` shim; changing the machine execution policy is unnecessary. If several PHP installations exist, fix PATH and reopen the terminal before proceeding.

## 2. Clone and create a feature branch

Choose a short, writable local directory outside OneDrive/network shares to reduce path, sync and locking problems:

```powershell
New-Item -ItemType Directory -Force C:\Projects | Out-Null
Set-Location C:\Projects
git clone --branch main https://github.com/pintybhatt/psg-document-control.git
Set-Location .\psg-document-control
git switch -c feature/your-change
```

Use the team's approved baseline. Until Phase 10 is reviewed, pushed and merged, a fresh GitHub clone of main may lack Phase 10 commands. If asked to work from a published review branch, fetch and create your own branch from that exact approved remote ref instead. Local-only commits on another laptop are not available through GitHub. Do not bootstrap from an unreviewed archive or copy another developer's `.env`/database.

Open this folder in your editor (`code .` if the VS Code command is installed). Run the following commands from the repository root.

## 3. Install locked dependencies and configure local SQLite

```powershell
composer install --prefer-dist --no-interaction
composer check-platform-reqs
npm.cmd ci --ignore-scripts
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
if (-not (Test-Path database/database.sqlite)) { New-Item -ItemType File database/database.sqlite | Out-Null }
```

Do not use `composer update`, `npm update`, `--ignore-platform-reqs` or delete lockfiles to bypass an installation failure. Fix missing extensions/tool versions. The existing npm configuration intentionally disables dependency install scripts.

Edit `.env` locally. Keep unrelated example defaults, and set:

```dotenv
APP_NAME="XPPL DCMS DEV"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
DB_CONNECTION=sqlite
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=false
CACHE_STORE=database
QUEUE_CONNECTION=sync
MAIL_MAILER=array
```

Leave `APP_KEY` blank until the next step. Leave `DB_DATABASE` absent/commented: `config/database.php` then selects this checkout's `database/database.sqlite`. Remove any inherited `DB_URL`, `DB_SOCKET`, MySQL credentials or cloud credentials; do not point local code at Toll, UAT or Production databases. `sync` avoids needing a queue worker for ordinary development. Mail stays local and is not sent.

Only for a **new** local `.env` without an APP_KEY, generate its key once. Preserve the key on subsequent setup/update runs:

```powershell
php artisan config:clear --no-interaction
php artisan key:generate --no-interaction
php artisan migrate --no-interaction
```

Before migrating, verify `APP_ENV=local`, SQLite and the checkout's file path. Use normal forward migrations; never use `migrate:fresh`, rollback or reset against a database you need to preserve. Do not run `composer setup` over an existing checkout: its convenience script also generates a key and migrates. Do not copy UAT credentials, uploaded documents or numbering history into DEV.

## 4. Create the first local administrator

On your newly initialized, personal DEV database:

```powershell
php artisan rbac:initialize --no-interaction
php artisan user:bootstrap-admin --name="Local Developer" --email=admin@dcms.test --language=en
php artisan rbac:bootstrap-admin --email=admin@dcms.test --scope=global --operator="Local Developer DEV setup" --confirm-global --no-interaction
```

The identity command **must be interactive**: enter and confirm a private password in its hidden prompts (at least 8 characters, at most 72 bytes). Do not add `--no-interaction` to that command, put a password in arguments or ask an agent to record it. The identity command grants no permissions; the separate global assignment explicitly grants System Administrator authority on this **personal local database only**. No organization or operational master data is created here.

Bootstrap closes once an account exists; an existing email is a no-op, not a password reset or reactivation. Use the existing authorized Users administration screen for additional users. Do not delete users to reopen bootstrap. If hidden input fails in an editor terminal, try a normal interactive PowerShell window; do not weaken the command or introduce a password flag.

## 5. Optional synthetic demo and XPPL DEV master data

Skip this section if you only need to sign in. On a new personal database, the synthetic DEMO tools can prepare an example workspace for the `admin@dcms.test` account above:

```powershell
php artisan dev:setup-phase3-demo --operator="Local Developer" --no-interaction
php artisan dev:configure-demo-documents --operator="Local Developer" --no-interaction
```

These commands require local SQLite and the expected synthetic DEMO identity. They do not issue document numbers. Existing identity/name/configuration conflicts must be reviewed rather than overwritten.

The separate `dev:prepare-xppl-master-data` command requires **existing active XPPL company ID 2 and site ID 2**, in exactly `database/database.sqlite`. On a fresh database, creating DEMO first normally occupies IDs 1; obtain approved development organization labels before initializing XPPL:

```powershell
$companyEn = Read-Host 'Approved DEV company English name'
$companyLo = Read-Host 'Approved DEV company Lao name'
$siteEn = Read-Host 'Approved DEV site English name'
$siteLo = Read-Host 'Approved DEV site Lao name'
php artisan organization:initialize-xppl --company-name-en="$companyEn" --company-name-lo="$companyLo" --site-name-en="$siteEn" --site-name-lo="$siteLo" --no-interaction
```

Check the company/site IDs and active status in Administration. The initializer preserves existing rows and does not promise ID 2. If XPPL already has other IDs, **stop this optional import path**; do not renumber rows, reset the database or patch the guard. Use normal Administration or request a reviewed alternative.

Preview without writes:

```powershell
php artisan dev:prepare-xppl-master-data --no-interaction
```

Read the proposal hash, conflicts and provisional code/translation warnings. Do not treat a calculated hash as Product Owner approval.

**Confirmed native Windows apply blocker:** `PrepareXpplMasterData::backup()` requires the supplied path to start with `/`. A normal fully qualified Windows path such as `C:/XPPL-DEV-Backups/before-import.sqlite` is rejected, even though PHP can otherwise use it. Therefore this guide provides the safe read-only preview but **does not prescribe a native Windows `--apply` command**. Do not use a root-relative slash path, manipulate drive assumptions, bypass the guard or manually insert SQL to work around it. Request a separate reviewed path-validation fix and Windows backup/integrity/permissions testing before applying the optional proposal on Windows. Core login/development and synthetic DEMO setup do not depend on this importer.

After a compatible, reviewed implementation is available, apply still requires Product Owner approval of the exact proposal SHA-256, an existing authorized administrator ID and a new absolute private SQLite backup path. Preserve its insert-only/conflict checks and backup integrity verification. Windows backup protection also needs appropriate NTFS access controls; POSIX `chmod(0600)` alone is not a Windows ACL guarantee. Never commit backups.

This optional DEV tooling does not configure official numbering or import production records. The separate `uat:import-xppl-master-data` command is for a separately authorized UAT initialization; **never run it for laptop setup**. See the [UAT import runbook](security/phase-10c13-uat-master-data-import.md) for that distinct process.

## 6. Start the app

Use two PowerShell terminals, both in the repository root. This avoids depending on Linux process tools or optional multiplex/Pail support.

Terminal 1:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal 2:

```powershell
npm.cmd run dev -- --host 127.0.0.1
```

Open the local login page at `http://localhost:8000/login`. Stop each process with Ctrl+C. Keep these development servers bound to loopback. If ports are occupied, stop your own stale process or choose another port and align APP_URL; do not stop unrelated apps. For assets without the Vite dev server, run `npm.cmd run build`.

## 7. Routine changes and PRs

Develop and commit on your feature branch. Before updating existing local data, preserve its database, uploads and APP_KEY; run locked installs and normal migrations only when required by the reviewed change.

Run focused checks, replacing these example files with those relevant to your change:

```powershell
php artisan test --compact tests/Feature/UserManagementTest.php
npm.cmd test -- tests/Frontend/Login.test.js
npm.cmd run build
php vendor/bin/pint --dirty --format agent
git diff --check
git status --short
git diff
```

The default PHP tests use disposable in-memory SQLite. Do not change their database settings to DEV or UAT. Linux process/concurrency tests and the guarded MySQL compatibility harness are not certified for native Windows; leave consolidated compatibility validation to GitHub CI. Do not rerun the complete MySQL suite during ordinary laptop development or skip safeguards to make a Windows run green.

Select intended files explicitly, commit locally, then push/create a PR targeting main **only when authorized**. Avoid `git add .` when secrets or backups might be present. Require CI and review before merge. After approved merge, an authorized operator uses the existing manual UAT action; developers do not deploy from laptop setup. Production requires its separate explicit approval process.

## 8. Codex / Claude Code

Open the same native Windows checkout in your chosen tool and keep its sandbox/approval controls enabled. Codex supports a [native Windows sandbox](https://learn.chatgpt.com/docs/windows/windows-sandbox). Follow your organization's supported installation/account policy for either agent; this guide does not install agent tools or require WSL. VS Code and Visual Studio are distinct editors; use an integration supported by the chosen editor, or a separate native terminal agent.

Ask the agent to read `AGENTS.md`, relevant `.agents/skills` and any `.ai/rules` before edits. `CLAUDE.md` contains older bootstrap instructions; Boost is **already installed**. Do not rerun `composer require laravel/boost`, regenerate agent files or update dependencies just to onboard.

The committed `.codex/config.toml` currently calls `wsl.exe` and contains a Linux absolute project path. It will not provide native Windows Boost as written. Use a machine-local override (or a local-only edit that you never stage) to invoke your native `php.exe` with the absolute Windows `artisan` path and `boost:mcp`; preserve the config's table name. The same native process can be registered in Claude Code's local MCP settings. Verify paths locally; do not change shared configuration for everyone or install WSL solely to satisfy this file. If MCP is unavailable, source inspection and Artisan help remain usable; never invent unavailable tool results.

Example task boundary:

> Work on this local feature branch. Inspect project rules first. Make only the requested change. Preserve authorization, numbering and import guards. Use focused SQLite/frontend tests. Do not read secrets, modify DEV/UAT data, update dependencies, push, create a PR, merge or deploy without explicit authorization. Report changed files, checks and the local commit.

Review generated diffs yourself. Never paste credentials, `.env`, real documents, database dumps or backups into agent prompts. Keep passwords out of agent-run commands. Approval of a code change is not approval to change a shared environment.

## Troubleshooting and verification limits

- **Missing driver/extension:** check `php --ini`, `php -m`, PATH and `composer check-platform-reqs`. Enable matching extensions, then restart Laravel.
- **Missing Vite manifest:** run Vite or `npm.cmd run build`; do not change backend routes to bypass it.
- **SQLite locked/read-only:** stop duplicate local writers, use a writable local folder and check antivirus/sync interference. Preserve data; do not disable foreign keys or delete the database.
- **Uploads too large:** align local `upload_max_filesize`/`post_max_size` with the app's 25 MB upload allowance; do not weaken file authorization/validation.
- **Native Windows uncertainty:** no native Windows end-to-end installation, hidden-prompt, importer, queue/process tooling or concurrency test execution was performed while preparing this document. Report the exact failing command and sanitized error for a Windows follow-up. Linux CI remains the automated compatibility gate.
