<?php
/**
 * Sending a report on by email, through `wp_mail` so a site's existing SMTP
 * plugin handles delivery.
 *
 * The body is the Markdown rendering, as plain text. Markdown in a plain-text
 * email is a deliberate choice rather than an oversight: it reads perfectly
 * well as text, and it pastes straight into a GitHub issue, which is where
 * most of these end up.
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

namespace Bugbottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report notifications.
 */
final class Email {

	/**
	 * Emails one stored report to the configured recipient.
	 *
	 * @return bool False when there is no recipient, no such report, or
	 *              `wp_mail` refused it.
	 */
	public static function send_report( int $post_id ): bool {
		$recipient = Settings::string( 'email_recipient' );
		if ( '' === $recipient || ! is_email( $recipient ) ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || Storage::POST_TYPE !== $post->post_type ) {
			return false;
		}

		$report = Storage::to_report( $post );
		$type   = is_string( $report['type'] ) ? $report['type'] : 'other';

		$facts = array(
			'Site'     => get_bloginfo( 'name' ),
			'Reporter' => self::reporter_name( (int) $report['reporter'] ),
		);

		// The email deliberately carries no link to the picture itself. The
		// screenshot route requires `manage_options` through a cookie session
		// plus a REST nonce, so a raw URL sitting in an inbox answers 401 for
		// every recipient, signed in or not. The picture is one click behind
		// the admin link below, under the same capability that guards it.
		if ( '' !== $report['screenshot'] ) {
			$facts['Screenshot'] = 'attached, in admin';
		}
		$facts['In admin'] = admin_url( 'admin.php?page=bugbottle&report=' . $post_id );

		$body = Markdown::render(
			$report,
			array(
				'facts'            => $facts,
				'collapse_console' => false,
				'heading_level'    => 0,
			)
		);

		$subject = sprintf(
			/* translators: 1: site name, 2: report title */
			__( '[%1$s] %2$s', 'bugbottle' ),
			get_bloginfo( 'name' ),
			Markdown::title_for( $type, (string) $report['message'] )
		);

		return wp_mail( $recipient, $subject, $body );
	}

	/**
	 * A display name for the reporter, or "not logged in" when there was none.
	 */
	public static function reporter_name( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'Not logged in', 'bugbottle' );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			/* translators: %d: user id */
			return sprintf( __( 'User %d', 'bugbottle' ), $user_id );
		}
		return $user->display_name . ' <' . $user->user_email . '>';
	}
}
