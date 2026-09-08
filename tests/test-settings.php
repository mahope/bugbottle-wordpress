<?php
/**
 * The settings sanitiser, which is the only place settings are written.
 *
 * The one under most pressure is `Language`. Its value is handed to the panel
 * as `locale` and reaches `resolveLocale()` in the browser, and bugbottle
 * 0.13.0 fixed a crash there: a tag of `__proto__` or `constructor` used to
 * find an inherited property of the messages object rather than a language, so
 * the panel threw at mount. The library no longer looks at prototype names, and
 * this plugin never could send one — the setting is an allow-list — but a
 * `<select>` is only a suggestion to whoever is posting the form, so the rule
 * is a test rather than a property somebody has to notice.
 *
 * A handful of WordPress functions are stubbed rather than loaded: the
 * sanitiser touches five of them, all of them string work, and stubbing them
 * keeps this runnable with plain `php` the way the parity test is. Nothing
 * here reads or writes the database.
 *
 * Usage: php tests/test-settings.php
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

/**
 * @param string $color A colour from the form.
 */
function sanitize_hex_color( string $color ): ?string {
	return 1 === preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ? $color : null;
}

/**
 * @param string $str A string from the form.
 */
function sanitize_text_field( string $str ): string {
	return trim( (string) preg_replace( '/[\r\n\t\0\x0B]+/', '', wp_strip_all_tags( $str ) ) );
}

/**
 * @param string $str A string from the form.
 */
function wp_strip_all_tags( string $str ): string {
	return (string) preg_replace( '/<[^>]*>/', '', $str );
}

/**
 * @param string $url A URL from the form.
 */
function esc_url_raw( string $url ): string {
	$url = trim( $url );
	if ( '' === $url ) {
		return '';
	}
	return 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
}

/**
 * @param string $email An address from the form.
 */
function is_email( string $email ): bool {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}

/**
 * @param string $email An address from the form.
 */
function sanitize_email( string $email ): string {
	return trim( $email );
}

require_once __DIR__ . '/../includes/class-settings.php';

use Bugbottle\Settings;

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
 * The saved `locale` for a posted one.
 *
 * @param mixed $value Whatever was posted as the Language field.
 */
function saved_locale( $value ): string {
	return (string) Settings::sanitize( array( 'locale' => $value ) )['locale'];
}

// The eight languages the library speaks in the script tag, plus `auto`.
foreach ( Settings::LOCALES as $code ) {
	check( "the language \"$code\" is saved as itself", $code, saved_locale( $code ) );
}

// The prototype names. `in_array( $value, self::LOCALES, true )` is what stops
// them, so this is a check that the allow-list is an allow-list and stays one.
check( 'a language of __proto__ falls back to auto', 'auto', saved_locale( '__proto__' ) );
check( 'a language of constructor falls back to auto', 'auto', saved_locale( 'constructor' ) );
check( 'a language of prototype falls back to auto', 'auto', saved_locale( 'prototype' ) );
check( 'a language of toString falls back to auto', 'auto', saved_locale( 'toString' ) );
check( 'a language of hasOwnProperty falls back to auto', 'auto', saved_locale( 'hasOwnProperty' ) );

// And everything else that is not one of the nine.
check( 'an unknown language falls back to auto', 'auto', saved_locale( 'kl' ) );
check( 'an empty language falls back to auto', 'auto', saved_locale( '' ) );
check( 'a missing language falls back to auto', 'auto', (string) Settings::sanitize( array() )['locale'] );
check( 'a language that is not a string falls back to auto', 'auto', saved_locale( 7 ) );
check( 'a language of the right shape but wrong case falls back to auto', 'auto', saved_locale( 'DA' ) );
check( 'whitespace around a language is not trimmed into a match', 'auto', saved_locale( ' da ' ) );

// The three other allow-lists on the screen, for the same reason.
check(
	'a position that is not one of the four falls back',
	'bottom-right',
	(string) Settings::sanitize( array( 'position' => '__proto__' ) )['position']
);
check(
	'a contact mode that is not one of the three falls back to off',
	Settings::CONTACT_OFF,
	(string) Settings::sanitize( array( 'contact' => 'constructor' ) )['contact']
);
check(
	'a colour that is not a hex colour falls back to the default',
	'#2563eb',
	(string) Settings::sanitize( array( 'primary_color' => 'javascript:alert(1)' ) )['primary_color']
);

// The shortcut is not an allow-list but a shape, and the shape is the defence:
// anything with a character outside `[a-z0-9+]` is a typo, and a typo restores
// the default rather than saving a combination nobody can press.
check(
	'a shortcut of __proto__ is not a shortcut',
	Settings::DEFAULT_SHORTCUT,
	(string) Settings::sanitize( array( 'shortcut' => '__proto__' ) )['shortcut']
);
check(
	'an empty shortcut means no shortcut',
	'',
	(string) Settings::sanitize( array( 'shortcut' => '' ) )['shortcut']
);
check(
	'a real shortcut survives',
	'mod+shift+b',
	(string) Settings::sanitize( array( 'shortcut' => ' MOD+Shift+B ' ) )['shortcut']
);

echo "\n$total checks, $failures failed\n";
exit( $failures > 0 ? 1 : 0 );
