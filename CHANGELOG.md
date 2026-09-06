# Changelog

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [Semantic Versioning](https://semver.org/).

## [2.2.0] — 2026-09-06

### Added

- **Live update progress.** After clicking "Update", the tab lists the steps
  (release lookup, download, unpack, deletability check, backup, directory
  cleanup, copy, finish), a running count of copied files and the elapsed time.
  The form is sent with `fetch()` and the browser polls the
  `wp_ajax_gthu_progress` endpoint once a second; the installer records its
  current step in the `gthu_install_progress` transient (new `Install_Progress`
  class). Without JavaScript the form submits as before.
- Opening the Update tab while an operation is running (from another tab, or
  after a refresh) picks up its progress and reloads the page when it finishes.
- `Filesystem::copy_tree()` and `Backup_Manager::create()` accept an optional
  callback invoked after every copied file.
- The browser warns before leaving the Settings tab with unsaved form changes.
- **Ignored paths** (`ignored_paths` setting, `.git` and `node_modules` by
  default). Unlike protected paths they are left out of backups, never installed
  from the archive and left untouched on disk — during restores too. This covers
  a theme directory full of development leftovers, where the backup alone copied
  over a hundred thousand files and ran into the PHP time limit.
- **Operation log.** The bottom of the Update tab shows the last 5 updates and
  restores, failed ones included: date, version, previous version, result with
  the error text, number of copied files, author and duration. Entries live in
  the `gthu_history` option (new `History` class), the `gthu_history_limit`
  filter changes the limit, and uninstalling with data removal enabled clears
  the log as well.

### Fixed

- **Path traversal in the backup identifier.** `sanitize_id()` let `..`
  through, so a request to delete the backup with ID `..` would have wiped all
  of `wp-content`, and a restore would have copied it into the theme directory.
  It needed an administrator account and a nonce, but still: an ID that is all
  dots after cleaning, or contains `..`, is now rejected. Covered by a test.
- **Lock race.** `is_locked()` and `lock()` were two queries, so two clicks in
  the same second both went through. The lock is now a `wp_options` row
  (`gthu_install_lock`) claimed with a single `INSERT IGNORE`; `lock()` returns
  a `bool`, and progress recording starts only after the lock is held, so it
  can no longer overwrite the progress of a running installation.
- **Manual backups did not take the lock** and could snapshot the theme halfway
  through a copy by a running update. They take the same lock as updates and
  restores.
- **Double token encryption.** `Settings::sanitize()` encrypted every non-empty
  value, so an `update_option()` with an already encrypted token (a migration,
  WP-CLI, REST) would have wrapped it a second time. A value already in storage
  form (`gthu:enc:` / `gthu:raw:`) is left as is.
- **Implicitly nullable parameters** (`Path_Rules $rules = null` and friends),
  deprecated as of PHP 8.4, are now written as `?Path_Rules`. PHP 8.4 added to
  the CI matrix.
- **An undeletable file halfway through cleanup left the site without a theme.**
  Deleting through `WP_Filesystem_Direct` returns `false` for files carrying the
  read-only attribute (on Windows git sets it on pack files inside `.git`,
  including nested ones in `vendor` packages installed from VCS), and a failed
  restore from the backup went unreported. Now: (1) before the backup, the
  installer checks that everything it is about to delete can be deleted — the
  new "Checking the theme files can be replaced" step — and stops before touching
  anything if not; (2) deletion retries after clearing read-only flags; (3) the
  message after a failed cleanup or copy says plainly whether the theme was
  restored from the backup, and, with backups turned off, that the directory may
  be incomplete. Restoring from the Backups tab runs the same check.

### Added (update safety)

- **Theme identity check.** Before touching the directory, the installer reads
  the `style.css` headers of the archive and of the theme on disk: an archive
  without a `Theme Name` is rejected, and a different theme name together with a
  different text domain stops the installation with a clear message. The first
  install into an empty directory has nothing to compare against and goes
  through. The `gthu_theme_identity_matches` filter allows a mismatch to be
  accepted deliberately.
- **Protection against wordpress.org collisions.** The plugin declares
  `Update URI: false`, and a `site_transient_update_themes` filter drops the
  managed theme slug from wordpress.org responses, so WordPress never offers an
  "update" with a stranger's code of the same name.
- **ZIP signature** check (`PK\x03\x04`) on the downloaded file instead of a
  size threshold alone; an error page saved under a `.zip` name ends in a
  readable error.
- **Tests against the real `WP_Filesystem_Direct`** (core classes vendored into
  `tests/fixtures/wp/`): cleanup with protected paths, copying with rules and a
  callback, deleting read-only files, the deletability check. Plus tests for
  rejecting unsafe backup IDs.

### Changed

- The "Release the lock" button appears only two minutes after the lock was
  taken, so it does not tempt anyone during a slow but healthy backup.
- Links between tabs on the Settings and Instructions tabs open in a new tab, so
  a click no longer discards unsaved form input.
- `Plugin URI` removed from the plugin header — it pointed at the same page as
  `Author URI`, giving the plugins list two links to one address. The author
  link stays.
- The "Install this version" button shows "Installing…" once clicked, like the
  main update button.
- Releasing the lock by hand also clears the progress record, and uninstalling
  removes the lock and progress transients.

## [2.1.0] — 2026-09-06

### Changed

- **English is now the source language of every string**, per WordPress
  convention. The Polish interface stays — it ships as a full translation
  (`languages/pl_PL.po` and `pl_PL.mo`, 237 entries) and switches on by itself
  on a site running in Polish. The plugin can now be read and translated by
  someone outside Poland, and the repository stops being a barrier to entry.
- The plugin description in the file header is in English too; its Polish
  version lives in the translation file.
- Sentences such as "Change the limit under Settings" were rebuilt around "on
  the %s tab", so a tab name is one string rather than two inflected forms.

### Fixed

- `Github_Client::request()` declared an array return type but could return any
  JSON-decoded value (`true`, a number). A response that is not an array now ends
  in a readable error instead of a surprise at the call site.
- Dead code flagged by static analysis removed: `null` comparisons for
  `dirlist()` that were always false, and a redundant `isset()` on a key that
  always exists.

### Added

- **GitHub Actions** (`.github/workflows/ci.yml`): syntax check and tests on
  PHP 7.4–8.3, PHPCS and PHPStan on 8.3, on every push and pull request.
- **PHPStan at level 6** with WordPress stubs (`phpstan.neon.dist`) — passes
  clean. Array types across the codebase were tightened along the way.
- `composer phpstan`, and `composer check` extended to run it.
- `docs/screenshots/` with instructions on what to capture and how, plus a
  "Screenshots" section in the README.
- Composer platform pinned to PHP 7.4 so the lock file matches the declared
  minimum.

## [2.0.1] — 2026-09-06

### Fixed

- **Backups vanished whenever anything else was updated.** They were stored in
  `wp-content/upgrade/`, which `WP_Upgrader::unpack_package()` clears before
  every core, plugin and theme update. The default location is now
  `wp-content/gthu-backups/`; existing backups are moved automatically on
  activation or version bump.
- **A theme in a directory with a capital letter could not be updated.** The
  directory name went through `sanitize_key()`, which lowercases it — for themes
  such as `Divi` or `Avada-Child` this produced a path that does not exist. Case
  is now preserved.
- The working directory stays in `wp-content/upgrade/` — there WordPress
  clearing it is welcome, because those are temporary files.

### Security

- **Multisite:** the required capability is now `manage_network_themes`. The
  themes directory is shared by the whole network, so a single-site administrator
  should not be able to replace a theme other sites are running. No change
  outside multisite (`manage_options`).
- Saving settings through `options.php` was gated by `manage_options` only,
  regardless of the capability required elsewhere in the plugin. Added the
  `option_page_capability_gthu_settings_group` filter.
- The backup directory also gets a `web.config` — `.htaccess` alone does not
  protect on IIS.

### Added

- A **Release the lock** button on the Update tab, with a note on how long the
  lock has been held. Previously a killed process meant a 15-minute wait.
- A dashboard warning on multisite installations.
- A PHPUnit suite (92 tests, 145 assertions) for `Path_Rules`, `Repository`,
  `Release`, `Token_Storage` and theme directory name validation. These classes
  do not depend on WordPress, so the tests run without an installation.
- `composer.json` with `lint`, `phpcs`, `phpcbf`, `test` and `check` scripts.
- `phpcs.xml.dist` — WordPress Coding Standards ruleset; the code passes clean.
- `phpunit.xml.dist`, `LICENSE` (GPL-2.0), `CHANGELOG.md`, `.gitignore`.

### Changed

- `unlink()` replaced with `wp_delete_file()`.
- Parameters colliding with PHP reserved words renamed (`$protected`, `$list`,
  `$default`, `$class`).
- README available in English (`README.md`) and Polish (`README.pl.md`).

## [2.0.0] — 2026-09-06

The plugin rewritten from a single file into a class structure.

### Fixed

- **No nonce or capability checks.** Any logged-in user who found the admin URL
  could trigger an update.
- **Theme directory deleted before copying.** A failed copy left the theme
  unusable. Nothing is touched now until a backup exists, and an error during
  installation restores it automatically.
- **Looking for a directory named after the slug inside the archive.** GitHub
  packages sources as `owner-repo-sha`, so that directory practically never
  existed. The theme is now located by its `style.css`.
- **`basename($zip_url)` used as the version number** — the stored version is now
  the release tag.
- File operations go through `WP_Filesystem` instead of direct `unlink()` and
  `copy()`.
- A lock against concurrent updates and restores.
- `ignore_user_abort( true )` — closing the tab no longer interrupts a copy
  halfway.

### Added

- A configurable list of protected paths (directories, files, `*.log` patterns,
  comments) instead of a hard-coded `/languages`.
- Backups with rotation, restore and a tab of their own.
- Branch mode alongside release mode; a pattern for a ZIP asset attached to a
  release; pre-release support.
- Repository detection from any GitHub address (browser URL, SSH, `.git`, the
  API address from version 1.0).
- The token is encrypted with AES-256-CBC before it is stored; alternatively the
  `GTHU_GITHUB_TOKEN` constant in `wp-config.php`.
- Scheduled version checks and a dashboard notice.
- A step-by-step instructions tab with error diagnostics.
- Full i18n (`github-theme-updater` text domain) with a `.pot` file.
- Hooks: `gthu_before_install`, `gthu_after_install`, `gthu_install_failed`,
  and the `gthu_capability`, `gthu_backup_dir`, `gthu_cache_lifetime`,
  `gthu_download_timeout` filters.

### Changed

- Author: ms-m.pl.
- Options from version 1.0 migrate automatically; the old options are left in
  place so the previous version can be restored.

## [1.0] — 2025-03-31

First version: a single file, downloading the latest GitHub release, hard-coded
protection of the `/languages` directory.
