<?php
/**
 * Update tab.
 *
 * @package MSM\GitHubThemeUpdater
 *
 * @var Settings        $settings   Settings repository.
 * @var array<string, mixed> $state      Runtime state.
 * @var bool            $configured Whether the plugin is ready to run.
 * @var Release|null    $latest     Newest available release.
 * @var Release[]       $releases   Every available release.
 * @var \WP_Error|null  $error        Error raised while talking to GitHub.
 * @var Repository|null $repository   Configured repository.
 * @var int             $locked_since When the running operation claimed the lock, 0 if none.
 * @var bool            $progress_running Whether an installation is reporting progress right now.
 * @var array<int, array<string, mixed>> $history Recent operations, newest first.
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

$gthu_datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$gthu_installed       = (string) $state['installed_version'];
$gthu_theme_dir       = $settings->theme_dir();
$gthu_protected       = $settings->protected_paths();
$gthu_ignored         = $settings->ignored_paths();
$gthu_is_branch       = 'branch' === $settings->get( 'source' );
$gthu_update_needed   = $latest && $latest->is_newer_than( $gthu_installed );
?>

<?php if ( ! $configured ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<strong><?php esc_html_e( 'The plugin is not configured yet.', 'github-theme-updater' ); ?></strong>
			<?php esc_html_e( 'Enter a GitHub repository and a theme directory to start pulling updates.', 'github-theme-updater' ); ?>
		</p>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( Admin_Page::url( 'settings' ) ); ?>">
				<?php esc_html_e( 'Go to the settings', 'github-theme-updater' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( Admin_Page::url( 'help' ) ); ?>">
				<?php esc_html_e( 'See the step-by-step instructions', 'github-theme-updater' ); ?>
			</a>
		</p>
	</div>
	<?php return; ?>
<?php endif; ?>

<?php if ( $error ) : ?>
	<div class="notice notice-error inline">
		<p><strong><?php esc_html_e( 'Could not fetch data from GitHub.', 'github-theme-updater' ); ?></strong></p>
		<p><?php echo esc_html( $error->get_error_message() ); ?></p>
	</div>
<?php endif; ?>

<?php if ( is_multisite() ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<strong><?php esc_html_e( 'Multisite installation.', 'github-theme-updater' ); ?></strong>
			<?php esc_html_e( 'The themes directory is shared across the whole network — an update replaces the theme files for every site using it.', 'github-theme-updater' ); ?>
		</p>
	</div>
<?php endif; ?>

<?php if ( $locked_since ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<strong><?php esc_html_e( 'An operation on the theme files is running.', 'github-theme-updater' ); ?></strong>
			<?php
			printf(
				/* translators: %s: human readable time difference, e.g. "2 minutes". */
				esc_html__( 'Started %s ago. Updates and restores are blocked while it runs.', 'github-theme-updater' ),
				esc_html( human_time_diff( $locked_since ) )
			);
			?>
		</p>
		<p>
			<?php esc_html_e( 'If the previous operation was interrupted — a server restart, a timeout — the lock expires by itself after 15 minutes. You can also release it by hand.', 'github-theme-updater' ); ?>
		</p>
		<?php if ( time() - $locked_since >= 2 * MINUTE_IN_SECONDS ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'gthu_release_lock' ); ?>
				<input type="hidden" name="action" value="gthu_release_lock">
				<button
					type="submit"
					class="button"
					data-gthu-confirm="<?php echo esc_attr__( 'Release the lock? Do this only if you are certain no update is still running — otherwise two operations can overwrite each other’s files.', 'github-theme-updater' ); ?>"
				>
					<?php esc_html_e( 'Release the lock', 'github-theme-updater' ); ?>
				</button>
			</form>
		<?php else : ?>
			<p><em><?php esc_html_e( 'The button to release the lock appears after two minutes, so a slow but healthy update is not interrupted by accident.', 'github-theme-updater' ); ?></em></p>
		<?php endif; ?>
	</div>
<?php endif; ?>

<div
	class="gthu-progress"
	data-gthu-progress-panel
	<?php echo $progress_running ? 'data-gthu-autostart' : 'hidden'; ?>
	aria-live="polite"
