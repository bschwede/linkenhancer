# CONTRIBUTING

## To contribute

1. Go to primary repository: <https://codeberg.org/bschwede/linkenhancer>
2. Create an account on Codeberg (it's free and FOSS!)
3. Fork the repository there
4. Make your changes
5. Open a Pull Request on Codeberg

## Already made changes on GitHub?

No problem! You can push your branch to Codeberg:

```bash
# Add Codeberg as remote
git remote add codeberg <https://codeberg.org/bschwede/linkenhancer.git>

# Push your branch
git push codeberg your-branch-name

# Then open PR via Codeberg web interface
```

## Why Codeberg?

- 🔓 100% Open Source (AGPLv3)
- 🇪🇺 GDPR compliant, hosted in EU  
- 💚 Non-profit, community-driven
- 🚫 No tracking, no ads, no corporate control

Join us on the real FLOSS platform! 🎉

## Additional details
### Dependencies
This module requires shared code, which is managed in the [wt-shared-libs](https://codeberg.org/bschwede/wt-shared-libs) package. Just clone that repo into a folder named `wt-shared-libs` beside this module, in order to include it via `composer update`.

### How the POT is generated

All user-facing strings are extracted with `xgettext` (no manual PO entries) by
`util/update-po-files.sh`:

- View/service strings use `I18N::translate()` as usual.
- Strings that are **already provided by the webtrees core** (e.g. `Help`, `yes`/`no`,
  `Control panel`) are wrapped in `MoreI18N::xlate()` instead: functionally identical at
  runtime, but the different call name is invisible to xgettext - so they are never
  extracted into the module POT and their translations come from the core POT.
- **Manifest literals** (`cron-jobs.php` is pure data, loaded by the cronjob module in
  tick/CLI context where no UI language is active) are wrapped in `MoreI18N::translate()`,
  an *identity* marker whose last qualified-name component matches xgettext's
  `--keyword=translate`. Nothing is translated at manifest load time.
- **Pipeline:** `util/update-po-files.sh` runs xgettext over the module
  (`util/`/`vendor/`/`node_modules/`/`tests/` excluded) into `resources/lang/messages.pot`.
  PO files are maintained via Weblate and land in `resources/lang/<language>.po`;
  `LinkEnhancerModule::customTranslations()` feeds them into webtrees' `I18N`, so the calls
  find them **at render time**.
- **After a core update:** if core newly covers a module string, mask it with
  `MoreI18N::xlate()` so it is not double-translated.

### CLI scripts & maintenance

The module ships CLI-only scripts. They are guarded: requested over HTTP (the `modules_v4` directory is inside the web root) they answer `403` instead of running.

| Script | Purpose | Needs a webtrees install + DB? |
| --- | --- | --- |
| `tests/test-text-tag-collector.php` | standalone unit tests for the text-tag collector | no |
| `tests/test-link-classifier.php` | standalone unit tests for the link classifier (incl. target extraction) | no |
| `tests/test-uid-tag-collector.php` | standalone unit tests for the UID tag collector | no |
| `tests/test-uid-index-service.php` | standalone unit tests for the UID index service (pre-filter, candidate check) | no |
| `tests/smoke-text-tag-collector.php [limit]` | scan records containing enhanced links, print every captured `TEXT`/`NOTE`/`_TODO` value with its location | yes |
| `tests/smoke-migration5.php` | SQLite smoke test for Migration5 (UID index schema) | yes |
| `tests/p1-measure.php [--tree=<id>]` | read-only scaling measurement for the XREF overview (query costs, table sizes, PHP limits) | yes |
| `cli/build-link-index.php [--limit=N] [--tree=<id>] [--rebuild] [--flush]` | build/update the link index for the XREF overview (see below) | yes |
| `cli/build-uid-index.php [--limit=N] [--tree=<id>] [--rebuild] [--flush]` | build/update the UID index for UID lookup | yes |
| `cli/...` | maintenance scripts (template in `modules_v4/cronjob/cli/_template-maintenance.php`) | yes |

Run them from the webtrees root with the **same PHP version** the instance runs on (webtrees requires PHP 8.3+):

```
php modules_v4/linkenhancer/tests/test-text-tag-collector.php
php modules_v4/linkenhancer/tests/smoke-text-tag-collector.php 10
```

#### Writing maintenance scripts

`modules_v4/cronjob/cli/_template-maintenance.php` is a copy-paste template for longer-running maintenance jobs. The conventions:

- **SAPI guard**: every script starts with `CliBootstrap::guard()` - the directory is URL-addressable, the guard answers `403` instead of running.
- **Bootstrap**: use `CliBootstrap::boot()` - the core CLI bootstrap sequence (app bootstrap, i18n, config, database) with a hard failure on missing config or DB connection.
- **Arguments**: plain `$argv` (`--limit=N`, `--help`).
- **Lock**: `flock()` on `data/linkenhancer-<name>.lock` so overlapping cron runs do not collide; a held lock is not an error (exit 0).
- **Idempotent batches**: design the job so one run finishes in roughly 5-10 minutes and the next run continues where the last one stopped.
- **Logging**: plain stdout lines (cron mail / log file). Never print personal data - counters and XREFs, never names.
- **Exit codes**: `0` = ok (incl. "nothing to do", "lock held"), `1` = error.

Cron example (every 2 hours, 500 records per run):

```
15 */2 * * * cd /path/to/webtrees && php modules_v4/linkenhancer/cli/<name>.php --limit=500 >> /var/log/linkenhancer-<name>.log 2>&1
```

Note: the script runs with the user that may read `data/config.ini.php` and has database access (usually the web server user or root).
