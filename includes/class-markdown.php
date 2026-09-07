<?php
/**
 * A PHP port of `bugbottle/src/markdown.ts`.
 *
 * A report rendered as Markdown — for the email that goes out and for the
 * summary on the detail screen. The layout is deliberately boring: heading,
 * message, a small table of facts, then the evidence sections in the order a
 * reader wants them (elements first, because they say where; console last,
 * because it is long).
 *
 * Every value goes through `Validator` first, so this accepts the raw body
 * from the request as well as a stored report. The output is deliberately not
 * translated: it mirrors the library's rendering so an issue body pasted from
 * an email looks like every other bugbottle report.
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

namespace Bugbottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a report as Markdown.
 */
final class Markdown {

	private const TYPE_LABEL = array(
		'bug'   => 'Bug',
		'idea'  => 'Idea',
		'other' => 'Feedback',
	);

	/**
	 * How many frames of a stack are printed under a console entry. Ten are
	 * kept in the report, but a reader scanning an issue wants the innermost
	 * few; the whole stack is in the JSON for anyone who needs it.
	 */
	private const MAX_RENDERED_STACK_FRAMES = 3;

	/**
	 * Renders a report (raw request body or stored report) as Markdown. Never
	 * throws on malformed input: missing sections are left out.
	 *
	 * @param array<string, mixed> $raw     The report.
	 * @param array<string, mixed> $options heading_level, type_in_title, max_title_length,
	 *                                      max_console_entries, facts, screenshot_url, collapse_console.
	 */
	public static function render( array $raw, array $options = array() ): string {
		$type    = Validator::is_report_type( $raw['type'] ?? null ) ? (string) $raw['type'] : 'other';
		$message = Validator::message( $raw['message'] ?? null ) ?? '';
		$context = Validator::context( $raw['context'] ?? null );
		$elements    = Validator::elements( $raw['elements'] ?? null );
		$breadcrumbs = Validator::breadcrumbs( $raw['breadcrumbs'] ?? null );
		$network     = Validator::network( $raw['network'] ?? null );
		$console     = Validator::console( $raw['console'] ?? null );

		$max_console = $options['max_console_entries'] ?? null;
		if ( is_int( $max_console ) && count( $console ) > $max_console ) {
			$console = array_slice( $console, -$max_console );
		}

		$heading_level = isset( $options['heading_level'] ) ? (int) $options['heading_level'] : 2;
		$max_title     = isset( $options['max_title_length'] ) ? (int) $options['max_title_length'] : 80;
		$type_label    = self::TYPE_LABEL[ $type ] ?? 'Report';

		$out = array();
		if ( $heading_level > 0 ) {
			$out[] = str_repeat( '#', min( $heading_level, 6 ) ) . ' ' . self::title( $message, $type_label, $max_title, $options );
			$out[] = '';
		}
		if ( '' !== $message ) {
			$out[] = $message;
			$out[] = '';
		}

		$facts = array( array( 'Type', $type_label ) );
		if ( '' !== $context['url'] ) {
			$facts[] = array( 'Page', '`' . $context['url'] . '`' );
		}
		if ( '' !== $context['viewport'] ) {
			$facts[] = array( 'Viewport', $context['viewport'] );
		}
		if ( isset( $context['screen'] ) ) {
			$facts[] = array( 'Screen', (string) $context['screen'] );
		}
		if ( '' !== $context['userAgent'] ) {
			$facts[] = array( 'Browser', $context['userAgent'] );
		}
		if ( isset( $context['language'] ) ) {
			$facts[] = array( 'Language', (string) $context['language'] );
		}
		if ( isset( $context['timezone'] ) ) {
			$facts[] = array( 'Time zone', (string) $context['timezone'] );
		}
		if ( isset( $context['colorScheme'] ) ) {
			$facts[] = array( 'Colour scheme', (string) $context['colorScheme'] );
		}
		if ( isset( $context['online'] ) && is_bool( $context['online'] ) ) {
			$facts[] = array( 'Online', $context['online'] ? 'yes' : 'no' );
		}
		if ( isset( $context['connection'] ) ) {
			$facts[] = array( 'Connection', (string) $context['connection'] );
		}
		$last = end( $console );
		if ( is_array( $last ) && '' !== $last['ts'] ) {
			$facts[] = array( 'Last console entry', $last['ts'] );
		}
		if ( ! empty( $options['screenshot_url'] ) ) {
			$facts[] = array( 'Screenshot', (string) $options['screenshot_url'] );
		} elseif ( ! empty( $raw['screenshotDataUrl'] ) && is_string( $raw['screenshotDataUrl'] ) ) {
			$facts[] = array( 'Screenshot', 'attached' );
		}
		if ( isset( $options['facts'] ) && is_array( $options['facts'] ) ) {
			foreach ( $options['facts'] as $key => $value ) {
				if ( null !== $value && '' !== $value ) {
					$facts[] = array( (string) $key, (string) $value );
				}
			}
		}
		$out[] = '| | |';
		$out[] = '|---|---|';
		foreach ( $facts as $fact ) {
			$out[] = '| ' . self::cell( $fact[0] ) . ' | ' . self::cell( $fact[1] ) . ' |';
		}
		$out[] = '';

		if ( count( $elements ) > 0 ) {
			$out[] = '### Element' . ( count( $elements ) > 1 ? 's' : '' ) . ' pointed at';
			$out[] = '';
			foreach ( $elements as $element ) {
				$out[] = self::element_line( $element );
			}
			$out[] = '';
		}

		if ( count( $breadcrumbs ) > 0 ) {
			$out[] = '### What happened before';
			$out[] = '';
			foreach ( $breadcrumbs as $crumb ) {
				$out[] = self::breadcrumb_line( $crumb );
			}
			$out[] = '';
		}

		if ( count( $network ) > 0 ) {
			$out[] = '### Requests';
			$out[] = '';
			$out[] = '| Method | URL | Status | ms |';
			$out[] = '|---|---|---|---|';
			foreach ( $network as $entry ) {
				$out[] = self::request_row( $entry );
			}
			$out[] = '';
		}

		if ( count( $console ) > 0 ) {
			$lines = array();
			foreach ( $console as $entry ) {
				$lines[] = ( '' !== $entry['ts'] ? $entry['ts'] . ' ' : '' ) . '[' . $entry['level'] . '] ' . $entry['message'];
				// The top of the stack is where the fault is; the rest is framework.
				$frames = is_array( $entry['stack'] ?? null ) ? $entry['stack'] : array();
				foreach ( array_slice( $frames, 0, self::MAX_RENDERED_STACK_FRAMES ) as $frame ) {
					$lines[] = '    at ' . ( isset( $frame['fn'] ) ? $frame['fn'] . ' ' : '' )
						. $frame['file'] . ':' . $frame['line'] . ':' . $frame['col'];
				}
			}
			$block = self::fence( implode( "\n", $lines ), 'text' );
			$label = sprintf(
				'Console (%d %s)',
				count( $console ),
				1 === count( $console ) ? 'entry' : 'entries'
			);
			if ( $options['collapse_console'] ?? true ) {
				$out[] = '<details><summary>' . $label . '</summary>';
				$out[] = '';
				$out[] = $block;
				$out[] = '';
				$out[] = '</details>';
				$out[] = '';
			} else {
				$out[] = '### ' . $label;
				$out[] = '';
				$out[] = $block;
				$out[] = '';
			}
		}

		return rtrim( implode( "\n", $out ) ) . "\n";
	}

