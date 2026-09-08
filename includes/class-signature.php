<?php
/**
 * Verifying the optional `X-Bugbottle-Signature` header the library's client
 * can put on a report.
 *
 * The header is `t=<unix milliseconds>,v1=<hex>`, where the hex is
 * `HMAC-SHA-256(key, "<t>.<body>")` over the *raw* request body. Raw is the
 * whole point: `json_decode` followed by `json_encode` moves key order,
 * spacing and number formatting, and the digest moves with them, so a report
 * that was signed correctly would be refused. WordPress hands the REST
 * callback a parsed body, but it also keeps the bytes it parsed on the
 * request, so `WP_REST_Request::get_body()` is the raw string in this flow —
 * `WP_REST_Server::serve_request()` fills it from `php://input`. The fallback
 * below reads `php://input` itself for the case where something else built the
 * request without a body.
 *
 * A key that ships to the browser is public: it sits in the page source, so
 * anyone who wants it has it. This is spam deterrence next to the rate limit,
 * not authentication, and nothing here should ever be described as securing
 * the endpoint. The settings screen says the same thing beside the field.
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

namespace Bugbottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Parses and verifies the request signature.
 *
 * Everything except `check()` is pure: it takes the bytes, the header, the
 * keys and the current time, and returns an answer. That is what makes the
 * rules testable without a WordPress install, and it is why the replay memory
 * arrives as a callable rather than as a `get_transient` call in the middle of
 * the arithmetic.
 */
final class Signature {

	/** The header name the library uses by default. */
	public const HEADER = 'X-Bugbottle-Signature';

	/**
	 * How far apart the two clocks may be, in milliseconds. Five minutes, the
	 * library's default, and in *both* directions: a caller whose clock runs
	 * ahead of ours opens exactly the same replay window as one that runs
	 * behind, so refusing only the past would be theatre.
	 */
	public const SKEW_MS = 5 * 60 * 1000;

	/** Prefix for the transient that remembers an accepted digest. */
	private const SEEN_PREFIX = 'bugbottle_sig_';

	/**
	 * The configured keys, one per line in the setting, in the order they were
	 * written. More than one is how a key is rotated: publish the new key,
	 * keep the old one until the last cached page carrying it has expired,
	 * then delete the old one.
	 *
	 * @return list<string>
	 */
	public static function keys(): array {
		return self::parse_keys( Settings::string( 'signing_keys' ) );
	}

	/**
	 * Splits the textarea into keys. Blank lines and surrounding whitespace go;
	 * a key is otherwise taken exactly as written, because it is an opaque
	 * secret and we do not get to decide what characters belong in one.
	 *
	 * @return list<string>
	 */
	public static function parse_keys( string $raw ): array {
		$keys = array();
		foreach ( preg_split( '/\R/', $raw ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' !== $line && ! in_array( $line, $keys, true ) ) {
				$keys[] = $line;
			}
		}
		return $keys;
	}

	/** Whether this site is verifying signatures at all. */
	public static function is_configured(): bool {
		return array() !== self::keys();
	}

	/**
	 * Pulls `t` and `v1` out of the header. Returns null for anything that is
	 * not both of them, including a `v1` that is not 64 lowercase hex
	 * characters — a digest of the wrong shape can never match one of ours, and
	 * refusing it here keeps `hash_equals` from being handed rubbish.
	 *
	 * @return array{t: string, v1: string}|null
	 */
	public static function parse_header( string $header ): ?array {
		$t  = null;
		$v1 = null;
		foreach ( explode( ',', $header ) as $part ) {
			$pair  = array_pad( explode( '=', $part, 2 ), 2, null );
			$name  = trim( (string) $pair[0] );
			$value = trim( (string) $pair[1] );
			if ( 't' === $name ) {
				$t = $value;
			} elseif ( 'v1' === $name ) {
				$v1 = $value;
			}
		}
		if ( null === $t || null === $v1 ) {
			return null;
		}
		if ( 1 !== preg_match( '/^[0-9]{1,20}$/', $t ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $v1 ) ) {
			return null;
		}
		return array(
			't'  => $t,
			'v1' => $v1,
		);
	}

	/**
	 * Whether the timestamp is inside the window, in either direction.
	 */
	public static function is_fresh( string $t, int $now_ms ): bool {
		return abs( $now_ms - (int) $t ) <= self::SKEW_MS;
	}

