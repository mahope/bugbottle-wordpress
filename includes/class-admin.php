<?php
/**
 * The admin screens: a list of reports, a detail view, and settings.
 *
 * These are rendered by hand rather than by the post editor. A report is a
 * record somebody reads once and marks done — the editor would offer to
 * rewrite it, and would put the screenshot in the media library, where it
 * would get a public URL.
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

namespace Bugbottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu, list screen, detail screen.
 */
final class Admin {

	public const PAGE = 'bugbottle';

	public static function register_menu(): void {
		add_menu_page(
			__( 'Bug reports', 'bugbottle' ),
			__( 'Bug reports', 'bugbottle' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render_page' ),
			'dashicons-warning',
			26
		);

		add_submenu_page(
			self::PAGE,
			__( 'Bug reports', 'bugbottle' ),
			__( 'All reports', 'bugbottle' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render_page' )
		);

		add_submenu_page(
			self::PAGE,
			__( 'Bugbottle settings', 'bugbottle' ),
			__( 'Settings', 'bugbottle' ),
			'manage_options',
			self::PAGE . '-settings',
			array( Settings::class, 'render_page' )
		);
	}

	/**
	 * The list, or one report when `?report=` names one.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to read bug reports.', 'bugbottle' ) );
		}

		$report_id = isset( $_GET['report'] ) ? absint( $_GET['report'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $report_id > 0 ) {
			self::render_detail( $report_id );
			return;
		}
		self::render_list();
	}

	private static function render_list(): void {
		$table = new Reports_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bug reports', 'bugbottle' ); ?></h1>
			<?php if ( '' === Settings::string( 'email_recipient' ) ) : ?>
				<div class="notice notice-info"><p>
					<?php
					printf(
						/* translators: %s: link to the settings screen */
						esc_html__( 'No email recipient is set, so reports are only stored here. %s', 'bugbottle' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=bugbottle-settings' ) ) . '">'
							. esc_html__( 'Settings', 'bugbottle' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<?php $table->views(); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private static function render_detail( int $report_id ): void {
		$post = get_post( $report_id );
		if ( ! $post instanceof \WP_Post || Storage::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'No such report.', 'bugbottle' ) );
		}

		$report      = Storage::to_report( $post );
		$has_shot    = '' !== $report['screenshot'];
		$status      = Storage::status( $report_id );
		$next_status = Storage::STATUS_DONE === $status ? Storage::STATUS_OPEN : Storage::STATUS_DONE;
		$summary     = Markdown::render(
			$report,
			array(
				'facts'            => array(
					'Reporter' => Email::reporter_name( (int) $report['reporter'] ),
					'Received' => get_the_time( 'c', $post ),
				),
				'screenshot_url'   => $has_shot ? Rest::screenshot_url( $report_id ) : null,
				'collapse_console' => false,
			)
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_the_title( $post ) ); ?></h1>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=bugbottle' ) ); ?>">
					&larr; <?php esc_html_e( 'All reports', 'bugbottle' ); ?>
				</a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em">
				<input type="hidden" name="action" value="bugbottle_set_status">
				<input type="hidden" name="report" value="<?php echo esc_attr( (string) $report_id ); ?>">
				<input type="hidden" name="status" value="<?php echo esc_attr( $next_status ); ?>">
				<?php wp_nonce_field( 'bugbottle_set_status_' . $report_id ); ?>
				<button type="submit" class="button">
					<?php
					echo Storage::STATUS_DONE === $status
						? esc_html__( 'Reopen', 'bugbottle' )
						: esc_html__( 'Mark as done', 'bugbottle' );
					?>
				</button>
			</form>

			<?php if ( $has_shot ) : ?>
				<h2><?php esc_html_e( 'Screenshot', 'bugbottle' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Served only to administrators, through the plugin route. It is not in the media library and has no public URL.', 'bugbottle' ); ?>
				</p>
				<p>
					<img src="<?php echo esc_url( Rest::screenshot_url( $report_id ) ); ?>" alt=""
						style="max-width:100%;height:auto;border:1px solid #c3c4c7">
				</p>
			<?php endif; ?>

			<?php
			self::render_breadcrumbs( Validator::breadcrumbs( $report['breadcrumbs'] ?? null ) );
			self::render_network( Validator::network( $report['network'] ?? null ) );
			?>

			<h2><?php esc_html_e( 'Summary', 'bugbottle' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Markdown, ready to paste into an issue.', 'bugbottle' ); ?>
			</p>
			<textarea readonly rows="24" style="width:100%;font-family:Menlo,Consolas,monospace;font-size:12px"
				onclick="this.select()"><?php echo esc_textarea( $summary ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * What the reporter did before they reported, oldest first. The Markdown
	 * summary carries the same timeline, but this is the one a reader scans:
	 * the textarea below is for pasting, not for reading.
	 *
	 * @param array<int, array<string, string>> $breadcrumbs Validated breadcrumbs.
	 */
	private static function render_breadcrumbs( array $breadcrumbs ): void {
		if ( 0 === count( $breadcrumbs ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'What happened before', 'bugbottle' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'bugbottle' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What', 'bugbottle' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Where', 'bugbottle' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $breadcrumbs as $crumb ) : ?>
					<tr>
						<td><?php echo esc_html( $crumb['ts'] ); ?></td>
						<td><?php echo esc_html( self::breadcrumb_label( $crumb['kind'] ) ); ?></td>
						<td>
							<code><?php echo esc_html( self::breadcrumb_where( $crumb ) ); ?></code>
							<?php if ( isset( $crumb['text'] ) && '' !== $crumb['text'] ) : ?>
								<?php echo esc_html( ' — "' . $crumb['text'] . '"' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** The translated verb for a breadcrumb kind. */
	private static function breadcrumb_label( string $kind ): string {
		switch ( $kind ) {
			case 'click':
				return __( 'Clicked', 'bugbottle' );
			case 'submit':
				return __( 'Submitted', 'bugbottle' );
			case 'navigation':
				return __( 'Navigated', 'bugbottle' );
			default:
				return __( 'Page visibility', 'bugbottle' );
		}
	}

	/**
	 * The selector a click or a submit names, or the two ends of a navigation.
	 *
	 * @param array<string, string> $crumb A validated breadcrumb.
	 */
	private static function breadcrumb_where( array $crumb ): string {
		if ( 'navigation' === $crumb['kind'] ) {
			$from = $crumb['from'] ?? '';
			return '' !== $from ? $from . ' → ' . ( $crumb['to'] ?? '' ) : ( $crumb['to'] ?? '' );
		}
		return $crumb['target'] ?? ( $crumb['to'] ?? '' );
	}

	/**
	 * The requests that failed or were slow before the report. A request that
	 * never got a status shows the failure rather than a bare zero, which
	 * reads as a status nobody recognises.
	 *
	 * @param array<int, array<string, mixed>> $network Validated network entries.
	 */
	private static function render_network( array $network ): void {
		if ( 0 === count( $network ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Requests', 'bugbottle' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Requests that failed or were slow. Bodies and headers are never recorded, in either direction.', 'bugbottle' ); ?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Method', 'bugbottle' ); ?></th>
					<th scope="col"><?php esc_html_e( 'URL', 'bugbottle' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'bugbottle' ); ?></th>
					<th scope="col"><?php esc_html_e( 'ms', 'bugbottle' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $network as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $entry['method'] ); ?></td>
						<td><code><?php echo esc_html( (string) $entry['url'] ); ?></code></td>
						<td>
							<?php
							$status = (int) $entry['status'];
							if ( $status > 0 ) {
								echo esc_html( (string) $status );
							} elseif ( ! empty( $entry['error'] ) ) {
								esc_html_e( 'Failed', 'bugbottle' );
							}
							?>
						</td>
						<td><?php echo esc_html( (string) (int) $entry['ms'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Toggles a report between open and done.
	 */
	public static function handle_status_change(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change a report.', 'bugbottle' ) );
		}
		$report_id = isset( $_POST['report'] ) ? absint( $_POST['report'] ) : 0;
		check_admin_referer( 'bugbottle_set_status_' . $report_id );

		$status = isset( $_POST['status'] ) ? sanitize_key( (string) $_POST['status'] ) : Storage::STATUS_OPEN;
		$post   = get_post( $report_id );
		if ( $post instanceof \WP_Post && Storage::POST_TYPE === $post->post_type ) {
			update_post_meta(
				$report_id,
				Storage::META_STATUS,
				Storage::STATUS_DONE === $status ? Storage::STATUS_DONE : Storage::STATUS_OPEN
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=bugbottle&report=' . $report_id ) );
		exit;
	}

	/**
	 * The translated label for a report type. The Markdown rendering keeps the
	 * library's English labels on purpose; these are for the screens.
	 */
	public static function type_label( string $type ): string {
		switch ( $type ) {
			case 'bug':
				return __( 'Bug', 'bugbottle' );
			case 'idea':
				return __( 'Idea', 'bugbottle' );
			default:
				return __( 'Feedback', 'bugbottle' );
		}
	}
}
