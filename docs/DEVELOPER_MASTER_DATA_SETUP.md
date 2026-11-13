# Local XPPL master data

Use your **local** database (`APP_ENV=local`). These commands refuse UAT/Production. Review your local database connection before running migrations or import.

```bash
php artisan migrate --no-interaction
```

The importer does not create users. Use an existing active local account. If your developer database has none, the existing command below creates an initial identity with hidden password prompts; it does not grant roles:

```bash
php artisan user:bootstrap-admin --name="Local Developer" --email="developer@example.test"
```

Find that account's local ID in your normal local user administration/database viewer. Replace `123` below with it. Master tables and numbering configuration have required creator foreign keys, so `--actor` is necessary when those records are missing. Source creator IDs are never imported.

```bash
php artisan dcms:master-import --file=database/master-data/xppl-master.json --dry-run --actor=123
php artisan dcms:master-import --file=database/master-data/xppl-master.json --actor=123
php artisan dcms:master-import --file=database/master-data/xppl-master.json --dry-run
```

Preview lists each business key and `create`, `skip`, or `conflict`, followed by counts per type. A fresh target receives one company, one site, 19 departments, 39 categories, 19 types, three registers, 24 type/register links, one site scope, four numbering series/current configurations/prefix reservations and three policy settings. Post-import preview should show `create=0`, `conflict=0`, and all 90 master/configuration entries skipped. Once everything matches, rerunning without `--actor` is safe.

Matching uses company/site/department/category/type codes, scope business keys and register/purpose combinations, never source IDs. Exact matches are skipped. Unrelated records, operational documents, files, existing audits, and all numbering counters/allocations are preserved. Mismatched names, classifications/relationships, type/register mappings, review settings, numbering configuration or reserved prefixes cause a conflict; there is no overwrite mode. Invalid references/unknown fields are rejected before import. Unexpected failures roll back the whole import, including new scopes and creation audits.

Resolve conflicts by reviewing the preview against your local configuration and the owner-approved snapshot. Do not delete operational data, reset counters or edit issued history. Ask the owner to resolve the intended configuration; if appropriate, use a separate new local database rather than forcing a conflicting import. Inspect normal master-data screens after import; access still requires your existing local roles/memberships (not distributed by this package).

To refresh the package from the reviewed DEV configuration:

```bash
php artisan dcms:master-export --company=XPPL
```

Export writes `database/master-data/xppl-master.json` and never writes database data. Review the JSON diff and obtain owner approval before distributing a refresh. It contains only explicitly selected master fields, current numbering format/settings and active XPPL policy kinds. It excludes users, credentials, role assignments, documents, attachments, counters, allocations, historical configuration versions, actor/timestamp history and XPPL reconciliation/mapping state. Policy-driven numbering initializes its normal mappings at future registration; this import does not issue numbers or recreate a source baseline. Prefixes are literal configuration, including any numeric segments; they are not remapped as source IDs.

This snapshot was read from DEV on 2026-11-13. It retains 76 `[DEV-UNTRANSLATED]` labels, an English ADM Lao label, English company/site Lao labels, and CAT01–CAT39 codes. These need owner review as labels/codes; no translations or official codes were invented. The older repository UAT proposal differs in ADM's Lao label and does not include the current numbering setup. A current UAT snapshot was unavailable; DEV/UAT equality is **not verified**. This developer package does not replace the existing guarded UAT/Production master-data import tools.
