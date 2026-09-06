<?php
/**
 * Settings tab.
 *
 * @package MSM\GitHubThemeUpdater
 *
 * @var Settings $settings Settings repository.
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

$gthu_option     = Settings::OPTION;
$gthu_token      = $settings->token();
$gthu_from_const = $settings->token_is_constant();
$gthu_themes     = wp_get_themes();

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
						<?php esc_html_e( 'Checks run twice a day. The plugin never updates the theme by itself — the decision is always yours.', 'github-theme-updater' ); ?>
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

	<?php submit_button( __( 'Save settings', 'github-theme-updater' ) ); ?>
</form>

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