>
	<div class="gthu-progress__header">
		<span class="gthu-progress__spinner" aria-hidden="true"></span>
		<strong class="gthu-progress__title"><?php esc_html_e( 'Updating the theme…', 'github-theme-updater' ); ?></strong>
		<span class="gthu-progress__elapsed" data-gthu-elapsed title="<?php esc_attr_e( 'Elapsed time', 'github-theme-updater' ); ?>">0:00</span>
	</div>
	<ol class="gthu-progress__steps">
		<?php foreach ( Install_Progress::steps() as $gthu_step => $gthu_step_label ) : ?>
			<li class="gthu-progress__step" data-gthu-step="<?php echo esc_attr( $gthu_step ); ?>">
				<span class="gthu-progress__icon" aria-hidden="true"></span>
				<span class="gthu-progress__label"><?php echo esc_html( $gthu_step_label ); ?></span>
				<span class="gthu-progress__detail" data-gthu-detail></span>
			</li>
		<?php endforeach; ?>
		<?php foreach ( Install_Progress::recovery_steps() as $gthu_step => $gthu_step_label ) : ?>
			<li class="gthu-progress__step gthu-progress__step--recovery" data-gthu-step="<?php echo esc_attr( $gthu_step ); ?>" hidden>
				<span class="gthu-progress__icon" aria-hidden="true"></span>
				<span class="gthu-progress__label"><?php echo esc_html( $gthu_step_label ); ?></span>
				<span class="gthu-progress__detail" data-gthu-detail></span>
			</li>
		<?php endforeach; ?>
	</ol>
	<p class="gthu-hint gthu-progress__note">
		<?php esc_html_e( 'The update continues on the server even if you close this tab. The result will appear on this screen when it finishes.', 'github-theme-updater' ); ?>
	</p>
</div>

<div class="gthu-cards">
	<div class="gthu-card">
		<span class="gthu-card__label"><?php esc_html_e( 'Installed version', 'github-theme-updater' ); ?></span>
		<span class="gthu-card__value">
			<?php echo esc_html( '' !== $gthu_installed ? $gthu_installed : __( 'unknown', 'github-theme-updater' ) ); ?>
		</span>
		<span class="gthu-card__meta">
			<?php
			if ( $state['installed_at'] ) {
				printf(
					/* translators: %s: date and time of the last update. */
					esc_html__( 'Updated %s', 'github-theme-updater' ),
					esc_html( wp_date( $gthu_datetime_format, (int) $state['installed_at'] ) )
				);
			} else {
				esc_html_e( 'This site has not been updated by the plugin yet.', 'github-theme-updater' );
			}
			?>
		</span>
	</div>

	<div class="gthu-card<?php echo $gthu_update_needed ? ' gthu-card--highlight' : ''; ?>">
		<span class="gthu-card__label">
			<?php
			echo $gthu_is_branch
				? esc_html__( 'Version on the branch', 'github-theme-updater' )
				: esc_html__( 'Newest on GitHub', 'github-theme-updater' );
			?>
		</span>
		<span class="gthu-card__value">
			<?php echo esc_html( $latest ? $latest->tag() : __( 'no data', 'github-theme-updater' ) ); ?>
		</span>
		<span class="gthu-card__meta">
			<?php
			if ( $latest && $gthu_is_branch ) {
				esc_html_e( 'Branch mode — the current state of the code is always installed.', 'github-theme-updater' );
			} elseif ( $gthu_update_needed ) {
				esc_html_e( 'A version newer than the installed one is available.', 'github-theme-updater' );
			} elseif ( $latest ) {
				esc_html_e( 'You are on the newest version.', 'github-theme-updater' );
			}
			?>
		</span>
	</div>

	<div class="gthu-card">
		<span class="gthu-card__label"><?php esc_html_e( 'Repository', 'github-theme-updater' ); ?></span>
		<span class="gthu-card__value gthu-card__value--small">
			<?php if ( $repository ) : ?>
				<a href="<?php echo esc_url( $repository->html_url() ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html( $repository->full_name() ); ?>
				</a>
			<?php endif; ?>
		</span>
		<span class="gthu-card__meta">
			<?php
			printf(
				/* translators: %s: theme directory name. */
				esc_html__( 'Theme: %s', 'github-theme-updater' ),
				'<code>' . esc_html( (string) $settings->get( 'theme_slug' ) ) . '</code>'
			);
			?>
		</span>
	</div>
</div>

