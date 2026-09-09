=== GitHub Theme Updater ===
Contributors: msiemieniukmorawski
Tags: github, deployment, backup, rollback, theme
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.3.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Update a WordPress theme straight from a GitHub repository, private ones included, with backups, rollback and protected paths.

== Description ==

GitHub Theme Updater installs a theme into your site from the GitHub repository the theme is developed in. It fits the case where the theme is your own code, lives in a repository you control, and never passes through the wordpress.org theme directory.

An update runs the same sequence every time, whether a person clicks the button, the nightly schedule fires, or a GitHub webhook triggers it:

1. Ask the GitHub API for the newest release and compare it with what is installed.
2. Download the release source, or a ZIP attached to the release when you point at one.
3. Verify that the archive really is a theme, and the same theme as the one on disk.
4. Check that every file that would be deleted can be deleted, before deleting any of them.
5. Take a full backup of the theme directory.
6. Replace the files, leaving protected paths untouched.
7. Restore the backup automatically if anything fails during the copy, and write the outcome to the operation log.

= What it does =

* Public and private repositories. A personal access token is stored encrypted with AES-256-CBC, under a key derived from the WordPress salts. The token can live in a GTHU_GITHUB_TOKEN constant in wp-config.php instead, which survives moving a site between environments.
* Releases or a branch. Releases mode installs tagged versions and can roll back to any earlier one. Branch mode pulls the current state of a branch, for while you are working on the theme.
* Protected paths. One rule per line, relative to the theme root: a directory, a single file, or a wildcard such as *.log. Protected paths are never overwritten or deleted by an update, and they still go into every backup. The defaults are languages, .env and acf-json.
* Ignored paths. Paths the plugin never looks at at all: not backed up, not installed, not deleted. The defaults are .git and node_modules, development leftovers that can hold tens of thousands of files.
* Backups and rollback. A full snapshot of the theme directory before every update, restorable in one click. The number kept is configurable and older ones are pruned automatically. Backups are deliberately not stored under wp-content/upgrade/, because WordPress empties that directory before every core, plugin and theme update.
* Automatic updates, opt-in. A nightly run at an hour you choose, and a GitHub webhook that updates the moment a release is published. Both are off by default, both follow the sequence above, and both e-mail a report.
* Operation log. The last few updates and restores, failed ones included, with the version, the result, the number of copied files, the user and the duration.

= What it does not do =

* It does not touch the database. Theme options, the Customizer, menus, widgets and content are untouched by an update.
* It does not manage plugins. It manages one theme directory, the one named in the settings. That can be a parent or a child theme.
* It does not send your code or your token anywhere except to the GitHub API.

= External services =

The plugin talks to the GitHub REST API at api.github.com, and only to it. It asks for the release list of the repository you configure, and downloads the release archive when you install or roll back. Requests carry your access token when one is configured. Nothing is sent to any other host, and no data is transmitted anywhere on a site where no repository has been configured. GitHub's terms of service are at https://docs.github.com/site-policy/github-terms/github-terms-of-service and its privacy statement at https://docs.github.com/site-policy/privacy-policies/github-privacy-statement

= Requirements =

WordPress 5.8 or newer, PHP 7.4 or newer, and the OpenSSL extension for token encryption. A GitHub token is only required for private repositories, though adding one for a public repository lifts GitHub's limit of 60 requests per hour per IP address, which shared hosting tends to hit.

== Installation ==

1. Install and activate the plugin.
2. Open Appearance -> Theme from GitHub -> Settings.
3. Enter the repository as owner/repo, or paste any GitHub URL - the plugin works the owner and the name out itself.
4. For a private repository, paste a personal access token with repo scope. Alternatively add define( 'GTHU_GITHUB_TOKEN', '...' ); to wp-config.php.
5. Enter the name of the theme directory that the repository should overwrite.
6. Choose Releases mode (recommended) or Branch mode, review the protected paths, and save.
7. Click Test connection to confirm the repository is reachable, then use the Update tab.

== Frequently Asked Questions ==

= Can a failed update take down a live site? =

The theme directory is not touched until the backup has been taken. A download error, a bad token, a corrupt archive or a missing style.css each ends with a message and zero changes on disk. If the update fails later, while copying files, the theme is restored automatically from the backup made moments earlier.

There is one real window of risk: between emptying the theme directory and copying the new files in. On a large theme that takes a few seconds, and visitors may see an error during it. Run updates outside peak hours.

= Files that were not in the repository disappeared after an update. Why? =

That is deliberate: after an update the theme directory is meant to match the repository. This covers both files deleted from the repository and files uploaded to the server by hand. Anything that has to survive belongs on the protected paths list before the update runs.

