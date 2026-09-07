<?php
/**
 * A PHP port of `bugbottle/src/report-core.ts`.
 *
 * Everything that arrives here is attacker-controlled input that is about to
 * be written to the database, so its shape is checked rather than trusted.
 * The rules and the limits are the ones the TypeScript validators use; keep
 * the two in step when either moves.
 *
 * Nothing in here throws except the screenshot decoder: a malformed console
 * section means "no console", not a failed report. The message is the
 * valuable part.
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

namespace Bugbottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown when a screenshot is not a PNG data URL of an acceptable size.
 */
class Invalid_Screenshot_Error extends \Exception {
}

/**
 * Validators mirroring `bugbottle/server`.
 */
final class Validator {

	public const REPORT_TYPES = array( 'bug', 'idea', 'other' );

	/** Decoded ceiling for a screenshot. The client downscales before this. */
	public const MAX_SCREENSHOT_BYTES = 2097152;

	/** Base64 inflates by about a third; the prefix is the rest of the slack. */
	public const MAX_SCREENSHOT_DATA_URL_LENGTH = 2900000;

	public const MAX_MESSAGE_LENGTH = 4000;

	/** How many console entries a report may carry. Oldest are dropped first. */
	public const MAX_CONSOLE_ENTRIES = 50;

	/** Longest a single console message may be before it is clipped. */
	public const MAX_CONSOLE_MESSAGE_LENGTH = 500;

	/** How many pointed-at elements a report may carry. */
	public const MAX_ELEMENTS = 10;

	/** Longest text kept for a pointed-at element. */
	public const MAX_ELEMENT_TEXT_LENGTH = 200;

	/** How many breadcrumbs a report may carry. Oldest are dropped first. */
	public const MAX_BREADCRUMBS = 30;

	/** Longest text kept for a clicked element. Short on purpose: a label. */
	public const MAX_BREADCRUMB_TEXT_LENGTH = 40;

	public const BREADCRUMB_KINDS = array( 'click', 'navigation', 'submit', 'visibility' );

	private const PNG_DATA_URL_PREFIX = 'data:image/png;base64,';

	private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	/**
	 * @param mixed $value Anything from the request body.
	 */
	public static function is_report_type( $value ): bool {
		return is_string( $value ) && in_array( $value, self::REPORT_TYPES, true );
	}

	/**
	 * MySQL will store a null byte, but it turns text columns into something
	 * no admin screen can render, and Postgres refuses it outright. The
	 * upstream validator strips them, so this one does too.
	 */
	private static function strip_null_bytes( string $text ): string {
		return str_replace( "\0", '', $text );
	}

	/**
	 * Clips to a number of characters, not bytes, so a Danish "ø" is not cut
	 * in half.
	 *
	 * @param mixed $value Anything from the request body.
	 */
	private static function str( $value, int $max ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return mb_substr( self::strip_null_bytes( $value ), 0, $max );
	}

	/**
	 * Trims and length-checks the reporter's message. Null when there is
	 * nothing worth storing.
	 *
	 * @param mixed $raw Anything from the request body.
	 */
	public static function message( $raw, int $max_length = self::MAX_MESSAGE_LENGTH ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$text = trim( self::strip_null_bytes( $raw ) );
		if ( '' === $text ) {
			return null;
		}
		return mb_substr( $text, 0, $max_length );
	}

	/**
	 * Clips the context strings. A browser can send a user-agent of any
	 * length, and this ends up in your database.
	 *
	 * @param mixed $raw Anything from the request body.
	 * @return array{url: string, viewport: string, userAgent: string}
	 */
	public static function context( $raw ): array {
		$obj = is_array( $raw ) ? $raw : array();
		return array(
			'url'       => self::str( $obj['url'] ?? null, 500 ),
			'viewport'  => self::str( $obj['viewport'] ?? null, 32 ),
			'userAgent' => self::str( $obj['userAgent'] ?? null, 500 ),
		);
	}

