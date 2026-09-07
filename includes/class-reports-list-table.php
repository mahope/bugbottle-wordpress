<?php
/**
 * The list of reports, as a plain `WP_List_Table` rather than the post-type
 * list screen — the post type has `show_ui` off, so there is no list screen to
 * customise, and the columns here are report fields rather than post fields.
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

namespace Bugbottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// `WP_List_Table` is an admin class core loads on demand, not on every
// request, so a plugin that extends it has to ask for the file itself.
if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Type, title, page, reporter, date and status.
 */
final class Reports_List_Table extends \WP_List_Table {

	private const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'bugbottle_report',
				'plural'   => 'bugbottle_reports',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'type'     => __( 'Type', 'bugbottle' ),
			'title'    => __( 'Report', 'bugbottle' ),
			'page'     => __( 'Page', 'bugbottle' ),
			'reporter' => __( 'Reporter', 'bugbottle' ),
			'date'     => __( 'Date', 'bugbottle' ),
			'status'   => __( 'Status', 'bugbottle' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = array(
			'post_type'      => Storage::POST_TYPE,
			'post_status'    => 'private',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( Storage::STATUS_OPEN === $status || Storage::STATUS_DONE === $status ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => Storage::META_STATUS,
					'value' => $status,
				),
			);
		}

		$query       = new \WP_Query( $args );
		$this->items = $query->posts;

		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		$current = isset( $_GET['status'] ) ? sanitize_key( (string) $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base    = admin_url( 'admin.php?page=bugbottle' );
		$views   = array();
		foreach ( array(
			''                    => __( 'All', 'bugbottle' ),
			Storage::STATUS_OPEN  => __( 'Open', 'bugbottle' ),
			Storage::STATUS_DONE  => __( 'Done', 'bugbottle' ),
		) as $key => $label ) {
			$url            = '' === $key ? $base : add_query_arg( 'status', $key, $base );
			$views[ $key ]  = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $url ),
				$current === $key ? ' class="current"' : '',
				esc_html( $label )
			);
		}
		return $views;
	}

	public function no_items(): void {
		esc_html_e( 'No reports yet.', 'bugbottle' );
	}

	/**
	 * @param \WP_Post $item        The report.
	 * @param string   $column_name Column name.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'type':
				return esc_html( Admin::type_label( (string) get_post_meta( $item->ID, Storage::META_TYPE, true ) ) );

			case 'page':
				$context = json_decode( (string) get_post_meta( $item->ID, Storage::META_CONTEXT, true ), true );
				$url     = is_array( $context ) && isset( $context['url'] ) ? (string) $context['url'] : '';
				return '' === $url ? '—' : '<code>' . esc_html( $url ) . '</code>';

			case 'reporter':
				return esc_html( Email::reporter_name( (int) get_post_meta( $item->ID, Storage::META_REPORTER, true ) ) );

			case 'date':
				return esc_html( get_the_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item ) );

			case 'status':
				$status = Storage::status( $item->ID );
				return Storage::STATUS_DONE === $status
					? esc_html__( 'Done', 'bugbottle' )
					: '<strong>' . esc_html__( 'Open', 'bugbottle' ) . '</strong>';

			default:
				return '';
		}
	}

	/**
	 * @param \WP_Post $item The report.
	 */
	public function column_title( $item ): string {
		$url = add_query_arg(
			array(
				'page'   => 'bugbottle',
				'report' => $item->ID,
			),
			admin_url( 'admin.php' )
		);
		return sprintf(
			'<a href="%s"><strong>%s</strong></a>',
			esc_url( $url ),
			esc_html( get_the_title( $item ) )
		);
	}
}