<?php if ( '' !== $gthu_theme_dir && ! is_dir( $gthu_theme_dir ) ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			printf(
				/* translators: %s: theme directory path. */
				esc_html__( 'The directory %s does not exist yet. The first update will create it and install the theme from scratch.', 'github-theme-updater' ),
				'<code>' . esc_html( $gthu_theme_dir ) . '</code>'
			);
			?>
		</p>
	</div>
<?php endif; ?>

<div class="gthu-panel">
	<h2><?php esc_html_e( 'Install the newest version', 'github-theme-updater' ); ?></h2>

	<p class="gthu-hint">
		<?php if ( ! $gthu_protected->is_empty() ) : ?>
			<?php
			printf(
				/* translators: %s: comma separated list of protected paths. */
				esc_html__( 'These paths will not be overwritten or deleted: %s.', 'github-theme-updater' ),
				'<code>' . implode( '</code>, <code>', array_map( 'esc_html', $gthu_protected->all() ) ) . '</code>'
			);
			?>
			<a href="<?php echo esc_url( Admin_Page::url( 'settings' ) ); ?>"><?php esc_html_e( 'Change the list', 'github-theme-updater' ); ?></a>
		<?php else : ?>
			<?php esc_html_e( 'No protected paths are set — the entire contents of the theme directory will be replaced.', 'github-theme-updater' ); ?>
			<a href="<?php echo esc_url( Admin_Page::url( 'settings' ) ); ?>"><?php esc_html_e( 'Add protected files', 'github-theme-updater' ); ?></a>
		<?php endif; ?>
	</p>

	<?php if ( ! $gthu_ignored->is_empty() ) : ?>
		<p class="gthu-hint">
			<?php
			printf(
				/* translators: %s: comma separated list of ignored paths. */
				esc_html__( 'Ignored — neither backed up nor installed: %s.', 'github-theme-updater' ),
				'<code>' . implode( '</code>, <code>', array_map( 'esc_html', $gthu_ignored->all() ) ) . '</code>'
			);
			?>
		</p>
	<?php endif; ?>

	<p class="gthu-hint">
		<?php if ( $settings->get( 'create_backup', true ) ) : ?>
			<?php esc_html_e( 'The plugin will back up the current theme before updating.', 'github-theme-updater' ); ?>
		<?php else : ?>
			<strong><?php esc_html_e( 'Backups are turned off.', 'github-theme-updater' ); ?></strong>
			<?php esc_html_e( 'Without them a failed update cannot be undone in one click.', 'github-theme-updater' ); ?>
		<?php endif; ?>
	</p>

	<div class="gthu-actions">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-gthu-progress>
			<?php wp_nonce_field( 'gthu_install' ); ?>
			<input type="hidden" name="action" value="gthu_install">
			<input type="hidden" name="tag" value="latest">
			<button
				type="submit"
				class="button button-primary button-hero"
				<?php disabled( ! $latest ); ?>
				data-gthu-confirm="<?php echo esc_attr__( 'Update the theme now? The theme files will be replaced with the GitHub version.', 'github-theme-updater' ); ?>"
				data-gthu-busy-label="<?php echo esc_attr__( 'Updating…', 'github-theme-updater' ); ?>"
			>
				<?php
				if ( $latest ) {
					printf(
						/* translators: %s: version to install. */
						esc_html__( 'Update to %s', 'github-theme-updater' ),
						esc_html( $latest->tag() )
					);
				} else {
					esc_html_e( 'No version to install', 'github-theme-updater' );
				}
				?>
			</button>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'gthu_refresh' ); ?>
			<input type="hidden" name="action" value="gthu_refresh">
			<button type="submit" class="button">
				<?php esc_html_e( 'Check again', 'github-theme-updater' ); ?>
			</button>
		</form>
	</div>

	<?php if ( $state['last_check'] ) : ?>
		<p class="gthu-hint">
			<?php
			printf(
				/* translators: %s: date and time of the last check. */
				esc_html__( 'Last check: %s', 'github-theme-updater' ),
				esc_html( wp_date( $gthu_datetime_format, (int) $state['last_check'] ) )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( $latest && '' !== trim( $latest->notes() ) ) : ?>
		<details class="gthu-notes">
			<summary><?php esc_html_e( 'What changed in this version', 'github-theme-updater' ); ?></summary>
			<div class="gthu-notes__body"><?php echo wp_kses_post( wpautop( $latest->notes() ) ); ?></div>
		</details>
	<?php endif; ?>
</div>

<?php if ( count( $releases ) > 1 ) : ?>
	<div class="gthu-panel">
		<h2><?php esc_html_e( 'Roll back to an earlier version', 'github-theme-updater' ); ?></h2>
		<p class="gthu-hint">
			<?php esc_html_e( 'Installs the selected release from GitHub. It works exactly like an update — backup and protected paths included.', 'github-theme-updater' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gthu-inline-form" data-gthu-progress>
			<?php wp_nonce_field( 'gthu_install' ); ?>
			<input type="hidden" name="action" value="gthu_install">

			<label class="screen-reader-text" for="gthu-release">
				<?php esc_html_e( 'Choose a version', 'github-theme-updater' ); ?>
			</label>
			<select name="tag" id="gthu-release">
				<?php foreach ( $releases as $gthu_release ) : ?>
					<option value="<?php echo esc_attr( $gthu_release->tag() ); ?>">
						<?php
						echo esc_html( $gthu_release->label() );

						if ( $gthu_release->tag() === $gthu_installed ) {
							echo ' — ' . esc_html__( 'installed', 'github-theme-updater' );
						}

						if ( $gthu_release->is_prerelease() ) {
							echo ' — ' . esc_html__( 'pre-release', 'github-theme-updater' );
						}
						?>
					</option>
				<?php endforeach; ?>
			</select>

			<button
				type="submit"
				class="button"
				data-gthu-confirm="<?php echo esc_attr__( 'Install the selected version of the theme?', 'github-theme-updater' ); ?>"
				data-gthu-busy-label="<?php echo esc_attr__( 'Installing…', 'github-theme-updater' ); ?>"
			>
				<?php esc_html_e( 'Install this version', 'github-theme-updater' ); ?>
			</button>
		</form>
	</div>
<?php endif; ?>

<div class="gthu-panel">
	<h2><?php esc_html_e( 'Recent operations', 'github-theme-updater' ); ?></h2>
	<p class="gthu-hint">
		<?php
		printf(
			/* translators: %s: number of entries kept in the log. */
			esc_html__( 'Up to %s most recent updates and restores, newest first. Failed attempts are listed too.', 'github-theme-updater' ),
			esc_html( number_format_i18n( History::limit() ) )
		);
		?>
	</p>

	<?php if ( empty( $history ) ) : ?>
		<p class="gthu-hint"><em><?php esc_html_e( 'Nothing has been recorded yet.', 'github-theme-updater' ); ?></em></p>
	<?php else : ?>
		<table class="wp-list-table widefat striped gthu-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'github-theme-updater' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Operation', 'github-theme-updater' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'github-theme-updater' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Author', 'github-theme-updater' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Duration', 'github-theme-updater' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history as $gthu_entry ) : ?>
					<?php
					$gthu_entry_user  = ! empty( $gthu_entry['user'] ) ? get_userdata( (int) $gthu_entry['user'] ) : null;
					$gthu_entry_ok    = 'success' === $gthu_entry['status'];
					$gthu_entry_files = (int) $gthu_entry['files'];
					?>
					<tr>
						<td><?php echo esc_html( wp_date( $gthu_datetime_format, (int) $gthu_entry['time'] ) ); ?></td>
						<td>
							<strong>
								<?php
								if ( 'restore' === $gthu_entry['action'] ) {
									printf(
										/* translators: %s: version restored from the backup. */
										esc_html__( 'Restore from backup (version %s)', 'github-theme-updater' ),
										esc_html( '' !== (string) $gthu_entry['version'] ? (string) $gthu_entry['version'] : __( 'unknown', 'github-theme-updater' ) )
									);
								} else {
									printf(
										/* translators: %s: version that was installed. */
										esc_html( _x( 'Update to %s', 'history entry', 'github-theme-updater' ) ),
										esc_html( (string) $gthu_entry['version'] )
									);
								}
								?>
							</strong>
							<?php if ( '' !== (string) $gthu_entry['previous'] ) : ?>
								<br>
								<span class="gthu-card__meta">
									<?php
									printf(
										/* translators: %s: version installed before the operation. */
										esc_html__( 'previously %s', 'github-theme-updater' ),
										esc_html( (string) $gthu_entry['previous'] )
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $gthu_entry_ok ) : ?>
								<span class="gthu-status gthu-status--ok"><?php esc_html_e( 'Completed', 'github-theme-updater' ); ?></span>
								<?php if ( $gthu_entry_files > 0 ) : ?>
									<span class="gthu-card__meta">
										<?php
										printf(
											/* translators: %s: number of files copied. */
											esc_html( _n( '%s file', '%s files', $gthu_entry_files, 'github-theme-updater' ) ),
											esc_html( number_format_i18n( $gthu_entry_files ) )
										);
										?>
									</span>
								<?php endif; ?>
							<?php else : ?>
								<span class="gthu-status gthu-status--error"><?php esc_html_e( 'Failed', 'github-theme-updater' ); ?></span>
								<?php if ( '' !== (string) $gthu_entry['message'] ) : ?>
									<br>
									<span class="gthu-card__meta"><?php echo esc_html( (string) $gthu_entry['message'] ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td>
							<?php
							$gthu_entry_trigger = (string) $gthu_entry['trigger'];

							if ( 'schedule' === $gthu_entry_trigger ) {
								esc_html_e( 'Automatic (schedule)', 'github-theme-updater' );
							} elseif ( 'webhook' === $gthu_entry_trigger ) {
								esc_html_e( 'Automatic (GitHub webhook)', 'github-theme-updater' );
							} else {
								echo esc_html( $gthu_entry_user ? $gthu_entry_user->display_name : '—' );
							}
							?>
						</td>
						<td><?php echo esc_html( History::format_duration( (float) $gthu_entry['duration'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>

<?php if ( '' !== (string) $state['last_error'] ) : ?>
	<div class="gthu-panel gthu-panel--muted">
		<h2><?php esc_html_e( 'Last error', 'github-theme-updater' ); ?></h2>
		<p><?php echo esc_html( (string) $state['last_error'] ); ?></p>
	</div>
<?php endif; ?>