	/**
	 * The digest the library would have produced for this body and timestamp
	 * under this key. Shared by the verification and by the tests, so both
	 * sides compute it the same way or neither does.
	 */
	public static function digest( string $key, string $t, string $raw_body ): string {
		return hash_hmac( 'sha256', $t . '.' . $raw_body, $key );
	}

	/**
	 * Whether any configured key produces the digest that arrived. Every key is
	 * tried even after one matches: bailing out early would leak, in the time
	 * taken, which key it was.
	 *
	 * @param list<string> $keys The configured keys.
	 */
	public static function matches( string $raw_body, string $t, string $v1, array $keys ): bool {
		$ok = false;
		foreach ( $keys as $key ) {
			if ( hash_equals( self::digest( $key, $t, $raw_body ), $v1 ) ) {
				$ok = true;
			}
		}
		return $ok;
	}

	/**
	 * The whole rule, with nothing of WordPress in it.
	 *
	 * @param string        $raw_body The bytes as they arrived.
	 * @param string        $header   The signature header, '' when absent.
	 * @param list<string>  $keys     The configured keys.
	 * @param int           $now_ms   Now, in unix milliseconds.
	 * @param callable|null $claim    Given the digest, returns false if it has
	 *                                been accepted before and records it
	 *                                otherwise. Null skips the replay check.
	 */
	public static function verify( string $raw_body, string $header, array $keys, int $now_ms, ?callable $claim = null ): bool {
		if ( array() === $keys ) {
			return false;
		}
		$parts = self::parse_header( $header );
		if ( null === $parts ) {
			return false;
		}
		if ( ! self::is_fresh( $parts['t'], $now_ms ) ) {
			return false;
		}
		if ( ! self::matches( $raw_body, $parts['t'], $parts['v1'], $keys ) ) {
			return false;
		}
		// Only a digest that got this far is worth remembering: recording one
		// that failed would let anyone fill the store with garbage.
		if ( null !== $claim && ! $claim( $parts['v1'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * The REST glue. Returns true when the request may proceed and a `WP_Error`
	 * when it may not — the same answer for missing, malformed, expired, wrong
	 * and replayed alike, because telling a caller which part they got wrong
	 * tells them how to get it right.
	 *
	 * The status is 401 and `error` is `Bad signature`, the two things the
	 * library's own server answers with. They arrive inside WordPress' error
	 * envelope rather than as a bare `{"error":"Bad signature"}` body: every
	 * other refusal from this route is shaped that way, and a route that
	 * answered one of its errors differently from the rest would be a worse
	 * surprise to a WordPress client than the extra wrapper is to a bugbottle
	 * one.
	 *
	 * A site with no key configured verifies nothing and accepts everything;
	 * a site with a key requires one, which is the library's own default.
	 *
	 * @return true|\WP_Error
	 */
	public static function check( \WP_REST_Request $request ) {
		$keys = self::keys();
		if ( array() === $keys ) {
			return true;
		}

		$header = (string) $request->get_header( self::HEADER );
		$body   = self::raw_body( $request );

		if ( self::verify( $body, $header, $keys, self::now_ms(), array( self::class, 'claim' ) ) ) {
			return true;
		}

		return new \WP_Error(
			'bugbottle_bad_signature',
			__( 'Bad signature.', 'bugbottle' ),
			array(
				'status' => 401,
				'error'  => 'Bad signature',
			)
		);
	}

	/**
	 * The bytes the browser sent. `get_body()` is what `WP_REST_Server` filled
	 * from `php://input` before it parsed anything, so it is the raw string
	 * for a real HTTP request and for anything `rest_do_request` was handed a
	 * body for. `php://input` is the fallback for a request built without one.
	 */
	public static function raw_body( \WP_REST_Request $request ): string {
		$body = $request->get_body();
		if ( is_string( $body ) && '' !== $body ) {
			return $body;
		}
		$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_string( $raw ) ? $raw : '';
	}

	/** Now, in unix milliseconds. */
	public static function now_ms(): int {
		return (int) round( microtime( true ) * 1000 );
	}

	/**
	 * Records a digest and reports whether it was new. A transient is enough:
	 * it expires by itself, and losing the lot to a cache flush costs a replay
	 * window, not a breach.
	 *
	 * It lives for twice the skew, because a timestamp five minutes in the
	 * future is still fresh five minutes from now — the window a digest can be
	 * reused in is the whole ten minutes, not the five.
	 */
	public static function claim( string $digest ): bool {
		$key = self::SEEN_PREFIX . $digest;
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		set_transient( $key, 1, (int) ( self::SKEW_MS / 1000 ) * 2 );
		return true;
	}
}
