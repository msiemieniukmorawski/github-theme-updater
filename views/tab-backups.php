<?php
/**
 * Backups tab.
 *
 * @package MSM\GitHubThemeUpdater
 *
 * @var Settings       $settings Settings repository.
 * @var array<int, array<string, mixed>> $backups  Available backups, newest first.
 * @var \WP_Error|null $error    Filesystem error, if any.
 * @var array<string, mixed> $state    Runtime state.
 */

namespace MSM\GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

$gthu_datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
?>

<?php if ( $error ) : ?>
	<div class="notice notice-error inline">
		<p><?php echo esc_html( $error->get_error_message() ); ?></p>
	</div>
	<?php return; ?>
<?php endif; ?>

<div class="gthu-panel">
	<h2><?php esc_html_e( 'Theme backups', 'github-theme-updater' ); ?></h2>
	<p class="gthu-hint">
		<?php esc_html_e( 'A backup is a full snapshot of the theme directory taken just before an update. Restoring one replaces the current theme files with its contents.', 'github-theme-updater' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'gthu_create_backup' ); ?>
		<input type="hidden" name="action" value="gthu_create_backup">
		<button type="submit" class="button" <?php disabled( '' === $settings->theme_dir() ); ?>>
			<?php esc_html_e( 'Back up now', 'github-theme-updater' ); ?>
		</button>
	</form>
</div>

<?php if ( empty( $backups ) ) : ?>
	<div class="gthu-panel gthu-panel--muted">
		<p>
			<?php esc_html_e( 'There are no backups yet. The first one is created automatically with the next theme update.', 'github-theme-updater' ); ?>
		</p>
	</div>
	<?php return; ?>
<?php endif; ?>

<table class="wp-list-table widefat striped gthu-table">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'Version', 'github-theme-updater' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Created', 'github-theme-updater' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Theme', 'github-theme-updater' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Size', 'github-theme-updater' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Author', 'github-theme-updater' ); ?></th>
			<th scope="col" class="gthu-table__actions"><?php esc_html_e( 'Actions', 'github-theme-updater' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $backups as $gthu_backup ) : ?>
			<?php $gthu_author = $gthu_backup['created_by'] ? get_userdata( (int) $gthu_backup['created_by'] ) : null; ?>
			<tr>
				<td>
					<strong>
						<?php
						echo esc_html(
							'' !== (string) $gthu_backup['version']
								? (string) $gthu_backup['version']
								: __( 'unknown', 'github-theme-updater' )
						);
						?>
					</strong>
				</td>
				<td>
					<?php
					echo $gthu_backup['created']
						? esc_html( wp_date( $gthu_datetime_format, (int) $gthu_backup['created'] ) )
						: '&mdash;';
					?>
				</td>
				<td><code><?php echo esc_html( (string) $gthu_backup['theme_slug'] ); ?></code></td>
				<td>
					<?php
					echo $gthu_backup['size']
						? esc_html( size_format( (int) $gthu_backup['size'] ) )
						: '&mdash;';
					?>
				</td>
				<td><?php echo esc_html( $gthu_author ? $gthu_author->display_name : '—' ); ?></td>
				<td class="gthu-table__actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gthu-inline-form">
						<?php wp_nonce_field( 'gthu_restore_backup' ); ?>
						<input type="hidden" name="action" value="gthu_restore_backup">
						<input type="hidden" name="backup" value="<?php echo esc_attr( (string) $gthu_backup['id'] ); ?>">
						<button
							type="submit"
							class="button button-secondary"
							data-gthu-confirm="<?php echo esc_attr__( 'Restore this backup? The current theme files will be replaced.', 'github-theme-updater' ); ?>"
						>
							<?php esc_html_e( 'Restore', 'github-theme-updater' ); ?>
						</button>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gthu-inline-form">
						<?php wp_nonce_field( 'gthu_delete_backup' ); ?>
						<input type="hidden" name="action" value="gthu_delete_backup">
						<input type="hidden" name="backup" value="<?php echo esc_attr( (string) $gthu_backup['id'] ); ?>">
						<button
							type="submit"
							class="button-link gthu-link-danger"
							data-gthu-confirm="<?php echo esc_attr__( 'Delete this backup permanently?', 'github-theme-updater' ); ?>"
						>
							<?php esc_html_e( 'Delete', 'github-theme-updater' ); ?>
						</button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<p class="gthu-hint">
	<?php
	printf(
		/* translators: 1: number of backups kept, 2: URL of the settings tab. */
		esc_html__( 'The last %1$d backups are kept — older ones are deleted automatically. You can change the limit on the %2$s tab.', 'github-theme-updater' ),
		(int) $settings->get( 'backup_limit' ),
		'<a href="' . esc_url( Admin_Page::url( 'settings' ) ) . '">' . esc_html__( 'Settings', 'github-theme-updater' ) . '</a>'
	);
	?>
</p>
