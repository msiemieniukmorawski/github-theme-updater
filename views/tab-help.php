<?php
/**
 * Help tab.
 *
 * @package MSM\GitHubThemeUpdater
 *
 * @var Settings        $settings   Settings repository.
 * @var Repository|null $repository Configured repository.
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

$gthu_repo_name = $repository ? $repository->full_name() : 'company-name/theme-name';
?>

<div class="gthu-help">

	<div class="gthu-panel">
		<h2><?php esc_html_e( 'How it works', 'github-theme-updater' ); ?></h2>
		<ol class="gthu-steps">
			<li><?php esc_html_e( 'You keep the theme code in a GitHub repository.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'When the theme is ready, you publish a new release on GitHub with a version number.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'This plugin sees the new release and installs it on the site in one click.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Before overwriting anything it takes a backup, and leaves the files you nominated untouched.', 'github-theme-updater' ); ?></li>
		</ol>
	</div>

	<div class="gthu-panel">
		<h2><?php esc_html_e( 'Step 1. Generate an access token', 'github-theme-updater' ); ?></h2>
		<p class="gthu-hint">
			<?php esc_html_e( 'A token is only needed when the repository is private. For a public one you can skip this step.', 'github-theme-updater' ); ?>
		</p>

		<h3><?php esc_html_e( 'Recommended: a fine-grained token', 'github-theme-updater' ); ?></h3>
		<ol class="gthu-steps">
			<li>
				<?php
				printf(
					/* translators: %s: link to the GitHub token settings page. */
					esc_html__( 'Go to %s and click “Generate new token”.', 'github-theme-updater' ),
					'<a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">github.com/settings/personal-access-tokens</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Token name: anything you like, for example “Theme updates — site name”. Including the site name helps you tell later which token is used where.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Expiration: set an expiry date, for example 90 days. After that a new token has to be generated.', 'github-theme-updater' ); ?></li>
			<li>
				<?php
				printf(
					/* translators: %s: repository name. */
					esc_html__( 'Repository access: choose “Only select repositories” and pick %s.', 'github-theme-updater' ),
					'<code>' . esc_html( $gthu_repo_name ) . '</code>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: required permission name. */
					esc_html__( 'Permissions → Repository permissions: set %s. Nothing else is needed.', 'github-theme-updater' ),
					'<code>Contents: Read-only</code>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Click “Generate token” and copy the result straight away — GitHub shows it only once.', 'github-theme-updater' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Simpler: a classic token', 'github-theme-updater' ); ?></h3>
		<ol class="gthu-steps">
			<li>
				<?php
				printf(
					/* translators: %s: link to the classic token settings page. */
					esc_html__( 'Go to %s and choose “Generate new token (classic)”.', 'github-theme-updater' ),
					'<a href="https://github.com/settings/tokens" target="_blank" rel="noopener noreferrer">github.com/settings/tokens</a>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: required scope name. */
					esc_html__( 'Under “Scopes” tick only %s — that grants access to private repositories.', 'github-theme-updater' ),
					'<code>repo</code>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Generate the token and copy it.', 'github-theme-updater' ); ?></li>
		</ol>

		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'Safer, outside the database:', 'github-theme-updater' ); ?></strong>
				<?php esc_html_e( 'you can also put the token in wp-config.php. It then never reaches the database and is not visible in the admin:', 'github-theme-updater' ); ?>
			</p>
			<p><code>define( 'GTHU_GITHUB_TOKEN', 'paste_your_token_here' );</code></p>
		</div>
	</div>

	<div class="gthu-panel">
		<h2><?php esc_html_e( 'Step 2. Fill in the plugin settings', 'github-theme-updater' ); ?></h2>
		<ol class="gthu-steps">
			<li>
				<?php
				printf(
					/* translators: %s: URL of the settings tab. */
					esc_html__( 'Go to the %s tab.', 'github-theme-updater' ),
					'<a href="' . esc_url( Admin_Page::url( 'settings' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Settings', 'github-theme-updater' ) . '</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'In “GitHub repository”, paste the repository address from your browser.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'In “Access token”, paste the token from step 1.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'In “Theme directory”, enter the name of the theme folder on this site — suggestions appear when you click the field.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Save the settings and click “Test connection”. A green message with the repository name should appear.', 'github-theme-updater' ); ?></li>
		</ol>
	</div>

	<div class="gthu-panel">
		<h2><?php esc_html_e( 'Step 3. Publish a new version of the theme', 'github-theme-updater' ); ?></h2>
		<p class="gthu-hint">
			<?php esc_html_e( 'You do this on the code side, every time you want to ship a new version.', 'github-theme-updater' ); ?>
		</p>

		<h3><?php esc_html_e( 'A. Tag the version in the repository', 'github-theme-updater' ); ?></h3>
		<p><?php esc_html_e( 'In the terminal, inside the project directory, once your changes are committed:', 'github-theme-updater' ); ?></p>
		<pre class="gthu-code"><code>git tag -a v1.2.0 -m "Short description of changes"
git push origin v1.2.0</code></pre>
		<p class="description">
			<?php
			printf(
				/* translators: 1: example version number, 2: example version number. */
				esc_html__( 'Version numbers use the %1$s format. Each release has to carry a higher number (%2$s), otherwise the plugin will not treat it as newer.', 'github-theme-updater' ),
				'<code>v1.2.0</code>',
				'<code>v1.2.1</code>'
			);
			?>
		</p>

		<h3><?php esc_html_e( 'B. Create a release on GitHub', 'github-theme-updater' ); ?></h3>
		<ol class="gthu-steps">
			<li>
				<?php
				printf(
					/* translators: %s: link to the repository releases page. */
					esc_html__( 'Open the releases page of the repository: %s.', 'github-theme-updater' ),
					$repository
						? '<a href="' . esc_url( $repository->releases_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $repository->releases_url() ) . '</a>'
						: '<code>github.com/' . esc_html( $gthu_repo_name ) . '/releases</code>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Click “Draft a new release”.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Under “Choose a tag”, pick the tag you just pushed.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Enter a release title and, optionally, a list of changes. You will see that description later on the Update tab.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Click “Publish release”.', 'github-theme-updater' ); ?></li>
			<li>
				<?php
				printf(
					/* translators: %s: URL of the update tab. */
					esc_html__( 'Go back to the %s tab and click “Check again” — the new version should show up.', 'github-theme-updater' ),
					'<a href="' . esc_url( Admin_Page::url( 'update' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Update', 'github-theme-updater' ) . '</a>'
				);
				?>
			</li>
		</ol>
	</div>

	<div class="gthu-panel" id="gthu-help-automation">
		<h2><?php esc_html_e( 'Step 4 (optional). Let the plugin update the theme by itself', 'github-theme-updater' ); ?></h2>
		<p class="gthu-hint">
			<?php esc_html_e( 'Two ways, which can be combined: a nightly run at a chosen hour, and a GitHub webhook that starts the update the moment a release is published. Both use the same sequence as the Update button — backup, verification, automatic rollback, operation log — and both e-mail a report. Both work in Releases mode only.', 'github-theme-updater' ); ?>
		</p>

		<h3><?php esc_html_e( 'A. Nightly schedule', 'github-theme-updater' ); ?></h3>
		<ol class="gthu-steps">
			<li>
				<?php
				printf(
					/* translators: %s: URL of the settings tab. */
					esc_html__( 'On the %s tab, under “Automatic updates”, tick “Scheduled update” and pick a quiet hour.', 'github-theme-updater' ),
					'<a href="' . esc_url( Admin_Page::url( 'settings' ) ) . '#gthu-automation" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Settings', 'github-theme-updater' ) . '</a>'
				);
				?>
			</li>
			<li><?php esc_html_e( 'Enter the e-mail addresses that should receive the report, separated by commas, and save.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Click “Send a test e-mail” to make sure messages from this site arrive. If they do not, the site needs an SMTP plugin — WordPress alone often ends up in spam.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Click “Run now” once. It does exactly what the nightly run will do, so you see the whole chain work while you are watching.', 'github-theme-updater' ); ?></li>
		</ol>

		<h4><?php esc_html_e( 'Why the run may be late, and how to make the hour exact', 'github-theme-updater' ); ?></h4>
		<p><?php esc_html_e( 'WordPress has no clock of its own. Its scheduler, WP-Cron, only wakes up when a page view or another request runs PHP. The chosen hour is therefore a “not before”. Common reasons for a late run:', 'github-theme-updater' ); ?></p>
		<ul class="gthu-examples">
			<li><?php esc_html_e( 'No visitors at night — the task waits for the first request after the scheduled time.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'A page cache or a CDN answers visitors without running PHP, so even traffic does not wake the scheduler.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'The hosting disabled WP-Cron and runs a system cron instead; the update happens on its next tick.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Another update, restore or backup holds the lock at that moment; the run is skipped until the next day.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'The site time zone or daylight saving changed; the schedule corrects itself on the next run or settings save.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'The server is busy or the previous cron worker is still running; WordPress skips a tick it cannot start.', 'github-theme-updater' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'For an exact hour, disable the page-view trigger and let the server call the scheduler every few minutes. Add this to wp-config.php:', 'github-theme-updater' ); ?></p>
		<pre class="gthu-code"><code>define( 'DISABLE_WP_CRON', true );</code></pre>
		<p><?php esc_html_e( 'and ask the hosting for a system cron entry like this (most control panels have a form for it):', 'github-theme-updater' ); ?></p>
		<pre class="gthu-code"><code>*/5 * * * * curl -s <?php echo esc_html( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?> &gt; /dev/null 2&gt;&amp;1</code></pre>

		<h3><?php esc_html_e( 'B. Update the moment a release is published (GitHub webhook)', 'github-theme-updater' ); ?></h3>
		<p><?php esc_html_e( 'GitHub sends a signed message to this site every time a release is published. The plugin verifies the signature, checks that the message concerns the configured repository, and queues an update that runs within a minute or two. It never installs what the message says: it asks GitHub for the newest release with your own token, exactly like the nightly run. The site must be reachable from the internet — a local development site is not, unless a tunnel is used.', 'github-theme-updater' ); ?></p>
		<ol class="gthu-steps">
			<li>
				<?php
				printf(
					/* translators: %s: URL of the settings tab. */
					esc_html__( 'On the %s tab, tick “Update as soon as a release is published on GitHub” and save. A green box shows the secret once — copy it now.', 'github-theme-updater' ),
					'<a href="' . esc_url( Admin_Page::url( 'settings' ) ) . '#gthu-automation" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Settings', 'github-theme-updater' ) . '</a>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					/* translators: %s: link to the repository webhook settings page. */
					esc_html__( 'On GitHub, open %s and click “Add webhook”.', 'github-theme-updater' ),
					$repository
						? '<a href="' . esc_url( $repository->html_url() . '/settings/hooks/new' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $repository->full_name() . ' → Settings → Webhooks' ) . '</a>'
						: '<code>github.com/' . esc_html( $gthu_repo_name ) . '/settings/hooks</code>'
				);
				?>
			</li>
			<li>
				<?php esc_html_e( 'Payload URL: the address shown under the checkbox after saving:', 'github-theme-updater' ); ?>
				<code class="gthu-secret"><?php echo esc_html( Webhook::url() ); ?></code>
			</li>
			<li><?php esc_html_e( 'Content type: application/json.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Secret: paste the secret copied in step 1.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'SSL verification: leave enabled (the site must have a valid HTTPS certificate).', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Which events: choose “Let me select individual events”, tick only “Releases” and untick “Pushes”.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Make sure “Active” is ticked and click “Add webhook”. GitHub sends a ping straight away; under “Recent Deliveries” it should show a green tick with response 200, and the Settings tab shows “GitHub sent a ping”.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Publish the next release as usual. Within a couple of minutes the theme is updated, the operation log lists it as “Automatic (GitHub webhook)”, and the report arrives by e-mail.', 'github-theme-updater' ); ?></li>
		</ol>

		<h4><?php esc_html_e( 'How the webhook is protected', 'github-theme-updater' ); ?></h4>
		<ul class="gthu-examples">
			<li><?php esc_html_e( 'The address does not exist at all while the feature is off — it answers 404 like any unknown URL.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Every message must carry a signature (HMAC-SHA256 of the body under the secret). It is checked in constant time before anything else happens; unsigned or wrongly signed requests are rejected with 401/403 and only leave a note on the Settings tab.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'The secret is 64 random hexadecimal characters, generated by the plugin, stored encrypted like the token and never displayed again. It can be replaced at any time, or kept in wp-config.php as GTHU_WEBHOOK_SECRET.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Only “release published” events for the configured repository are accepted; a message about another repository is rejected. Drafts and, unless enabled, pre-releases are ignored.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'The message never decides what gets installed. The plugin fetches the release list from GitHub with its own token and installs the newest one only if it is newer than the installed version.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'Each delivery has an identifier; a redelivered or replayed message is recognised and does nothing. Oversized bodies are refused before being read.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'The update itself runs under the same lock as a manual one, so it can never overlap with an update or a restore started from the admin.', 'github-theme-updater' ); ?></li>
		</ul>
	</div>

	<div class="gthu-panel">
		<h2><?php esc_html_e( 'What exactly happens during an update', 'github-theme-updater' ); ?></h2>
		<ol class="gthu-steps">
			<li><?php esc_html_e( 'The plugin downloads the ZIP archive of the selected version from GitHub.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'It unpacks it into a temporary directory and checks that there really is a theme inside, by looking for style.css.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'It backs up the current theme.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'It empties the theme directory, skipping protected paths.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'It copies the new files in, again skipping protected paths.', 'github-theme-updater' ); ?></li>
			<li><?php esc_html_e( 'If anything goes wrong while copying, the theme is restored automatically from the backup.', 'github-theme-updater' ); ?></li>
		</ol>
		<p class="gthu-hint">
			<?php esc_html_e( 'Nothing in the theme directory changes until the backup exists, so a download error or a corrupt archive cannot disturb a working site.', 'github-theme-updater' ); ?>
		</p>
	</div>

	<div class="gthu-panel">
		<h2><?php esc_html_e( 'When something goes wrong', 'github-theme-updater' ); ?></h2>

		<h3><?php esc_html_e( '“GitHub rejected the token (401)”', 'github-theme-updater' ); ?></h3>
		<p><?php esc_html_e( 'The token expired, or it was copied incompletely. Generate a new one and save it under Settings.', 'github-theme-updater' ); ?></p>

		<h3><?php esc_html_e( '“No access to this repository (403)”', 'github-theme-updater' ); ?></h3>
		<p><?php esc_html_e( 'The token exists but does not cover this repository. For a fine-grained token, check that the repository is on the “Only select repositories” list and has the Contents: Read-only permission.', 'github-theme-updater' ); ?></p>

		<h3><?php esc_html_e( '“Repository not found (404)”', 'github-theme-updater' ); ?></h3>
		<p><?php esc_html_e( 'Usually a typo in the repository name — or the repository is private and the token has not been saved yet.', 'github-theme-updater' ); ?></p>

		<h3><?php esc_html_e( '“No style.css was found in the downloaded archive”', 'github-theme-updater' ); ?></h3>
		<p><?php esc_html_e( 'The repository should hold the theme itself — style.css in the repository root, or one directory below it. If you keep all of wp-content there, point the plugin at a repository holding only the theme instead.', 'github-theme-updater' ); ?></p>

		<h3><?php esc_html_e( '“WordPress has no direct file access”', 'github-theme-updater' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: %s: PHP constant definition. */
				esc_html__( 'The server does not let WordPress write files directly. Usually adding this to wp-config.php is enough: %s — if it does not help, the problem is permissions on the themes directory.', 'github-theme-updater' ),
				'<code>define( \'FS_METHOD\', \'direct\' );</code>'
			);
			?>
		</p>

		<h3><?php esc_html_e( 'The client’s changes disappeared after an update', 'github-theme-updater' ); ?></h3>
		<p>
			<?php
			printf(
				/* translators: %s: URL of the settings tab. */
				esc_html__( 'An update replaces the theme files with the repository version. Add anything that has to survive to the protected paths list on the %s tab.', 'github-theme-updater' ),
				'<a href="' . esc_url( Admin_Page::url( 'settings' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Settings', 'github-theme-updater' ) . '</a>'
			);
			?>
		</p>
	</div>

</div>
