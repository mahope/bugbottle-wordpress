<?php
/**
 * The signature as the REST route actually sees it: a `WP_REST_Request` with a
 * body and a header, dispatched through `rest_do_request`, against real
 * settings and real transients.
 *
 * This is the half `tests/test-signature.php` cannot reach — that the raw
 * bytes survive to `WP_REST_Request::get_body()` (they do: `WP_REST_Server`
 * fills the body from `php://input` before it parses anything, and
 * `rest_do_request` uses whatever body was set), that the failure is a 401
 * saying `Bad signature` and nothing else, and that the transient refuses a
 * second delivery of a digest already accepted.
 *
 * It writes the `bugbottle_settings` option and creates reports, so run it
 * against a scratch install, never a live site. The option is restored at the
 * end; the reports it stores are left for you to look at.
 *
 * Usage, from a WordPress install with the plugin active:
 *
 *   wp eval-file /path/to/bugbottle-wordpress/tests/test-rest-signature.php
 *
 * @package Bugbottle
 */

// No `declare( strict_types=1 )`: `wp eval-file` runs this through `eval`,
// where a declare is not the first statement of the script and is a fatal
// error. The plugin's own files declare it; this runner cannot.

use Bugbottle\Rest;
use Bugbottle\Settings;
use Bugbottle\Signature;

if ( ! class_exists( Signature::class ) ) {
	echo "The Bugbottle plugin is not active in this install.\n";
	exit( 1 );
}

/**
 * Counts one check and says how it went. Called with no arguments it returns
 * the tally instead.
 *
 * The tally lives in statics rather than in globals: `wp eval-file` runs this
 * file inside a function of its own, so the top level here is not global scope
 * and a `global $failures` in the checker would silently count nothing — which
 * is exactly what a green "0 checks, 0 failed" looked like the first time.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual   Actual value.
 * @return array{total: int, failures: int}
 */
function bb_check( string $name = '', $expected = null, $actual = null ): array {
	static $total    = 0;
	static $failures = 0;

	if ( '' !== $name ) {
		++$total;
		if ( $expected === $actual ) {
			echo "pass  $name\n";
		} else {
			++$failures;
			echo "FAIL  $name\n";
			echo '        expected: ' . var_export( $expected, true ) . "\n";
			echo '        actual:   ' . var_export( $actual, true ) . "\n";
		}
	}

	return array(
		'total'    => $total,
		'failures' => $failures,
	);
}

/** Sets the signing keys, one per line, and clears the merged read cache. */
function bb_set_keys( string $keys ): void {
	$settings                 = Settings::all();
	$settings['signing_keys'] = $keys;
	update_option( Settings::OPTION, $settings );
}

/**
 * Dispatches one report and returns [status, body].
 *
 * The body is set as a string, exactly as a browser would have sent it, and
 * never re-encoded: that is the whole point of the header.
 *
 * @return array{0: int, 1: mixed}
 */
function bb_post( string $raw, ?string $signature ): array {
	$request = new WP_REST_Request( 'POST', '/' . Rest::NAMESPACE . '/report' );
	$request->set_header( 'Content-Type', 'application/json' );
	if ( null !== $signature ) {
		$request->set_header( Signature::HEADER, $signature );
	}
	$request->set_body( $raw );
	$response = rest_do_request( $request );
	return array( $response->get_status(), $response->get_data() );
}

/** The signature the client would have produced for this body right now. */
function bb_sign( string $key, string $raw, ?int $t = null ): string {
	$t = (string) ( $t ?? Signature::now_ms() );
	return 't=' . $t . ',v1=' . Signature::digest( $key, $t, $raw );
}

$bb_original = get_option( Settings::OPTION );
$bb_key      = 'sk_test_' . wp_generate_password( 24, false );
$bb_key2     = 'sk_rotated_' . wp_generate_password( 24, false );

// Signatures are the only thing under test, so the run is made as an
// administrator: the permission callback then returns true the moment the
// signature check has passed, and the anonymous rate limit — ten an hour, which
// a run of this file would otherwise eat through and then fail against with a
// 429 — never enters into it.
$bb_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
if ( array() === $bb_admins ) {
	echo "This install has no administrator to run as.\n";
	exit( 1 );
}
wp_set_current_user( (int) $bb_admins[0] );

// --- no key configured -------------------------------------------------