	/**
	 * The title is the first line of the message, clipped. A report with no
	 * message falls back to its type.
	 *
	 * @param array<string, mixed> $options Render options.
	 */
	public static function title( string $message, string $type_label, int $max_title = 80, array $options = array() ): string {
		$first_line = trim( (string) ( preg_split( '/\r?\n/', $message )[0] ?? '' ) );
		$title      = mb_strlen( $first_line ) > $max_title
			? rtrim( mb_substr( $first_line, 0, $max_title - 1 ) ) . '…'
			: $first_line;
		if ( '' === $title ) {
			$title = $type_label;
		}
		if ( $options['type_in_title'] ?? true ) {
			$title = $type_label . ': ' . $title;
		}
		return $title;
	}

	/**
	 * The title of a stored report, for the admin list and the post title.
	 *
	 * @param array<string, mixed> $options Render options.
	 */
	public static function title_for( string $type, string $message, array $options = array() ): string {
		return self::title( $message, self::TYPE_LABEL[ $type ] ?? 'Report', 80, $options );
	}

	/** Pipes and newlines would break a table cell. */
	private static function cell( string $value ): string {
		return trim( (string) preg_replace( '/\r?\n/', ' ', str_replace( '|', '\\|', $value ) ) );
	}

	/** A message containing ``` would otherwise close the block early. */
	private static function fence( string $text, string $lang = '' ): string {
		$ticks = str_contains( $text, '```' ) ? '````' : '```';
		return $ticks . $lang . "\n" . $text . "\n" . $ticks;
	}

