<?php
/**
 * Where a report lives: a private custom post type, its meta, and the
 * screenshot on disk.
 *
 * `show_ui` is false and the post type is not public. The admin screens under
 * "Bug reports" are rendered by `Admin` rather than by the post editor,
 * because a report is a record to read, not a document to edit — and because
 * the default editor would happily expose a screenshot through the media
 * library.
 *
 * Screenshots are written to `wp-content/uploads/bugbottle/` with a random
 * name, behind a deny rule, and served back only through the admin-only REST
 * route. See the privacy section of the README: the file name is looked up
 * from the row, never taken from the request.
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

namespace Bugbottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The `bugbottle_report` post type and everything written alongside it.
 */
final class Storage {

	public const POST_TYPE = 'bugbottle_report';

	public const META_TYPE        = '_bugbottle_type';
	public const META_CONTACT     = '_bugbottle_contact';
	public const META_CONTEXT     = '_bugbottle_context';
	public const META_CONSOLE     = '_bugbottle_console';
	public const META_ELEMENTS    = '_bugbottle_elements';
	public const META_BREADCRUMBS = '_bugbottle_breadcrumbs';
	public const META_NETWORK     = '_bugbottle_network';
	public const META_PERF        = '_bugbottle_perf';
	public const META_STORAGE     = '_bugbottle_storage';
	public const META_SCREENSHOT  = '_bugbottle_screenshot';
	public const META_REPORTER    = '_bugbottle_reporter';
	public const META_EXTRA       = '_bugbottle_extra';
	public const META_STATUS      = '_bugbottle_status';

	public const STATUS_OPEN = 'open';
	public const STATUS_DONE = 'done';

