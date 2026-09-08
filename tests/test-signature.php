<?php
/**
 * The signature rules, without a WordPress install.
 *
 * `Bugbottle\Signature` keeps everything but `check()` free of WordPress on
 * purpose, so the arithmetic that decides whether a report is accepted can be
 * exercised here in a second rather than only inside a site. The REST flow
 * itself — the raw body, the 401 body, the transient that refuses a replay —
 * is `tests/test-rest-signature.php`, which runs under `wp eval-file`.
 *
 * Usage: php tests/test-signature.php
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../includes/class-signature.php';

use Bugbottle\Signature;

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

/** A replay memory that lives for the length of one test. */
function claimer(): callable {
	$seen = array();
	return static function ( string $digest ) use ( &$seen ): bool {
		if ( isset( $seen[ $digest ] ) ) {
			return false;
		}
		$seen[ $digest ] = true;
		return true;
	};
}

$key   = 'sk_test_bugbottle_0123456789';
$key2  = 'sk_test_rotated_9876543210';
$body  = '{"type":"bug","message":"The save button does nothing"}';
$now   = 1757260800000;
$t     = (string) $now;
$v1    = hash_hmac( 'sha256', $t . '.' . $body, $key );
$valid = "t=$t,v1=$v1";

// The vector both sides have to agree on, byte for byte. The library computes
// its digest through WebCrypto in the browser and through Node's `crypto` on
// the server; this is the same input through PHP's `hash_hmac`. Reproduce it
// with:
//
//   node -e 'const c=require("crypto");console.log(c.createHmac("sha256",
//     "bugbottle-test-key").update("1757260800000."+JSON.stringify({a:1}))
//     .digest("hex"))'
check(
	'the known vector agrees with Node',
	'f358148d3bf2ba334ce21bbafb5c3cc088dbd007e9773682e9a8fdc9f2ccaf07',
	Signature::digest( 'bugbottle-test-key', '1757260800000', '{"a":1}' )
);

check( 'a valid signature is accepted', true, Signature::verify( $body, $valid, array( $key ), $now, claimer() ) );

check(
	'a tampered body is refused',
	false,
	Signature::verify( $body . ' ', $valid, array( $key ), $now, claimer() )
);

check(
	'a tampered digest is refused',
	false,
	Signature::verify( $body, 't=' . $t . ',v1=' . str_repeat( 'a', 64 ), array( $key ), $now, claimer() )
);

check(
	'a timestamp older than the window is refused',
	false,
	Signature::verify( $body, $valid, array( $key ), $now + Signature::SKEW_MS + 1, claimer() )
);

check(
	'a timestamp newer than the window is refused, the same way',
	false,
	Signature::verify( $body, $valid, array( $key ), $now - Signature::SKEW_MS - 1, claimer() )
);

check(
	'the edge of the window is still inside it',
	true,
	Signature::verify( $body, $valid, array( $key ), $now + Signature::SKEW_MS, claimer() )
);

$replay = claimer();
check( 'the first delivery is accepted', true, Signature::verify( $body, $valid, array( $key ), $now, $replay ) );
check( 'the same digest again is refused', false, Signature::verify( $body, $valid, array( $key ), $now, $replay ) );

$t2      = (string) ( $now + 1000 );
$second  = "t=$t2,v1=" . hash_hmac( 'sha256', $t2 . '.' . $body, $key2 );
check(
	'the second key in the rotation is accepted',
	true,
	Signature::verify( $body, $second, array( $key, $key2 ), $now, claimer() )
);
check(
	'a key that has been rotated out is refused',
	false,
	Signature::verify( $body, $second, array( $key ), $now, claimer() )
);

check( 'a missing header is refused', false, Signature::verify( $body, '', array( $key ), $now, claimer() ) );
check( 'a header without v1 is refused', false, Signature::verify( $body, "t=$t", array( $key ), $now, claimer() ) );
check( 'a header without t is refused', false, Signature::verify( $body, "v1=$v1", array( $key ), $now, claimer() ) );
check( 'a v1 of the wrong length is refused', false, Signature::verify( $body, "t=$t,v1=abc", array( $key ), $now, claimer() ) );
check( 'uppercase hex is refused', false, Signature::verify( $body, 't=' . $t . ',v1=' . strtoupper( $v1 ), array( $key ), $now, claimer() ) );
check( 'a non-numeric t is refused', false, Signature::verify( $body, "t=now,v1=$v1", array( $key ), $now, claimer() ) );

check(
	'nothing verifies when no key is configured',
	false,
	Signature::verify( $body, $valid, array(), $now, claimer() )
);

check( 'spacing around the parts is tolerated', true, Signature::verify( $body, " t = $t , v1 = $v1 ", array( $key ), $now, claimer() ) );

check(
	'the keys are parsed one per line, blanks and duplicates dropped',
	array( 'one', 'two' ),
	Signature::parse_keys( "  one \n\n two\r\none\n  \n" )
);
check( 'an empty setting is no keys at all', array(), Signature::parse_keys( '' ) );

echo "\n$total checks, $failures failed\n";
exit( $failures > 0 ? 1 : 0 );
