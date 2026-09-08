<?php
/**
 * Settings tab.
 *
 * @package MSM\GitHubThemeUpdater
 *
 * @var Settings             $settings Settings repository.
 * @var array<string, mixed> $state    Runtime state.
 * @var int                  $next_run When the next scheduled run is due, 0 if none.
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

$gthu_option          = Settings::OPTION;
$gthu_token           = $settings->token();
$gthu_from_const      = $settings->token_is_constant();
$gthu_themes          = wp_get_themes();
$gthu_datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$gthu_has_secret      = $settings->has_webhook_secret();
$gthu_secret_const    = $settings->webhook_secret_is_constant();
$gthu_is_branch       = 'branch' === $settings->get( 'source' );
$gthu_wp_cron_off     = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

settings_errors( $gthu_option );
?>

<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="gthu-settings" data-gthu-guard>
	<?php settings_fields( 'gthu_settings_group' ); ?>

	<div class="gthu-panel">
		<h2><?php esc_html_e( 'Where the theme comes from', 'github-theme-updater' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="gthu-repository"><?php esc_html_e( 'GitHub repository', 'github-theme-updater' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="gthu-repository"
						class="regular-text code"
						name="<?php echo esc_attr( $gthu_option ); ?>[repository]"
						value="<?php echo esc_attr( (string) $settings->get( 'repository' ) ); ?>"
						placeholder="company-name/theme-name"
					>
					<p class="description">
						<?php esc_html_e( 'You can simply paste the repository address from your browser — the plugin works out the owner and the name itself.', 'github-theme-updater' ); ?>
						<br>
						<?php esc_html_e( 'Examples: company-name/theme-name, https://github.com/company-name/theme-name', 'github-theme-updater' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="gthu-token"><?php esc_html_e( 'Access token', 'github-theme-updater' ); ?></label>
				</th>
				<td>
					<?php if ( $gthu_from_const ) : ?>
						<p>
							<strong><?php esc_html_e( 'The token is set in wp-config.php', 'github-theme-updater' ); ?></strong>
							(<code>GTHU_GITHUB_TOKEN</code>)
						</p>
						<p class="description">
							<?php esc_html_e( 'This is the safest option — the token never reaches the database. To change it, edit wp-config.php.', 'github-theme-updater' ); ?>
						</p>
					<?php else : ?>
						<input
							type="password"
							id="gthu-token"
							class="regular-text code"
							name="<?php echo esc_attr( $gthu_option ); ?>[token]"
							value=""
							autocomplete="new-password"
							placeholder="<?php echo esc_attr( '' !== $gthu_token ? Token_Storage::mask( $gthu_token ) : 'ghp_...' ); ?>"
						>
						<p class="description">
							<?php if ( '' !== $gthu_token ) : ?>
								<?php esc_html_e( 'A token is already saved. Leave the field empty to keep it.', 'github-theme-updater' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Only required for private repositories.', 'github-theme-updater' ); ?>
							<?php endif; ?>
							<a href="<?php echo esc_url( Admin_Page::url( 'help' ) ); ?>" target="_blank" rel="noopener noreferrer">
								<?php esc_html_e( 'How do I generate a token?', 'github-theme-updater' ); ?>
							</a>
						</p>

						<?php if ( '' !== $gthu_token ) : ?>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $gthu_option ); ?>[clear_token]" value="1">
									<?php esc_html_e( 'Delete the saved token', 'github-theme-updater' ); ?>
								</label>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="gthu-theme-slug"><?php esc_html_e( 'Theme directory', 'github-theme-updater' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="gthu-theme-slug"
						class="regular-text code"
						name="<?php echo esc_attr( $gthu_option ); ?>[theme_slug]"
						value="<?php echo esc_attr( (string) $settings->get( 'theme_slug' ) ); ?>"
						list="gthu-themes"
						placeholder="theme-name"
					>
					<datalist id="gthu-themes">
						<?php foreach ( $gthu_themes as $gthu_slug => $gthu_theme ) : ?>
							<option value="<?php echo esc_attr( $gthu_slug ); ?>">
								<?php echo esc_attr( $gthu_theme->get( 'Name' ) ); ?>
							</option>
						<?php endforeach; ?>
					</datalist>
					<p class="description">
						<?php
						printf(
							/* translators: %s: themes directory path. */
							esc_html__( 'Name of the folder in %s that should be overwritten. That is the one replaced with the repository contents.', 'github-theme-updater' ),
							'<code>' . esc_html( untrailingslashit( get_theme_root() ) ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Download mode', 'github-theme-updater' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Download mode', 'github-theme-updater' ); ?></legend>

						<label>
							<input
								type="radio"
								name="<?php echo esc_attr( $gthu_option ); ?>[source]"
								value="release"
								<?php checked( 'branch' !== $settings->get( 'source' ) ); ?>
							>
							<?php esc_html_e( 'Releases — recommended', 'github-theme-updater' ); ?>
						</label>
						<p class="description gthu-indent">
							<?php esc_html_e( 'You install tagged, finished versions, and can go back to any earlier one.', 'github-theme-updater' ); ?>
						</p>

						<label>
							<input
								type="radio"
								name="<?php echo esc_attr( $gthu_option ); ?>[source]"
								value="branch"
								<?php checked( 'branch', $settings->get( 'source' ) ); ?>
							>
							<?php esc_html_e( 'Branch — the current state of the code', 'github-theme-updater' ); ?>
						</label>
						<p class="description gthu-indent">
							<?php esc_html_e( 'Pulls code straight from a branch, with no releases involved. Handy while working on the theme, but there is no version history to roll back to.', 'github-theme-updater' ); ?>
						</p>
					</fieldset>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="gthu-branch"><?php esc_html_e( 'Branch name', 'github-theme-updater' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="gthu-branch"
						class="regular-text code"
						name="<?php echo esc_attr( $gthu_option ); ?>[branch]"
						value="<?php echo esc_attr( (string) $settings->get( 'branch' ) ); ?>"
						placeholder="main"
					>
					<p class="description">
						<?php esc_html_e( 'Used in branch mode only.', 'github-theme-updater' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="gthu-asset"><?php esc_html_e( 'ZIP file from the release', 'github-theme-updater' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="gthu-asset"
						class="regular-text code"
						name="<?php echo esc_attr( $gthu_option ); ?>[asset_pattern]"
						value="<?php echo esc_attr( (string) $settings->get( 'asset_pattern' ) ); ?>"
						placeholder="theme-*.zip"
					>
					<p class="description">
						<?php esc_html_e( 'Leave empty to download the repository source. Fill it in if you attach a built ZIP package to your releases — give the pattern of its name, for example theme-*.zip.', 'github-theme-updater' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Pre-releases', 'github-theme-updater' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( $gthu_option ); ?>[include_prereleases]"
							value="1"
							<?php checked( (bool) $settings->get( 'include_prereleases' ) ); ?>
						>
						<?php esc_html_e( 'Also show releases marked as pre-release', 'github-theme-updater' ); ?>
					</label>
				</td>
			</tr>
		</table>
	</div>

	<div class="gthu-panel">
		<h2><?php esc_html_e( 'Files protected from overwriting', 'github-theme-updater' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="gthu-protected"><?php esc_html_e( 'Protected paths', 'github-theme-updater' ); ?></label>
				</th>
				<td>
					<textarea
						id="gthu-protected"
						class="large-text code"
						rows="8"
						name="<?php echo esc_attr( $gthu_option ); ?>[protected_paths]"
					><?php echo esc_textarea( (string) $settings->get( 'protected_paths' ) ); ?></textarea>

					<p class="description">
						<?php esc_html_e( 'One path per line, relative to the theme directory. These files and directories are neither deleted nor overwritten with the GitHub version.', 'github-theme-updater' ); ?>
					</p>

					<ul class="gthu-examples">
						<li><code>languages</code> — <?php esc_html_e( 'the whole translations directory', 'github-theme-updater' ); ?></li>
						<li><code>.env</code> — <?php esc_html_e( 'a single file', 'github-theme-updater' ); ?></li>
						<li><code>assets/css/custom.css</code> — <?php esc_html_e( 'a file in a subdirectory', 'github-theme-updater' ); ?></li>
						<li><code>*.log</code> — <?php esc_html_e( 'every file with that extension, at any depth', 'github-theme-updater' ); ?></li>
						<li><code># comment</code> — <?php esc_html_e( 'lines starting with # are ignored', 'github-theme-updater' ); ?></li>
					</ul>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="gthu-ignored"><?php esc_html_e( 'Ignored paths', 'github-theme-updater' ); ?></label>
				</th>
				<td>
					<textarea
						id="gthu-ignored"
						class="large-text code"
						rows="4"
						name="<?php echo esc_attr( $gthu_option ); ?>[ignored_paths]"
					><?php echo esc_textarea( (string) $settings->get( 'ignored_paths' ) ); ?></textarea>

					<p class="description">
						<?php esc_html_e( 'Same syntax. These paths are never copied — not into backups, not from the GitHub archive — and are left alone on disk. Meant for development leftovers such as .git, node_modules or vendor, which can hold tens of thousands of files and turn a backup into a multi-minute job.', 'github-theme-updater' ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Do not ignore a directory the theme needs at runtime, such as a committed vendor directory: it would not be installed from the archive.', 'github-theme-updater' ); ?>
					</p>
				</td>
			</tr>
		</table>
	</div>

	<div class="gthu-panel">
		<h2><?php esc_html_e( 'Backups and notifications', 'github-theme-updater' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Backup before an update', 'github-theme-updater' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( $gthu_option ); ?>[create_backup]"
							value="1"
							<?php checked( (bool) $settings->get( 'create_backup' ) ); ?>
						>
						<?php esc_html_e( 'Back up the theme before every update', 'github-theme-updater' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'This lets a failed update be undone in one click. Recommended.', 'github-theme-updater' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="gthu-backup-limit"><?php esc_html_e( 'How many backups to keep', 'github-theme-updater' ); ?></label>
				</th>
				<td>
					<input
						type="number"
						id="gthu-backup-limit"
						class="small-text"
						min="1"
						max="20"
						name="<?php echo esc_attr( $gthu_option ); ?>[backup_limit]"
						value="<?php echo esc_attr( (string) $settings->get( 'backup_limit' ) ); ?>"
					>
					<p class="description">
						<?php esc_html_e( 'Older backups are deleted automatically so they do not fill up the server.', 'github-theme-updater' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Notifications', 'github-theme-updater' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( $gthu_option ); ?>[check_updates]"
							value="1"
							<?php checked( (bool) $settings->get( 'check_updates' ) ); ?>
						>
						<?php esc_html_e( 'Check automatically and announce new versions in the dashboard', 'github-theme-updater' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Checks run twice a day and only show a notice. Installing without a click is a separate, opt-in feature — see “Automatic updates” below.', 'github-theme-updater' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstalling', 'github-theme-updater' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( $gthu_option ); ?>[delete_data]"
							value="1"
							<?php checked( (bool) $settings->get( 'delete_data' ) ); ?>
						>
						<?php esc_html_e( 'When the plugin is deleted, remove its settings and backups as well', 'github-theme-updater' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'This applies to deleting the plugin, not to deactivating it. The theme is left alone.', 'github-theme-updater' ); ?>
					</p>
				</td>
			</tr>
		</table>
	</div>

	<div class="gthu-panel" id="gthu-automation">
		<h2><?php esc_html_e( 'Automatic updates', 'github-theme-updater' ); ?></h2>
		<p class="gthu-hint">
			<?php esc_html_e( 'With either option on, the plugin installs a newer release by itself, using exactly the same sequence as the Update button: lock, backup, verification, copy, automatic rollback on failure, entry in the operation log. Releases only — a branch has no version number to compare.', 'github-theme-updater' ); ?>
		</p>

		<?php if ( $gthu_is_branch ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'The download mode is set to Branch. Automatic updates will not run until it is switched to Releases.', 'github-theme-updater' ); ?></p>
			</div>
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Scheduled update', 'github-theme-updater' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( $gthu_option ); ?>[auto_update]"
							value="1"
							<?php checked( (bool) $settings->get( 'auto_update' ) ); ?>
						>
						<?php esc_html_e( 'Once a day, check GitHub and install the newest release if it is newer than the installed one', 'github-theme-updater' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'When nothing newer is available, the run does nothing and nobody is e-mailed.', 'github-theme-updater' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="gthu-auto-time"><?php esc_html_e( 'Preferred time', 'github-theme-updater' ); ?></label>
				</th>
				<td>
					<input
						type="time"
						id="gthu-auto-time"
						step="60"
						name="<?php echo esc_attr( $gthu_option ); ?>[auto_update_time]"
						value="<?php echo esc_attr( (string) $settings->get( 'auto_update_time' ) ); ?>"
					>
					<p class="description">
						<?php
						printf(
							/* translators: %s: timezone name from the general settings. */
							esc_html__( 'Site time zone (%s). Pick a quiet hour: the update takes the theme offline for a moment while files are replaced.', 'github-theme-updater' ),
							'<code>' . esc_html( wp_timezone_string() ) . '</code>'
						);
						?>
					</p>

					<?php if ( $next_run ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: date and time. */
								esc_html__( 'Next run planned for: %s', 'github-theme-updater' ),
								'<strong>' . esc_html( wp_date( $gthu_datetime_format, $next_run ) ) . '</strong>'
							);
							?>
						</p>
					<?php endif; ?>

					<details class="gthu-notes">
						<summary><?php esc_html_e( 'Why the update may run later than the time set here', 'github-theme-updater' ); ?></summary>
						<div class="gthu-notes__body">
							<p><?php esc_html_e( 'The time is a “not before”, not a guarantee. WordPress has no clock of its own — its scheduler (WP-Cron) only wakes up when something else runs PHP on the site. The run can therefore be late when:', 'github-theme-updater' ); ?></p>
							<ul class="gthu-examples">
								<li><?php esc_html_e( 'Nobody visits the site. WP-Cron piggybacks on page views, so on a quiet site the 3:00 task runs with the first visitor of the morning — that may be 6:40.', 'github-theme-updater' ); ?></li>
								<li><?php esc_html_e( 'A page cache or CDN serves visitors without touching PHP. Cached pages do not wake the scheduler either, so even a busy site can look empty to it.', 'github-theme-updater' ); ?></li>
								<li>
									<?php
									printf(
										/* translators: %s: PHP constant name. */
										esc_html__( '%s is set in wp-config.php and the site relies on a system cron. The run then happens on the next tick of that cron — every 5 minutes, every hour, whatever the hosting configured.', 'github-theme-updater' ),
										'<code>DISABLE_WP_CRON</code>'
									);
									?>
								</li>
								<li><?php esc_html_e( 'Another operation holds the lock at that moment (an update or restore started by hand, a backup in progress). The run is skipped and tries again the next day.', 'github-theme-updater' ); ?></li>
								<li><?php esc_html_e( 'The site time zone changes, or the clocks move for daylight saving. The schedule is corrected on the next run and on the next settings save, so one run can land an hour off.', 'github-theme-updater' ); ?></li>
								<li><?php esc_html_e( 'The server is busy or the previous cron run is still going. WordPress runs one cron worker at a time and skips a tick it cannot start.', 'github-theme-updater' ); ?></li>
							</ul>
							<p>
								<?php
								printf(
									/* translators: %s: PHP constant definition. */
									esc_html__( 'For a time you can count on, ask the hosting to call wp-cron.php from a system cron every few minutes and add %s to wp-config.php. The Instructions tab shows how.', 'github-theme-updater' ),
									'<code>define( \'DISABLE_WP_CRON\', true );</code>'
								);
								?>
							</p>
							<?php if ( $gthu_wp_cron_off ) : ?>
								<p><strong><?php esc_html_e( 'DISABLE_WP_CRON is set on this site, so the time depends on the system cron of the server.', 'github-theme-updater' ); ?></strong></p>
							<?php endif; ?>
						</div>
					</details>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="gthu-notify-emails"><?php esc_html_e( 'E-mail reports to', 'github-theme-updater' ); ?></label>
				</th>
				<td>
					<input
						type="text"
						id="gthu-notify-emails"
						class="large-text"
						name="<?php echo esc_attr( $gthu_option ); ?>[notify_emails]"
						value="<?php echo esc_attr( (string) $settings->get( 'notify_emails' ) ); ?>"
						placeholder="you@example.com, colleague@example.com"
					>
					<p class="description">
						<?php esc_html_e( 'One or more addresses separated by commas. After every automatic update (scheduled or from the webhook) they receive a plain text message with the previous and the new version, the backup name and the release notes written on GitHub. A failed attempt is reported the same way, with the error. Updates started by hand from the Update tab are not e-mailed.', 'github-theme-updater' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'GitHub webhook', 'github-theme-updater' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( $gthu_option ); ?>[webhook_enabled]"
							value="1"
							<?php checked( (bool) $settings->get( 'webhook_enabled' ) ); ?>
						>
						<?php esc_html_e( 'Update as soon as a release is published on GitHub', 'github-theme-updater' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'GitHub calls this site the moment you click “Publish release”; the plugin then queues an update that runs within a minute or two. The site must be reachable from the internet. Full setup steps are on the Instructions tab.', 'github-theme-updater' ); ?>
					</p>

					<?php if ( $settings->get( 'webhook_enabled' ) && $gthu_has_secret ) : ?>
						<p class="description">
							<?php esc_html_e( 'Payload URL to enter on GitHub:', 'github-theme-updater' ); ?><br>
							<code class="gthu-secret"><?php echo esc_html( Webhook::url() ); ?></code>
						</p>
						<p class="description">
							<?php if ( $gthu_secret_const ) : ?>
								<?php esc_html_e( 'The secret is set in wp-config.php (GTHU_WEBHOOK_SECRET).', 'github-theme-updater' ); ?>
							<?php else : ?>
								<?php
								printf(
									/* translators: %s: date and time. */
									esc_html__( 'A secret was generated on %s and is stored encrypted. It cannot be displayed again; if it was lost, generate a new one and update the webhook on GitHub.', 'github-theme-updater' ),
									esc_html( wp_date( $gthu_datetime_format, (int) $settings->get( 'webhook_secret_at', 0 ) ) )
								);
								?>
							<?php endif; ?>
						</p>
						<?php if ( ! $gthu_secret_const ) : ?>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $gthu_option ); ?>[regenerate_webhook_secret]" value="1">
									<?php esc_html_e( 'Generate a new secret when saving (the old one stops working immediately)', 'github-theme-updater' ); ?>
								</label>
							</p>
						<?php endif; ?>
					<?php elseif ( $settings->get( 'webhook_enabled' ) ) : ?>
						<p class="description"><?php esc_html_e( 'No secret is available yet. Save the settings to generate one.', 'github-theme-updater' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Tick the box and save: the plugin generates a secret and shows it once, together with the address to enter on GitHub.', 'github-theme-updater' ); ?></p>
					<?php endif; ?>

					<?php if ( $state['webhook_last_at'] ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: date and time, 2: what happened. */
								esc_html__( 'Last delivery %1$s: %2$s', 'github-theme-updater' ),
								esc_html( wp_date( $gthu_datetime_format, (int) $state['webhook_last_at'] ) ),
								'<span class="gthu-status gthu-status--' . ( 'rejected' === $state['webhook_last_status'] ? 'error' : 'ok' ) . '">' . esc_html( (string) $state['webhook_last_message'] ) . '</span>'
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<?php if ( $state['auto_last_run'] ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last automatic run', 'github-theme-updater' ); ?></th>
					<td>
						<p>
							<?php
							$gthu_auto_ok = in_array( (string) $state['auto_last_status'], array( 'updated', 'up_to_date' ), true );

							echo esc_html( wp_date( $gthu_datetime_format, (int) $state['auto_last_run'] ) );
							echo ' (' . esc_html( Auto_Updater::trigger_label( (string) $state['auto_last_trigger'] ) ) . ')';
							?>
							<br>
							<span class="gthu-status gthu-status--<?php echo $gthu_auto_ok ? 'ok' : 'error'; ?>"><?php echo esc_html( (string) $state['auto_last_message'] ); ?></span>
						</p>
					</td>
				</tr>
			<?php endif; ?>
		</table>
	</div>

	<?php submit_button( __( 'Save settings', 'github-theme-updater' ) ); ?>
</form>

<div class="gthu-panel">
	<h2><?php esc_html_e( 'Try the automation', 'github-theme-updater' ); ?></h2>
	<p class="gthu-hint">
		<?php esc_html_e( '“Run now” does exactly what the nightly run does — including installing a newer release, if there is one, and e-mailing the report. Save the settings first.', 'github-theme-updater' ); ?>
	</p>
	<div class="gthu-actions">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'gthu_run_auto' ); ?>
			<input type="hidden" name="action" value="gthu_run_auto">
			<button
				type="submit"
				class="button"
				data-gthu-confirm="<?php echo esc_attr__( 'Run the automatic update now? If GitHub has a newer release, it will be installed straight away.', 'github-theme-updater' ); ?>"
			>
				<?php esc_html_e( 'Run now', 'github-theme-updater' ); ?>
			</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'gthu_test_email' ); ?>
			<input type="hidden" name="action" value="gthu_test_email">
			<button type="submit" class="button">
				<?php esc_html_e( 'Send a test e-mail', 'github-theme-updater' ); ?>
			</button>
		</form>
	</div>
</div>

<div class="gthu-panel">
	<h2><?php esc_html_e( 'Check the configuration', 'github-theme-updater' ); ?></h2>
	<p class="gthu-hint">
		<?php esc_html_e( 'The plugin will connect to GitHub and check that the repository is reachable with the saved token. Nothing is downloaded or changed.', 'github-theme-updater' ); ?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'gthu_test' ); ?>
		<input type="hidden" name="action" value="gthu_test">
		<button type="submit" class="button">
			<?php esc_html_e( 'Test connection', 'github-theme-updater' ); ?>
		</button>
	</form>
</div>
