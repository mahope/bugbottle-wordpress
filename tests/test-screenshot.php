<?php
/**
 * The screenshot path, without a WordPress install.
 *
 * Two things are under test. `Validator::decode_screenshot()` is the port of
 * the library's own check, and the part worth pinning is that it reads the PNG
 * signature out of the **decoded bytes** rather than trusting the `data:`
 * prefix — a JPEG or an HTML login page wearing a PNG label is exactly what
 * arrives when a renderer fails and something else answers instead. And
 * `Settings` is checked for the one fact a site owner is entitled to: the
 * Screenshots setting is off until somebody ticks it, and a value that was
 * never posted stays off.
 *
 * Usage: php tests/test-screenshot.php
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

// Settings reaches for these five; nothing here exercises what they do, only
// that a setting survives the sanitiser with the right value.
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param mixed $default_value Value returned for any option.
	 * @return mixed
	 */
	function get_option( string $option, $default_value = false ) {
		return $default_value;
	}
	function sanitize_hex_color( string $colour ): ?string {
		return 1 === preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $colour ) ? $colour : null;
	}
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
	function esc_url_raw( string $url ): string {
		return $url;
	}
	function is_email( string $value ): bool {
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
	}
	function sanitize_email( string $value ): string {
		return $value;
	}
}

require_once __DIR__ . '/../includes/class-validator.php';
require_once __DIR__ . '/../includes/class-settings.php';

use Bugbottle\Invalid_Screenshot_Error;
use Bugbottle\Settings;
use Bugbottle\Validator;

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

/** The reason a decode was refused, or 'accepted' with the byte count. */
function decode( string $data_url ): string {
	try {
		return 'accepted ' . strlen( Validator::decode_screenshot( $data_url ) );
	} catch ( Invalid_Screenshot_Error $e ) {
		return $e->getMessage();
	}
}

/** A real 1x1 PNG — the eight signature bytes, IHDR, IDAT and IEND. */
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

$png_bytes = base64_decode( PNG_1X1, true );
check( 'the fixture really is a PNG', "\x89PNG\r\n\x1a\n", substr( (string) $png_bytes, 0, 8 ) );

check(
	'a PNG data URL decodes to its bytes',
	'accepted ' . strlen( (string) $png_bytes ),
	decode( 'data:image/png;base64,' . PNG_1X1 )
);

// The whole point of decoding before checking: the label is the attacker's to
// write, the first eight bytes are not.
check(
	'a JPEG wearing a PNG label is refused',
	'Screenshot is not a valid PNG',
	decode( 'data:image/png;base64,' . base64_encode( "\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01" ) )
);

check(
	'an HTML page returned in place of a picture is refused',
	'Screenshot is not a valid PNG',
	decode( 'data:image/png;base64,' . base64_encode( '<!doctype html><title>Sign in</title>' ) )
);

// A buffer shorter than the signature would otherwise match on a prefix.
check(
	'a buffer shorter than the signature is refused',
	'Screenshot is not a valid PNG',
	decode( 'data:image/png;base64,' . base64_encode( "\x89PNG" ) )
);

check(
	'an empty payload is refused',
	'Screenshot is not a valid PNG',
	decode( 'data:image/png;base64,' )
);

check(
	'a JPEG data URL is refused before it is decoded',
	'Screenshot must be a PNG data URL',
	decode( 'data:image/jpeg;base64,' . PNG_1X1 )
);

check(
	'a plain URL is not a data URL',
	'Screenshot must be a PNG data URL',
	decode( 'https://example.test/shot.png' )
);

check(
	'something that is not a string at all is refused',
	'Screenshot must be a PNG data URL',
	( static function (): string {
		try {
			Validator::decode_screenshot( array( 'data:image/png;base64,' . PNG_1X1 ) );
			return 'accepted';
		} catch ( Invalid_Screenshot_Error $e ) {
			return $e->getMessage();
		}
	} )()
);

check(
	'base64 that is not base64 is refused',
	'Screenshot is not valid base64',
	decode( 'data:image/png;base64,not base64 at all!!' )
);

check(
	'a data URL past the ceiling is refused before it is decoded',
	'Screenshot is too large',
	decode( 'data:image/png;base64,' . str_repeat( 'A', 12 * 1024 * 1024 ) )
);

// The setting itself. Off by default is not a nicety here: a picture of the
// page is the one thing in a report that can carry somebody else's data.
check( 'screenshots are off by default', false, Settings::defaults()['screenshot'] );

check( 'an empty form leaves screenshots off', false, Settings::sanitize( array() )['screenshot'] );

check( 'a ticked box turns them on', true, Settings::sanitize( array( 'screenshot' => '1' ) )['screenshot'] );

check(
	'an unticked box turns them off again',
	false,
	Settings::sanitize( array( 'enabled' => '1' ) )['screenshot']
);

echo "\n$total checks, $failures failed\n";
exit( $failures > 0 ? 1 : 0 );