	/**
	 * Validates the console entries a report arrived with. Anything that is
	 * not `{ ts, level, message }` with a known level is dropped, messages are
	 * clipped, and only the most recent entries are kept.
	 *
	 * @param mixed $raw Anything from the request body.
	 * @return array<int, array{ts: string, level: string, message: string}>
	 */
	public static function console( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$level = $item['level'] ?? null;
			if ( 'error' !== $level && 'warn' !== $level ) {
				continue;
			}
			if ( ! isset( $item['message'] ) || ! is_string( $item['message'] ) ) {
				continue;
			}
			$out[] = array(
				'ts'      => self::timestamp( $item['ts'] ?? null ),
				'level'   => $level,
				'message' => self::str( $item['message'], self::MAX_CONSOLE_MESSAGE_LENGTH ),
			);
		}
		return count( $out ) > self::MAX_CONSOLE_ENTRIES
			? array_slice( $out, -self::MAX_CONSOLE_ENTRIES )
			: $out;
	}

	/**
	 * An unparseable timestamp becomes the empty string rather than throwing
	 * the entry away — the level and the message are still worth keeping.
	 *
	 * @param mixed $value Anything from the request body.
	 */
	private static function timestamp( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		return false === strtotime( $value ) ? '' : mb_substr( self::strip_null_bytes( $value ), 0, 40 );
	}

	/**
	 * Validates the elements a report arrived with. Malformed entries are
	 * dropped, strings are clipped, attribute names are limited to a safe
	 * pattern, and at most ten are kept.
	 *
	 * @param mixed $raw Anything from the request body.
	 * @return array<int, array<string, mixed>>
	 */
	public static function elements( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( count( $out ) >= self::MAX_ELEMENTS ) {
				break;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$selector = self::str( $item['selector'] ?? null, 500 );
			$tag      = self::str( $item['tag'] ?? null, 32 );
			if ( '' === $selector || '' === $tag ) {
				continue;
			}
			$rect       = is_array( $item['rect'] ?? null ) ? $item['rect'] : array();
			$attributes = array();
			if ( is_array( $item['attributes'] ?? null ) ) {
				foreach ( $item['attributes'] as $key => $value ) {
					if ( count( $attributes ) >= 20 ) {
						break;
					}
					if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9-]{0,63}$/', $key ) ) {
						continue;
					}
					// Never echo our own hooks back: they say nothing about the page.
					if ( str_starts_with( $key, 'data-bugbottle' ) ) {
						continue;
					}
					if ( is_string( $value ) ) {
						$attributes[ $key ] = self::str( $value, 200 );
					}
				}
			}
			$out[] = array(
				'selector'   => $selector,
				'tag'        => $tag,
				'text'       => self::str( $item['text'] ?? null, self::MAX_ELEMENT_TEXT_LENGTH ),
				'rect'       => array(
					'x'      => self::num( $rect['x'] ?? null ),
					'y'      => self::num( $rect['y'] ?? null ),
					'width'  => self::num( $rect['width'] ?? null ),
					'height' => self::num( $rect['height'] ?? null ),
				),
				'attributes' => $attributes,
			);
		}
		return $out;
	}

	/**
	 * @param mixed $value Anything from the request body.
	 */
	private static function num( $value ): int {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) && is_finite( $value ) ) {
			return (int) round( $value );
		}
		return 0;
	}

	/**
	 * Validates the breadcrumbs a report arrived with. Entries with an unknown
	 * kind are dropped, strings are clipped, fields that are not strings are
	 * left out entirely, and the most recent thirty are kept — the end of the
	 * timeline is the interesting end.
	 *
	 * @param mixed $raw Anything from the request body.
	 * @return array<int, array<string, string>>
	 */
	public static function breadcrumbs( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$kind = $item['kind'] ?? null;
			if ( ! is_string( $kind ) || ! in_array( $kind, self::BREADCRUMB_KINDS, true ) ) {
				continue;
			}
			$crumb = array(
				'ts'   => self::timestamp( $item['ts'] ?? null ),
				'kind' => $kind,
			);
			if ( isset( $item['target'] ) && is_string( $item['target'] ) ) {
				$crumb['target'] = self::str( $item['target'], 500 );
			}
			if ( isset( $item['text'] ) && is_string( $item['text'] ) ) {
				$crumb['text'] = self::str( $item['text'], self::MAX_BREADCRUMB_TEXT_LENGTH );
			}
			if ( isset( $item['from'] ) && is_string( $item['from'] ) ) {
				$crumb['from'] = self::str( $item['from'], 500 );
			}
			if ( isset( $item['to'] ) && is_string( $item['to'] ) ) {
				$crumb['to'] = self::str( $item['to'], 500 );
			}
			$out[] = $crumb;
		}
		return count( $out ) > self::MAX_BREADCRUMBS
			? array_slice( $out, -self::MAX_BREADCRUMBS )
			: $out;
	}

	/**
	 * Turns a PNG data URL into bytes, or throws.
	 *
	 * Checks the declared type, the real PNG signature in the decoded bytes,
	 * and a size ceiling — so a JPEG wearing a PNG label, a login page
	 * returned as HTML, or a 40 MB payload never reaches storage.
	 *
	 * Treat a failure as "store the report without the picture" rather than as
	 * a failed submission.
	 *
	 * @param mixed $data_url Anything from the request body.
	 * @throws Invalid_Screenshot_Error When the value is not an acceptable PNG data URL.
	 */
	public static function decode_screenshot( $data_url ): string {
		if ( ! is_string( $data_url ) || ! str_starts_with( $data_url, self::PNG_DATA_URL_PREFIX ) ) {
			throw new Invalid_Screenshot_Error( 'Screenshot must be a PNG data URL' );
		}
		if ( strlen( $data_url ) > self::MAX_SCREENSHOT_DATA_URL_LENGTH ) {
			throw new Invalid_Screenshot_Error( 'Screenshot is too large' );
		}

		$bytes = base64_decode( substr( $data_url, strlen( self::PNG_DATA_URL_PREFIX ) ), true );
		if ( false === $bytes ) {
			throw new Invalid_Screenshot_Error( 'Screenshot is not valid base64' );
		}
		if ( strlen( $bytes ) > self::MAX_SCREENSHOT_BYTES ) {
			throw new Invalid_Screenshot_Error( 'Screenshot is too large' );
		}
		// A short buffer would otherwise pass the signature check on a prefix.
		if ( strlen( $bytes ) < strlen( self::PNG_SIGNATURE )
			|| substr( $bytes, 0, strlen( self::PNG_SIGNATURE ) ) !== self::PNG_SIGNATURE ) {
			throw new Invalid_Screenshot_Error( 'Screenshot is not a valid PNG' );
		}
		return $bytes;
	}
}