The most common case is vendor/ or node_modules/ being listed in .gitignore, so it is missing from the GitHub archive and the theme stops working after an update. Two ways out: add the directory to the protected paths, or attach a built ZIP to the release and set the ZIP file pattern.

= Will I lose theme settings, content or widgets? =

No. An update only affects files in the theme directory. The database, theme options, the Customizer, menus and content are untouched.

= Do I need a token for a public repository? =

No. A token is only required for private repositories. It is still worth adding one, because without it GitHub limits traffic to 60 requests per hour per IP address, which on shared hosting tends to end in a 403.

= I set the automatic update to 3:00 and it ran at 6:40. Why? =

WordPress has no clock of its own. WP-Cron only wakes up when a request runs PHP on the site, so the hour you pick is the earliest the run can start, not the exact time. A quiet site, a page cache or a CDN can all delay it, and so can a held lock or a time zone change. For an hour you can count on, add define( 'DISABLE_WP_CRON', true ); to wp-config.php and have a system cron call wp-cron.php every few minutes. The Run now button does not depend on any of this.

= Is it safe to expose the webhook address? =

While the option is off the endpoint does not exist and returns 404. While it is on, every request must carry GitHub's X-Hub-Signature-256 HMAC-SHA256 signature under a secret only you and GitHub know, compared in constant time; anything unsigned or wrongly signed is refused before it is read further. Only release published events for the configured repository are accepted, and repeated deliveries are ignored. The request itself never decides what gets installed - it only queues the same run the schedule uses, which asks the GitHub API with your own token.

= Can I update automatically from a branch? =

No. A branch has no version number, so the plugin cannot tell whether the current state of the code is newer than what is installed. Both automatic options are saved but do nothing in Branch mode, and the Settings tab says so. Publish releases instead.

= Are the backups reachable from the web? =

The backup directory gets an index.php, an .htaccess and a web.config that deny access. Those cover Apache and IIS. On nginx you have to add the rule yourself, or move the backups outside the public directory with the gthu_backup_dir filter. Backups take space: theme size times the number of versions kept.

= Does it work on multisite? =

It runs, but treat it as untested territory. Because the themes directory is shared across the network, the plugin requires the manage_network_themes capability there, so a single site administrator cannot replace a theme other sites are running. Settings are still per-site, so two sites pointing at the same theme directory would overwrite each other; the Update tab warns about this.

= What happens when I delete the plugin? =

Nothing is removed unless you first ticked "When the plugin is deleted, remove its settings and backups as well" in the settings. With that off, deleting the plugin leaves the settings, the operation log and the backups in place. With it on, the options, the operation log and the backup directory are all removed. The theme itself is never touched either way.

== Screenshots ==

1. The Update tab: installed version, newest version on GitHub, the protected paths an update will leave alone, and the rollback list.
2. The Settings tab: repository, token, theme directory, download mode, protected paths, backups and notifications.
3. The Backups tab: every snapshot with its version, date, size and author, each restorable in one click.
4. The Instructions tab: the step-by-step setup guide shipped with the plugin.

== Changelog ==

= 2.3.1 =
* Fixed: uninstalling with data removal enabled left the backup directory on disk, because the cleanup pointed at the pre-2.1 location only. Both the current and the legacy directory are now removed.
* Changed: the plugin no longer declares Update URI: false, so updates can be served from this directory. The theme managed by the plugin is still filtered out of directory responses.
* Added: release automation, a wordpress.org readme, and directory assets.

= 2.3.0 =
* Added: scheduled automatic updates. Pick an hour and the plugin installs a newer release once a day, through the same sequence as the update button. Releases mode only.
* Added: e-mail reports for automatic runs, with the previous and the new version, the backup name, the file count, the protected paths and the release notes.
* Added: a GitHub webhook that updates the moment a release is published. Every delivery is verified against an HMAC-SHA256 signature and matched to the configured repository.

= 2.2.0 =
* Added: an operation log of the last updates and restores, with the result, the file count, the user and the duration.
* Added: live progress on the Update tab, so a running update shows its current step and reloads itself when the update finishes.

= 2.1.0 =
* Added: ignored paths, which are never backed up, never installed and never deleted.
* Changed: backups moved out of wp-content/upgrade/, which WordPress empties before every update.

= 2.0.0 =
* Rewritten as a namespaced, class-per-file plugin with a settings screen, backups, rollback and protected paths.

= 1.0 =
* First release.

The full changelog, naming every hook and filter, is in CHANGELOG.md in the repository.

== Upgrade Notice ==

= 2.3.1 =
Fixes backups being left on disk when the plugin is deleted with data removal enabled. Recommended for anyone who relies on that setting.

= 2.3.0 =
Adds opt-in scheduled updates, e-mail reports and a GitHub webhook. Nothing changes unless you switch them on.
