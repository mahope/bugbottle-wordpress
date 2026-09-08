<?php
/**
 * The notification email's body, without a WordPress install.
 *
 * The one fact under test is the one that was wrong until issue #5: the email
 * must carry no link to the screenshot route. That route wants
 * `manage_options` through a cookie session plus a REST nonce, neither of
 * which an inbox has, so a raw URL in an email answered 401 for every
 * recipient. What is left is the "In admin" link, which has always worked, and
 * which must resolve to the report's own detail screen rather than to the list.
 *
 * Since 0.5.0 the same file also pins the `Reply-To`: a report whose contact
 * line looks like an email address is sent with it as the reply address, and
 * one whose contact line is a phone number is not, because Reply-To takes an
 * address or nothing.
 *
 * Usage: php tests/test-email.php
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

/** The post row and its meta, rewritten per case by `send()` below. */
$GLOBALS['bb_meta'] = array();
/** What `wp_mail` was handed, or null when it was never called. */
$GLOBALS['bb_mail'] = null;

/**
 * WordPress is not loaded, so `WP_Post` is only the three fields `Storage`
 * and `Email` read off it.
 */
class WP_Post {

	public int $ID           = 0;
	public string $post_type = 'bugbottle_report';
	public string $post_content = '';
}

/**
 * @param mixed $default_value Value returned for any option.
 * @return mixed
 */
function get_option( string $option, $default_value = false ) {
	return array( 'email_recipient' => 'dev@example.test' );
}
function is_email( string $value ): bool {
	return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
}
function get_post( int $post_id ): ?WP_Post {
	$post               = new WP_Post();
	$post->ID           = $post_id;
	$post->post_content = 'The save button does nothing';
	return $post;
}
/**
 * @return mixed
 */
function get_post_meta( int $post_id, string $key = '', bool $single = false ) {
	return $GLOBALS['bb_meta'][ $key ] ?? '';
}
function get_bloginfo( string $show = '' ): string {
	return 'Example Site';
}
function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . $path;
}
/**
 * @return false
 */
function get_userdata( int $user_id ) {
	return false;
}
function __( string $text, string $domain = 'default' ): string {
	return $text;
}
/**
 * @param string|string[] $to Recipient.
 */
function wp_mail( $to, string $subject, string $message, $headers = array() ): bool {
	$GLOBALS['bb_mail'] = array(
		'to'      => $to,
		'subject' => $subject,
		'body'    => $message,
		'headers' => $headers,
	);
	return true;
}

require_once __DIR__ . '/../includes/class-validator.php';
require_once __DIR__ . '/../includes/class-markdown.php';
require_once __DIR__ . '/../includes/class-settings.php';
require_once __DIR__ . '/../includes/class-storage.php';
require_once __DIR__ . '/../includes/class-email.php';

use Bugbottle\Email;
use Bugbottle\Storage;

$failures = 0;
$total    = 0;

/**
 * @param mixed $expected Expected value.
 * @param mixed $actual   Actual value.
 */
function check( string $name, $expected, $actual ): void {
	global $failures, $total;
	++$total;
	if ( $expected === $actual ) {
		echo "pass  $name\n";
		return;
	}
	++$failures;
	echo "FAIL  $name\n";
	echo '        expected: ' . var_export( $expected, true ) . "\n";
	echo '        actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * Sends report 42, with or without a stored screenshot and with whatever
 * contact line the case wants, and returns the body.
 */
function send( bool $with_screenshot, string $contact = '' ): string {
	$GLOBALS['bb_meta'] = array(
		Storage::META_TYPE       => 'bug',
		Storage::META_CONTEXT    => '{"url":"/checkout","viewport":"1280x720","userAgent":"Mozilla/5.0"}',
		Storage::META_REPORTER   => '0',
		Storage::META_CONTACT    => $contact,
		Storage::META_SCREENSHOT => $with_screenshot ? 'a3f9c1d2e4b5.png' : '',
	);
	$GLOBALS['bb_mail'] = null;
	Email::send_report( 42 );
	$mail = $GLOBALS['bb_mail'];
	return is_array( $mail ) ? (string) $mail['body'] : '';
}

/** The headers the last `send()` produced. */
function sent_headers(): array {
	$mail = $GLOBALS['bb_mail'];
	return is_array( $mail ) && is_array( $mail['headers'] ) ? $mail['headers'] : array();
}

$with = send( true );

// The link that always answered 401. The route is `/screenshot/<id>` under the
// plugin's REST namespace; neither half of it may appear anywhere in the body.
check( 'the body carries no screenshot route', false, str_contains( $with, '/screenshot/' ) );
check( 'the body carries no REST URL at all', false, str_contains( $with, 'wp-json' ) );
check( 'the body carries no nonce', false, str_contains( $with, '_wpnonce' ) );

// What replaced it: the picture is named, but only as something to open in
// wp-admin behind the capability that guards it.
check(
	'a stored screenshot is named without a link',
	true,
	str_contains( $with, '| Screenshot | attached, in admin |' )
);

// The link that has always worked, and the reason the raw one is not missed.
check(
	'the admin link resolves to this report\'s detail screen',
	true,
	str_contains( $with, '| In admin | https://example.test/wp-admin/admin.php?page=bugbottle&report=42 |' )
);

$without = send( false );

check( 'a report without a picture says nothing about one', false, str_contains( $without, 'Screenshot' ) );
check( 'and still links the detail screen', true, str_contains( $without, 'page=bugbottle&report=42' ) );
check( 'and still carries no REST URL', false, str_contains( $without, 'wp-json' ) );

// The rest of the email is unchanged: it is the Markdown rendering, and the
// message is the part that matters.
check( 'the message survives', true, str_contains( $without, 'The save button does nothing' ) );
check( 'the site is a fact', true, str_contains( $without, '| Site | Example Site |' ) );
check( 'an anonymous reporter is named as one', true, str_contains( $without, '| Reporter | Not logged in |' ) );

$mail = $GLOBALS['bb_mail'];
check(
	'the subject carries the site and the title',
	'[Example Site] Bug: The save button does nothing',
	is_array( $mail ) ? (string) $mail['subject'] : ''
);
check( 'and it went to the configured recipient', 'dev@example.test', is_array( $mail ) ? $mail['to'] : '' );

// The contact line, and the Reply-To that follows from it. A reply to the
// notification should reach the person who wrote the report — but only when
// what they typed is plausibly an address, because a malformed Reply-To is a
// broken header and the line is in the body either way.
$reachable = send( false, '  anna@example.test  ' );
check( 'the contact line is a fact in the body', true, str_contains( $reachable, '| Contact | anna@example.test |' ) );
check( 'and it is the row under the type', true, str_contains( $reachable, "| Type | Bug |\n| Contact | anna@example.test |" ) );
check( 'an address becomes the Reply-To', array( 'Reply-To: anna@example.test' ), sent_headers() );

$phone = send( false, 'ring me on 12345678' );
check( 'a phone number is still a fact in the body', true, str_contains( $phone, '| Contact | ring me on 12345678 |' ) );
check( 'but it is never a Reply-To', array(), sent_headers() );

$none = send( false );
check( 'no contact line, no Contact row', false, str_contains( $none, '| Contact |' ) );
check( 'no contact line, no Reply-To', array(), sent_headers() );

echo "\n$total checks, $failures failed\n";
exit( $failures > 0 ? 1 : 0 );
