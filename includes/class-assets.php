<?php
/**
 * Putting the panel on the page.
 *
 * The bundle is enqueued in the footer and followed by a short inline script
 * that mounts it. The mount call is explicit rather than driven by
 * `data-*` attributes: the settings are a JSON object with nested theme and
 * brand shapes, and squeezing that through attributes only to parse it back
 * out buys nothing.
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

namespace Bugbottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end script and its configuration.
 */
final class Assets {

	public const HANDLE = 'bugbottle';

	public static function enqueue(): void {
		if ( ! self::should_show() ) {
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			BUGBOTTLE_URL . 'assets/bugbottle.js',
			array(),
			LIB_VERSION,
			true
		);

		wp_add_inline_script( self::HANDLE, self::config_script(), 'after' );
	}

	/**
	 * The panel is off when the site says so, in wp-admin, and — when the site
	 * asks for it — for visitors who are not logged in. There is no point
	 * showing a panel to somebody whose report the REST route will refuse.
	 */
	private static function should_show(): bool {
		if ( ! Settings::bool( 'enabled' ) ) {
			return false;
		}
		if ( is_admin() ) {
			return false;
		}
		if ( ! is_user_logged_in() ) {
			if ( Settings::bool( 'logged_in_only' ) || ! Settings::bool( 'allow_anonymous' ) ) {
				return false;
			}
		}

		/**
		 * Filters whether the panel is rendered on this request.
		 *
		 * @param bool $show Whether to show the panel.
		 */
		return (bool) apply_filters( 'bugbottle_show_panel', true );
	}

	/**
	 * The inline mount call. Everything the panel needs is in one JSON object
	 * so nothing here has to be escaped by hand.
	 */
	private static function config_script(): string {
		$settings = Settings::all();

		$theme = array(
			'primary'  => $settings['primary_color'],
			'position' => $settings['position'],
		);

		$brand = array();
		if ( '' !== $settings['brand_name'] ) {
			$brand['name'] = $settings['brand_name'];
		}
		if ( '' !== $settings['logo_url'] ) {
			$brand['logo'] = $settings['logo_url'];
		}

		$config = array(
			'endpoint' => rest_url( Rest::NAMESPACE . '/report' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'locale'   => self::locale_tag(),
			'theme'    => $theme,
			'brand'    => $brand,
			'trigger'  => '' !== $settings['trigger_selector'] ? $settings['trigger_selector'] : null,
			'scrub'    => (bool) $settings['scrub'],
			'extra'    => new \stdClass(),
		);

		/**
		 * Filters the configuration handed to `window.bugbottle.mount`.
		 *
		 * @param array<string, mixed> $config The mount configuration.
		 */
		$config = apply_filters( 'bugbottle_panel_config', $config );

		$json = wp_json_encode( $config );

		return <<<JS
(function () {
	var config = {$json};
	var api = window.bugbottle;
	if (!api || typeof api.mount !== "function") return;
	api.initConsoleBuffer();
	// Clicks, navigations, submits: the timeline that turns "it broke"
	// into something reproducible. Older bundles do not have it.
	if (typeof api.initBreadcrumbs === "function") api.initBreadcrumbs();
	var options = {
		endpoint: config.endpoint,
		headers: { "X-WP-Nonce": config.nonce },
		locale: api.resolveLocale(config.locale),
		theme: config.theme,
		brand: config.brand,
		credentials: "same-origin"
	};
	if (config.extra && Object.keys(config.extra).length > 0) options.extra = config.extra;
	if (config.trigger) options.trigger = config.trigger;
	if (config.scrub) options.scrub = api.scrubReport;
	api.mount(options);
})();
JS;
	}

	/**
	 * A BCP 47 tag `resolveLocale` understands. WordPress says `da_DK`; the
	 * library wants `da-DK`, and falls back to English on anything it does not
	 * recognise.
	 */
	public static function locale_tag(): string {
		$configured = Settings::string( 'locale' );
		if ( '' !== $configured && 'auto' !== $configured ) {
			return $configured;
		}
		return str_replace( '_', '-', get_locale() );
	}
}