	/**
	 * @param array<string, mixed> $element A validated element.
	 */
	private static function element_line( array $element ): string {
		$bits = array( '`' . $element['selector'] . '`' );
		if ( '' !== $element['text'] ) {
			$bits[] = '— "' . $element['text'] . '"';
		}
		$attrs = array();
		foreach ( $element['attributes'] as $key => $value ) {
			if ( 'id' === $key ) {
				continue;
			}
			$attrs[] = $key . '="' . $value . '"';
		}
		if ( count( $attrs ) > 0 ) {
			$bits[] = '(' . implode( ' ', $attrs ) . ')';
		}
		$rect   = $element['rect'];
		$bits[] = sprintf( 'at %d,%d %d×%d', $rect['x'], $rect['y'], $rect['width'], $rect['height'] );
		return '- ' . implode( ' ', $bits );
	}

	/**
	 * One row of the Requests table. A request that never got a status shows
	 * the failure instead of a bare 0, which reads as a status nobody
	 * recognises.
	 *
	 * @param array<string, mixed> $entry A validated network entry.
	 */
	private static function request_row( array $entry ): string {
		$code   = (int) $entry['status'];
		$status = '';
		if ( $code > 0 ) {
			$status = (string) $code;
		} elseif ( ! empty( $entry['error'] ) ) {
			$status = 'failed';
		}
		return '| ' . self::cell( (string) $entry['method'] ) . ' | `' . self::cell( (string) $entry['url'] )
			. '` | ' . $status . ' | ' . (int) $entry['ms'] . ' |';
	}

	/**
	 * @param array<string, string> $crumb A validated breadcrumb.
	 */
	private static function breadcrumb_line( array $crumb ): string {
		$bits = array();
		if ( '' !== $crumb['ts'] ) {
			$bits[] = $crumb['ts'];
		}
		if ( 'click' === $crumb['kind'] || 'submit' === $crumb['kind'] ) {
			$bits[] = 'click' === $crumb['kind'] ? 'clicked' : 'submitted';
			if ( isset( $crumb['target'] ) ) {
				$bits[] = '`' . $crumb['target'] . '`';
			}
			if ( isset( $crumb['text'] ) ) {
				$bits[] = '— "' . $crumb['text'] . '"';
			}
		} elseif ( 'navigation' === $crumb['kind'] ) {
			$bits[] = 'navigated';
			if ( isset( $crumb['from'] ) ) {
				$bits[] = '`' . $crumb['from'] . '` →';
			}
			$bits[] = '`' . ( $crumb['to'] ?? '' ) . '`';
		} else {
			$bits[] = 'page ' . ( $crumb['to'] ?? 'changed' );
		}
		return '- ' . implode( ' ', $bits );
	}
}