	public static function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Bug reports', 'bugbottle' ),
					'singular_name' => __( 'Bug report', 'bugbottle' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'custom-fields' ),
			)
		);
	}

	/**
	 * Creates `wp-content/uploads/bugbottle/` and the files that keep a web
	 * server from serving what is in it. The `.htaccess` covers Apache; nginx
	 * needs a location block, which the README and `nginx.conf.example` spell
	 * out — hence the note file next to it, so whoever finds the directory
	 * finds the warning too.
	 *
	 * @return string|null The directory path, or null when it could not be made.
	 */
	public static function ensure_upload_dir(): ?string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'bugbottle';
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$htaccess,
				"# Screenshots can contain anything the reporter could see.\n"
				. "# They are served only through the admin-only REST route.\n"
				. "Deny from all\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			);
		}

		$note = $dir . '/README.txt';
		if ( ! file_exists( $note ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
				$note,
				"Bugbottle screenshots.\n\n"
				. "These files can contain anything the reporter could see: a customer record,\n"
				. "an inbox, a half-written message. The .htaccess here denies direct access on\n"
				. "Apache. On nginx it does nothing, and you must add the deny yourself:\n\n"
				. "    location ~* /wp-content/uploads/bugbottle/ { deny all; }\n\n"
				. "Screenshots are meant to be read through wp-admin only.\n"
			);
		}

		// An index file so a server with directory listings on shows nothing.
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * Writes decoded PNG bytes under a random name.
	 *
	 * @return string|null The file name (not a path, not a URL), or null on failure.
	 */
	public static function store_screenshot( string $bytes ): ?string {
		$dir = self::ensure_upload_dir();
		if ( null === $dir ) {
			return null;
		}
		$name = bin2hex( random_bytes( 16 ) ) . '.png';
		$path = $dir . '/' . $name;
		$written = file_put_contents( $path, $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			return null;
		}
		return $name;
	}

	/**
	 * Absolute path of a stored screenshot, or null when the row carries none
	 * or the name is not one we wrote. The name is only ever read back from
	 * the row, so this is belt and braces — but a path traversal here would
	 * hand out arbitrary files, so it is worth the four lines.
	 */
	public static function screenshot_path( int $post_id ): ?string {
		$name = get_post_meta( $post_id, self::META_SCREENSHOT, true );
		if ( ! is_string( $name ) || 1 !== preg_match( '/^[0-9a-f]{32}\.png$/', $name ) ) {
			return null;
		}
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return null;
		}
		$path = trailingslashit( $uploads['basedir'] ) . 'bugbottle/' . $name;
		return file_exists( $path ) ? $path : null;
	}

	/**
	 * Stores a validated report and returns the new post id, or a WP_Error.
	 *
	 * @param array<string, mixed> $report Validated report fields.
	 * @return int|\WP_Error
	 */
	public static function insert( array $report ) {
		$type    = (string) $report['type'];
		$message = (string) $report['message'];

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_title'   => Markdown::title_for( $type, $message ),
				'post_content' => $message,
				'post_author'  => (int) ( $report['reporter'] ?? 0 ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_TYPE, $type );
		// Absent rather than stored empty: a reader can then tell "the form
		// never asked" from "asked and left blank", and there is no empty
		// personal-data row sitting in the meta table of every report.
		if ( ! empty( $report['contact'] ) ) {
			update_post_meta( $post_id, self::META_CONTACT, (string) $report['contact'] );
		}
		update_post_meta( $post_id, self::META_CONTEXT, wp_json_encode( $report['context'] ) );
		update_post_meta( $post_id, self::META_CONSOLE, wp_json_encode( $report['console'] ) );
		update_post_meta( $post_id, self::META_ELEMENTS, wp_json_encode( $report['elements'] ) );
		update_post_meta( $post_id, self::META_BREADCRUMBS, wp_json_encode( $report['breadcrumbs'] ) );
		update_post_meta( $post_id, self::META_NETWORK, wp_json_encode( $report['network'] ?? array() ) );
		// A snapshot that was never measured is absent rather than stored as
		// an empty object: a reader can then tell "nothing was measured" from
		// "measured and empty", which is the distinction the validators make.
		if ( ! empty( $report['perf'] ) ) {
			update_post_meta( $post_id, self::META_PERF, wp_json_encode( $report['perf'] ) );
		}
		if ( ! empty( $report['storage'] ) ) {
			update_post_meta( $post_id, self::META_STORAGE, wp_json_encode( $report['storage'] ) );
		}
		update_post_meta( $post_id, self::META_REPORTER, (int) ( $report['reporter'] ?? 0 ) );
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_OPEN );
		if ( ! empty( $report['extra'] ) ) {
			update_post_meta( $post_id, self::META_EXTRA, wp_json_encode( $report['extra'] ) );
		}
		if ( ! empty( $report['screenshot'] ) ) {
			update_post_meta( $post_id, self::META_SCREENSHOT, (string) $report['screenshot'] );
		}

		return (int) $post_id;
	}

	/**
	 * Reads a stored report back into the shape `Markdown::render` wants.
	 *
	 * @return array<string, mixed>
	 */
	public static function to_report( \WP_Post $post ): array {
		return array(
			'type'        => (string) get_post_meta( $post->ID, self::META_TYPE, true ),
			'message'     => $post->post_content,
			'contact'     => (string) get_post_meta( $post->ID, self::META_CONTACT, true ),
			'context'     => self::json_meta( $post->ID, self::META_CONTEXT ),
			'console'     => self::json_meta( $post->ID, self::META_CONSOLE ),
			'elements'    => self::json_meta( $post->ID, self::META_ELEMENTS ),
			'breadcrumbs' => self::json_meta( $post->ID, self::META_BREADCRUMBS ),
			'network'     => self::json_meta( $post->ID, self::META_NETWORK ),
			'perf'        => self::json_meta( $post->ID, self::META_PERF ),
			'storage'     => self::json_meta( $post->ID, self::META_STORAGE ),
			'extra'       => self::json_meta( $post->ID, self::META_EXTRA ),
			'reporter'    => (int) get_post_meta( $post->ID, self::META_REPORTER, true ),
			'screenshot'  => (string) get_post_meta( $post->ID, self::META_SCREENSHOT, true ),
			'status'      => self::status( $post->ID ),
		);
	}

	public static function status( int $post_id ): string {
		$status = get_post_meta( $post_id, self::META_STATUS, true );
		return self::STATUS_DONE === $status ? self::STATUS_DONE : self::STATUS_OPEN;
	}

	/**
	 * @return array<mixed>
	 */
	private static function json_meta( int $post_id, string $key ): array {
		$raw = get_post_meta( $post_id, $key, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
