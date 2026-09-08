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

	/**
	 * The screenshot renderer, enqueued only when the Screenshots setting is
	 * on. It is a second file because the panel bundle deliberately carries no
	 * renderer: `html-to-image` is larger than the rest of the panel put
	 * together, and a site that does not ask for pictures should not download
	 * it. See `bin/build-screenshot-bundle.sh`.
	 */
	public const SCREENSHOT_HANDLE = 'bugbottle-screenshot';

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

		// The inline mount call goes on whichever script is printed last, so
		// everything it reads off `window` is already there when it runs. With
		// screenshots on that is the renderer, which depends on the panel and
		// is therefore printed after it.
		$mount_on = self::HANDLE;

		if ( Settings::bool( 'screenshot' ) ) {
			wp_enqueue_script(
				self::SCREENSHOT_HANDLE,
				BUGBOTTLE_URL . 'assets/bugbottle-screenshot.js',
				array( self::HANDLE ),
				LIB_VERSION,
				true
			);
			$mount_on = self::SCREENSHOT_HANDLE;
		}

		wp_add_inline_script( $mount_on, self::config_script(), 'after' );
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
			'endpoint'    => rest_url( Rest::NAMESPACE . '/report' ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'locale'      => self::locale_tag(),
			'theme'       => $theme,
			'brand'       => $brand,
			'trigger'     => '' !== $settings['trigger_selector'] ? $settings['trigger_selector'] : null,
			// An empty shortcut is not "use the default": it is "no shortcut",
			// and `false` is how the library is told so.
			'shortcut'    => '' !== $settings['shortcut'] ? $settings['shortcut'] : false,
			'openOnError' => (bool) $settings['open_on_error'],
			// false, true or "required" — the three shapes the library's
			// `contact` option takes, which is why the setting is a tri-state
			// and not a checkbox.
			'contact'     => self::contact_option(),
			'scrub'       => (bool) $settings['scrub'],
			'queue'       => (bool) $settings['queue'],
			'network'     => (bool) $settings['network_log'],
			'breadcrumbs' => (bool) $settings['breadcrumbs'],
			'perf'        => (bool) $settings['perf'],
			'shake'       => (bool) $settings['shake'],
			'screenshot'  => (bool) $settings['screenshot'],
			'extra'       => new \stdClass(),
		);

		// Only the first key is handed to the browser. The rest of the list is
		// there so the server keeps accepting the key that older cached pages
		// were rendered with; a page rendered now signs with the current one.
		$keys = Signature::keys();
		if ( array() !== $keys ) {
			$config['signKey'] = $keys[0];
		}

		/**
		 * Filters the configuration handed to `window.bugbottle.mount`.
		 *
		 * @param array<string, mixed> $config The mount configuration.
		 */
		$config = apply_filters( 'bugbottle_panel_config', $config );

		$json = wp_json_encode( $config );

		// Assembled from single-quoted lines rather than a heredoc: the
		// WordPress.org Plugin Check forbids heredoc syntax outright, and the
		// snippet is short enough that the loss of readability is bearable.
		// The single `%s` is the JSON configuration above.
		$template = implode(
			"\n",
			array(
				'(function () {',
				'	var config = %s;',
				'	var api = window.bugbottle;',
				'	if (!api || typeof api.mount !== "function") return;',
				'	api.initConsoleBuffer();',
				'	var headers = { "X-WP-Nonce": config.nonce };',
				'	// Clicks, navigations, submits: the timeline that turns "it broke"',
				'	// into something reproducible. Older bundles do not have it.',
				'	if (config.breadcrumbs && typeof api.initBreadcrumbs === "function") api.initBreadcrumbs();',
				'	// Which call failed and how long it took. Never a body, never a header.',
				'	// The endpoint is passed so the panel does not log its own POST.',
				'	if (config.network && typeof api.initNetwork === "function") {',
				'		api.initNetwork({ endpoint: config.endpoint });',
				'	}',
				'	// How the page performed, and the names of the keys the browser had',
				'	// stored. Never a stored value, and never a cookie value at all.',
				'	if (config.perf && typeof api.initPerf === "function") api.initPerf();',
				'	var options = {',
				'		endpoint: config.endpoint,',
				'		headers: headers,',
				'		locale: api.resolveLocale(config.locale),',
				'		theme: config.theme,',
				'		brand: config.brand,',
				'		credentials: "same-origin"',
				'	};',
				'	if (config.extra && Object.keys(config.extra).length > 0) options.extra = config.extra;',
				'	if (config.trigger) options.trigger = config.trigger;',
				'	// false is meaningful here, so the option is always set.',
				'	options.shortcut = config.shortcut;',
				'	if (config.openOnError) options.openOnError = true;',
				'	// How to reach the reporter, when the site asked for it. false is the',
				'	// default in the library, so the option is only set when it is wanted.',
				'	if (config.contact) options.contact = config.contact;',
				'	if (config.scrub) options.scrub = api.scrubReport;',
				'	// The key is in the page source for anyone who looks. That is the honest',
				'	// shape of signing from a browser: it raises the cost of posting junk to',
				'	// the endpoint, and it authenticates nobody.',
				'	// One signer for both ways out: the panel signs what it sends now, and',
				'	// the queue below signs what it delivers later.',
				'	var signer = null;',
				'	if (config.signKey && typeof api.createSigner === "function") {',
				'		signer = api.createSigner({ key: config.signKey });',
				'		options.sign = signer;',
				'	}',
				'	// The gesture a phone has instead of a keyboard shortcut. On iOS nothing',
				'	// arrives until the page has called requestShakePermission() from a',
				'	// button somebody pressed. That button belongs to the theme, not to us.',
				'	if (config.shake) options.shake = api.onShake;',
				'	// The renderer is its own script and is only on the page when the',
				'	// setting is on. Handing it in is what makes the panel offer a',
				'	// picture at all — and, because this build already hands the',
				'	// annotator in, what puts "Edit picture" under the preview.',
				'	if (config.screenshot && window.bugbottleScreenshot) {',
				'		options.screenshot = window.bugbottleScreenshot.htmlToImage;',
				'	}',
				'	// The nonce travels with a queued report too: it is delivered by the',
				'	// queue rather than by the panel, on a later page load.',
				'	if (config.queue && typeof api.createQueue === "function") {',
				'		var queueOptions = { endpoint: config.endpoint, headers: headers };',
				'		// The library queue re-POSTs a finished body with a fetch of its own',
				'		// and no signer, so on a site with signing keys a report that waited',
				'		// out an outage used to arrive without X-Bugbottle-Signature and be',
				'		// refused with 401 — exactly the report the queue exists to save.',
				'		// `fetch` is the seam: sign the bytes as they go on the wire, which',
				'		// is also what gives the signature a timestamp inside the skew',
				'		// window. Signing at enqueue would be stale by the time it arrives.',
				'		if (signer) {',
				'			queueOptions.fetch = function (input, init) {',
				'				var request = init || {};',
				'				var body = typeof request.body === "string" ? request.body : "";',
				'				return signer(body).then(function (signed) {',
				'					var merged = Object.assign({}, request.headers, signed);',
				'					return window.fetch(input, Object.assign({}, request, { headers: merged }));',
				'				});',
				'			};',
				'		}',
				'		options.queue = api.createQueue(queueOptions);',
				'	}',
				'	// mount(), not mountBugbottle() from a module: this build is the one',
				'	// that carries the annotator, and its mount() is what hands it in.',
				'	api.mount(options);',
				'})();',
			)
		);

		return sprintf( $template, (string) $json );
	}

	/**
	 * The `contact` value handed to `mount()`: `false` when the site does not
	 * ask, `true` for an optional field, `"required"` for one the panel will
	 * not send without.
	 *
	 * @return bool|string
	 */
	private static function contact_option() {
		switch ( Settings::contact_mode() ) {
			case Settings::CONTACT_REQUIRED:
				return 'required';
			case Settings::CONTACT_OPTIONAL:
				return true;
			default:
				return false;
		}
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