bb_set_keys( '' );
$bb_body   = wp_json_encode( array( 'type' => 'bug', 'message' => 'Unsigned, and no key is set' ) );
$bb_result = bb_post( (string) $bb_body, null );
bb_check( 'an unsigned report is accepted when no key is set', 201, $bb_result[0] );

// --- a key configured --------------------------------------------------

bb_set_keys( $bb_key );

$bb_body   = wp_json_encode( array( 'type' => 'bug', 'message' => 'Signed with the configured key' ) );
$bb_body   = (string) $bb_body;
$bb_sig    = bb_sign( $bb_key, $bb_body );
$bb_result = bb_post( $bb_body, $bb_sig );
bb_check( 'a valid signature is accepted', 201, $bb_result[0] );

// The same bytes and the same header again: the digest has been seen.
$bb_result = bb_post( $bb_body, $bb_sig );
bb_check( 'a replay of the same digest is refused', 401, $bb_result[0] );

$bb_other  = (string) wp_json_encode( array( 'type' => 'bug', 'message' => 'Tampered after signing' ) );
$bb_result = bb_post( $bb_other, bb_sign( $bb_key, $bb_body ) );
bb_check( 'a body that changed after signing is refused', 401, $bb_result[0] );

$bb_result = bb_post( $bb_body, bb_sign( $bb_key, $bb_body, Signature::now_ms() - Signature::SKEW_MS - 1000 ) );
bb_check( 'a signature older than the window is refused', 401, $bb_result[0] );

$bb_result = bb_post( $bb_body, bb_sign( $bb_key, $bb_body, Signature::now_ms() + Signature::SKEW_MS + 1000 ) );
bb_check( 'a signature further ahead than the window is refused', 401, $bb_result[0] );

$bb_result = bb_post( $bb_body, null );
bb_check( 'a missing signature is refused when a key is set', 401, $bb_result[0] );

// Every refusal says the same thing.
$bb_error = $bb_result[1];
bb_check( 'the refusal names no more than "Bad signature"', 'Bad signature', is_array( $bb_error ) && isset( $bb_error['data']['error'] ) ? $bb_error['data']['error'] : null );
bb_check( 'the refusal carries the plugin error code', 'bugbottle_bad_signature', is_array( $bb_error ) && isset( $bb_error['code'] ) ? $bb_error['code'] : null );

// --- key rotation ------------------------------------------------------

bb_set_keys( $bb_key . "\n" . $bb_key2 );

$bb_body   = (string) wp_json_encode( array( 'type' => 'bug', 'message' => 'Signed with the second key' ) );
$bb_result = bb_post( $bb_body, bb_sign( $bb_key2, $bb_body ) );
bb_check( 'the second key in the rotation is accepted', 201, $bb_result[0] );

$bb_body   = (string) wp_json_encode( array( 'type' => 'bug', 'message' => 'Signed with the first key' ) );
$bb_result = bb_post( $bb_body, bb_sign( $bb_key, $bb_body ) );
bb_check( 'the first key still works while both are listed', 201, $bb_result[0] );

bb_set_keys( $bb_key2 );
$bb_body   = (string) wp_json_encode( array( 'type' => 'bug', 'message' => 'Signed with a key that was retired' ) );
$bb_result = bb_post( $bb_body, bb_sign( $bb_key, $bb_body ) );
bb_check( 'a key removed from the list stops working', 401, $bb_result[0] );

// --- the raw body reaches the verifier ---------------------------------

// Whitespace that `json_decode`/`json_encode` would flatten. If verification
// were done over the parsed body this is the case that would fail.
bb_set_keys( $bb_key );
$bb_spaced = "{ \"type\" : \"bug\" ,\n  \"message\" : \"Formatting nobody would re-emit\" }";
$bb_result = bb_post( $bb_spaced, bb_sign( $bb_key, $bb_spaced ) );
bb_check( 'the digest is taken over the bytes, not over a re-encoding', 201, $bb_result[0] );

$bb_reencoded = (string) wp_json_encode( json_decode( $bb_spaced, true ) );
bb_check( 'and those bytes really do differ from a re-encoding', true, $bb_spaced !== $bb_reencoded );

// -----------------------------------------------------------------------

update_option( Settings::OPTION, is_array( $bb_original ) ? $bb_original : Settings::defaults() );

$bb_tally = bb_check();
echo "\n{$bb_tally['total']} checks, {$bb_tally['failures']} failed\n";
if ( $bb_tally['failures'] > 0 ) {
	exit( 1 );
}
