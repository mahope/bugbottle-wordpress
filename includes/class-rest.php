<?php
/**
 * The two routes: one that receives a report, one that hands a screenshot back
 * to somebody allowed to see it.
 *
 * `POST /bugbottle/v1/report` takes anything a browser cares to send. A
 * logged-in reporter is identified by the ordinary cookie plus `X-WP-Nonce`,
 * which WordPress checks before this code runs. An anonymous reporter is only
 * accepted when the site says so, and then no more than ten times an hour from
 * one address — enough for a real person filing several reports in a sitting,
 * not enough to be worth scripting.
 *
 * `GET /bugbottle/v1/screenshot/<id>` is the only way a screenshot comes back
 * out. The file name is read from the row, never from the request.
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

namespace Bugbottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes for reports and screenshots.
 */
final class Rest {

	public const NAMESPACE = 'bugbottle/v1';

	/** Anonymous reports per IP address per window. */
	private const RATE_LIMIT = 10;

	private const RATE_WINDOW = HOUR_IN_SECONDS;

	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'receive_report' ),
				'permission_callback' => array( self::class, 'may_report' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/screenshot/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'serve_screenshot' ),
				'permission_callback' => array( self::class, 'may_read_screenshot' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * A logged-in user with a valid nonce, or an anonymous visitor when the
	 * site allows it and has not used up its allowance.
	 *
	 * WordPress has already rejected a cookie with a bad nonce by this point
	 * (`rest_cookie_check_errors`), so "logged in" here means "logged in and
	 * the nonce checked out".
	 *
	 * @return true|\WP_Error
	 */
	public static function may_report() {
		if ( is_user_logged_in() ) {
			return true;
		}
		if ( Settings::bool( 'logged_in_only' ) || ! Settings::bool( 'allow_anonymous' ) ) {
			return new \WP_Error(
				'bugbottle_forbidden',
				__( 'You must be logged in to send a report.', 'bugbottle' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		if ( ! self::within_rate_limit() ) {
			return new \WP_Error(
				'bugbottle_rate_limited',
				__( 'Too many reports from this address. Try again later.', 'bugbottle' ),
				array( 'status' => 429 )
			);
		}
		return true;
	}

	/**
	 * Counts anonymous submissions per address in a transient. A transient is
	 * the right amount of machinery here: it expires by itself, it survives a
	 * page load, and losing it to a cache flush costs nothing worse than a few
	 * extra reports.
	 */
	private static function within_rate_limit(): bool {
		$key   = 'bugbottle_rl_' . md5( self::client_ip() );
		$count = get_transient( $key );
		$count = is_numeric( $count ) ? (int) $count : 0;
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}

	/**
	 * The address as the web server saw it. Proxy headers are deliberately not
	 * consulted: they are trivially forged, and a forged header would make the
	 * rate limit meaningless.
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}

	/**
	 * Validates and stores a report.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function receive_report( \WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		if ( ! Validator::is_report_type( $body['type'] ?? null ) ) {
			return new \WP_Error(
				'bugbottle_invalid_type',
				__( 'Unknown report type.', 'bugbottle' ),
				array( 'status' => 400 )
			);
		}
		$message = Validator::message( $body['message'] ?? null );
		if ( null === $message ) {
			return new \WP_Error(
				'bugbottle_empty_message',
				__( 'A report needs a message.', 'bugbottle' ),
				array( 'status' => 400 )
			);
		}

		// A screenshot fails open: a picture we will not accept costs the
		// picture, not the report.
		$screenshot = null;
		if ( ! empty( $body['screenshotDataUrl'] ) ) {
			try {
				$screenshot = Storage::store_screenshot( Validator::decode_screenshot( $body['screenshotDataUrl'] ) );
			} catch ( Invalid_Screenshot_Error $e ) {
				$screenshot = null;
			}
		}

		$report = array(
			'type'        => (string) $body['type'],
			'message'     => $message,
			'context'     => Validator::context( $body['context'] ?? null ),
			'console'     => Validator::console( $body['console'] ?? null ),
			'elements'    => Validator::elements( $body['elements'] ?? null ),
			'breadcrumbs' => Validator::breadcrumbs( $body['breadcrumbs'] ?? null ),
			'extra'       => self::extra( $body ),
			'reporter'    => get_current_user_id(),
			'screenshot'  => $screenshot,
		);

		$post_id = Storage::insert( $report );
		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error(
				'bugbottle_not_stored',
				__( 'The report could not be stored.', 'bugbottle' ),
				array( 'status' => 500 )
			);
		}

		if ( Settings::bool( 'email_on_submit' ) ) {
			Email::send_report( $post_id );
		}

		/**
		 * Fires after a report has been stored.
		 *
		 * @param int                  $post_id The new report.
		 * @param array<string, mixed> $report  The validated report.
		 */
		do_action( 'bugbottle_report_stored', $post_id, $report );

		return new \WP_REST_Response( array( 'id' => (string) $post_id ), 201 );
	}

	/**
	 * Whatever the client added to the report beyond the known fields — an app
	 * version, a tenant id. Clipped so it cannot be used as free storage.
	 *
	 * @param array<string, mixed> $body The request body.
	 * @return array<string, string>
	 */
	private static function extra( array $body ): array {
		$known = array( 'type', 'message', 'context', 'console', 'elements', 'breadcrumbs', 'screenshotDataUrl' );
		$out   = array();
		foreach ( $body as $key => $value ) {
			if ( count( $out ) >= 20 ) {
				break;
			}
			if ( ! is_string( $key ) || in_array( $key, $known, true ) ) {
				continue;
			}
			// `sanitize_key` would lower-case the name, and `appVersion` reads
			// better in the admin than `appversion`.
			$name = mb_substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $key ), 0, 64 );
			if ( '' !== $name && is_scalar( $value ) ) {
				$out[ $name ] = mb_substr( str_replace( "\0", '', (string) $value ), 0, 200 );
			}
		}
		return $out;
	}

	/**
	 * Screenshots are for the people who administer the site. Nobody else.
	 */
	public static function may_read_screenshot(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Streams the PNG. `id` is a post id; the file name comes from that row.
	 *
	 * @return \WP_Error|never
	 */
	public static function serve_screenshot( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || Storage::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'bugbottle_not_found', __( 'No such report.', 'bugbottle' ), array( 'status' => 404 ) );
		}
		$path = Storage::screenshot_path( $post_id );
		if ( null === $path ) {
			return new \WP_Error( 'bugbottle_no_screenshot', __( 'That report has no screenshot.', 'bugbottle' ), array( 'status' => 404 ) );
		}

		header( 'Content-Type: image/png' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'Content-Disposition: inline; filename="bugbottle-' . $post_id . '.png"' );
		header( 'Cache-Control: private, no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** The URL the admin screens link a screenshot at. */
	public static function screenshot_url( int $post_id ): string {
		return rest_url( self::NAMESPACE . '/screenshot/' . $post_id );
	}
}
