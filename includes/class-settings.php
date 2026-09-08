<?php
/**
 * One option row, `bugbottle_settings`, holding everything the settings page
 * writes and everything the front end and the REST route read.
 *
 * Reads always go through `get()` so a site that upgrades from an older
 * version never sees a missing key: the defaults are merged in on every read
 * rather than written to the database on activation.
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

namespace Bugbottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's settings, their defaults, and the settings screen.
 */
final class Settings {

	public const OPTION = 'bugbottle_settings';

	public const LOCALES = array( 'auto', 'da', 'en', 'sv', 'nb', 'de', 'nl', 'fr', 'es' );

	public const POSITIONS = array( 'bottom-right', 'bottom-left', 'top-right', 'top-left' );

	/**
	 * The contact field is off, optional, or required — the three the library's
	 * `contact: false | true | "required"` option has. It is a tri-state rather
	 * than two checkboxes because "required but not shown" is not a thing.
	 */
	public const CONTACT_OFF      = 'off';
	public const CONTACT_OPTIONAL = 'optional';
	public const CONTACT_REQUIRED = 'required';

	public const CONTACT_MODES = array( self::CONTACT_OFF, self::CONTACT_OPTIONAL, self::CONTACT_REQUIRED );

	/**
	 * The combination the library opens the panel on by default. `mod` is
	 * Command on a Mac and Control everywhere else, which is why it is written
	 * once rather than as two settings. An empty setting means no shortcut.
	 */
	public const DEFAULT_SHORTCUT = 'mod+shift+b';

	/**
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'enabled'          => true,
			'logged_in_only'   => false,
			'allow_anonymous'  => false,
			'locale'           => 'auto',
			'primary_color'    => '#2563eb',
			'position'         => 'bottom-right',
			'brand_name'       => '',
			'logo_url'         => '',
			'trigger_selector' => '',
			'shortcut'         => self::DEFAULT_SHORTCUT,
			'open_on_error'    => false,
			'contact'          => self::CONTACT_OFF,
			'scrub'            => true,
			'queue'            => true,
			'network_log'      => true,
			'breadcrumbs'      => true,
			'perf'             => false,
			'shake'            => false,
			'screenshot'       => false,
			'signing_keys'     => '',
			'email_recipient'  => '',
			'email_on_submit'  => false,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function bool( string $key ): bool {
		return (bool) self::get( $key );
	}

	public static function string( string $key ): string {
		$value = self::get( $key );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * How the panel asks for a way of reaching the reporter: not at all, as an
	 * optional field, or as one that refuses to send empty. Anything the option
	 * does not recognise is "off", because off is the safe answer for a field
	 * that collects personal data.
	 */
	public static function contact_mode(): string {
		$mode = self::string( 'contact' );
		return in_array( $mode, self::CONTACT_MODES, true ) ? $mode : self::CONTACT_OFF;
	}

	/**
	 * Registers the option. `sanitize` is the only place settings are written,
	 * so the sanitiser is the whole validation story for this screen.
	 */
	public static function register(): void {
		register_setting(
			'bugbottle',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input Raw $_POST value for the option.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$flags = array(
			'enabled',
			'logged_in_only',
			'allow_anonymous',
			'open_on_error',
			'scrub',
			'queue',
			'network_log',
			'breadcrumbs',
			'perf',
			'shake',
			'screenshot',
			'email_on_submit',
		);
		foreach ( $flags as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}

		$locale = isset( $input['locale'] ) ? (string) $input['locale'] : 'auto';
		$out['locale'] = in_array( $locale, self::LOCALES, true ) ? $locale : 'auto';

		$contact = isset( $input['contact'] ) ? (string) $input['contact'] : self::CONTACT_OFF;
		$out['contact'] = in_array( $contact, self::CONTACT_MODES, true ) ? $contact : self::CONTACT_OFF;

		$position = isset( $input['position'] ) ? (string) $input['position'] : 'bottom-right';
		$out['position'] = in_array( $position, self::POSITIONS, true ) ? $position : 'bottom-right';

		$colour = isset( $input['primary_color'] ) ? sanitize_hex_color( (string) $input['primary_color'] ) : null;
		$out['primary_color'] = $colour ?? self::defaults()['primary_color'];

		$out['brand_name']       = sanitize_text_field( (string) ( $input['brand_name'] ?? '' ) );
		$out['logo_url']         = esc_url_raw( (string) ( $input['logo_url'] ?? '' ) );
		$out['trigger_selector'] = sanitize_text_field( (string) ( $input['trigger_selector'] ?? '' ) );
		$out['shortcut']         = self::shortcut( $input['shortcut'] ?? null );

		$out['signing_keys'] = self::signing_keys( $input['signing_keys'] ?? '' );

		$recipient = trim( (string) ( $input['email_recipient'] ?? '' ) );
		$out['email_recipient'] = is_email( $recipient ) ? sanitize_email( $recipient ) : '';

		return $out;
	}

	/**
	 * A keyboard combination the library will accept: lower-case names joined
	 * by `+`, such as `mod+shift+b`. The empty string is kept as-is — it is
	 * how the screen says "no shortcut" — and anything that is neither is a
	 * typo, so the default is restored rather than a combination nobody can
	 * press being saved.
	 *
	 * @param mixed $raw Raw $_POST value.
	 */
	private static function shortcut( $raw ): string {
		$value = strtolower( trim( (string) ( is_scalar( $raw ) ? $raw : '' ) ) );
		if ( '' === $value ) {
			return '';
		}
		$value = (string) preg_replace( '/\s+/', '', $value );
		foreach ( explode( '+', $value ) as $part ) {
			if ( 1 !== preg_match( '/^[a-z0-9]{1,12}$/', $part ) ) {
				return self::DEFAULT_SHORTCUT;
			}
		}
		return mb_substr( $value, 0, 64 );
	}

	/**
	 * The signing keys, one per line. A key is an opaque secret, so nothing is
	 * done to its characters beyond trimming the line and dropping the blank
	 * ones; only the length is capped, because a textarea is otherwise a place
	 * to store a novel in the options table.
	 *
	 * @param mixed $raw Raw $_POST value.
	 */
	private static function signing_keys( $raw ): string {
		$value = is_scalar( $raw ) ? (string) $raw : '';
		$value = str_replace( "\0", '', $value );
		$lines = array();
		foreach ( preg_split( '/\R/', $value ) ?: array() as $line ) {
			$line = trim( $line );
			if ( '' !== $line && ! in_array( $line, $lines, true ) ) {
				$lines[] = mb_substr( $line, 0, 200 );
			}
			if ( count( $lines ) >= 10 ) {
				break;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * The settings screen. Rendered by `Admin`, kept here so the fields live
	 * next to their sanitiser.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'bugbottle' ) );
		}
		$s = self::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bugbottle settings', 'bugbottle' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'bugbottle' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Panel', 'bugbottle' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bugbottle_settings[enabled]" value="1" <?php checked( $s['enabled'] ); ?>>
								<?php esc_html_e( 'Show the report panel on the front end', 'bugbottle' ); ?>
							</label><br>
							<label>
								<input type="checkbox" name="bugbottle_settings[logged_in_only]" value="1" <?php checked( $s['logged_in_only'] ); ?>>
								<?php esc_html_e( 'Only for logged-in users', 'bugbottle' ); ?>
							</label><br>
							<label>
								<input type="checkbox" name="bugbottle_settings[allow_anonymous]" value="1" <?php checked( $s['allow_anonymous'] ); ?>>
								<?php esc_html_e( 'Accept reports from visitors who are not logged in (rate-limited to 10 per hour per IP address)', 'bugbottle' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bugbottle-locale"><?php esc_html_e( 'Language', 'bugbottle' ); ?></label></th>
						<td>
							<select id="bugbottle-locale" name="bugbottle_settings[locale]">
								<?php foreach ( self::LOCALES as $code ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['locale'], $code ); ?>>
										<?php echo esc_html( self::locale_label( $code ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The language the panel speaks. Auto follows the site language.', 'bugbottle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bugbottle-color"><?php esc_html_e( 'Primary colour', 'bugbottle' ); ?></label></th>
						<td><input id="bugbottle-color" type="text" class="regular-text" name="bugbottle_settings[primary_color]" value="<?php echo esc_attr( $s['primary_color'] ); ?>" placeholder="#2563eb"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bugbottle-position"><?php esc_html_e( 'Position', 'bugbottle' ); ?></label></th>
						<td>
							<select id="bugbottle-position" name="bugbottle_settings[position]">
								<?php foreach ( self::POSITIONS as $position ) : ?>
									<option value="<?php echo esc_attr( $position ); ?>" <?php selected( $s['position'], $position ); ?>>
										<?php echo esc_html( self::position_label( $position ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bugbottle-brand"><?php esc_html_e( 'Brand name', 'bugbottle' ); ?></label></th>
						<td><input id="bugbottle-brand" type="text" class="regular-text" name="bugbottle_settings[brand_name]" value="<?php echo esc_attr( $s['brand_name'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bugbottle-logo"><?php esc_html_e( 'Logo URL', 'bugbottle' ); ?></label></th>
						<td><input id="bugbottle-logo" type="url" class="regular-text" name="bugbottle_settings[logo_url]" value="<?php echo esc_attr( $s['logo_url'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="bugbottle-trigger"><?php esc_html_e( 'Trigger selector', 'bugbottle' ); ?></label></th>
						<td>
							<input id="bugbottle-trigger" type="text" class="regular-text" name="bugbottle_settings[trigger_selector]" value="<?php echo esc_attr( $s['trigger_selector'] ); ?>" placeholder="#report-a-bug">
							<p class="description"><?php esc_html_e( 'A CSS selector for your own button. Leave empty for the floating button.', 'bugbottle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bugbottle-shortcut"><?php esc_html_e( 'Keyboard shortcut', 'bugbottle' ); ?></label></th>
						<td>
							<input id="bugbottle-shortcut" type="text" class="regular-text" name="bugbottle_settings[shortcut]" value="<?php echo esc_attr( $s['shortcut'] ); ?>" placeholder="<?php echo esc_attr( self::DEFAULT_SHORTCUT ); ?>">
							<p class="description"><?php esc_html_e( 'Opens the panel from the keyboard. "mod" is Command on a Mac and Ctrl everywhere else. Leave empty for no shortcut.', 'bugbottle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Open on error', 'bugbottle' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bugbottle_settings[open_on_error]" value="1" <?php checked( $s['open_on_error'] ); ?>>
								<?php esc_html_e( 'Open the panel by itself when the page throws an uncaught error', 'bugbottle' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default: it shows the panel to whoever happens to be on the page, including customers.', 'bugbottle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Contact field', 'bugbottle' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Ask the reporter how to reach them', 'bugbottle' ); ?></legend>
								<?php foreach ( self::CONTACT_MODES as $mode ) : ?>
									<label>
										<input type="radio" name="bugbottle_settings[contact]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( self::contact_mode(), $mode ); ?>>
										<?php echo esc_html( self::contact_label( $mode ) ); ?>
									</label><br>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Off by default. It adds one field under the message asking how the reporter can be reached, because "the save button does nothing" is worth a reply. Nothing checks what they type: a phone number or a name in your own chat is a good answer, and required only means the panel will not send an empty field.', 'bugbottle' ); ?></p>
							<p class="description"><strong><?php esc_html_e( 'The contact field is personal data you asked for.', 'bugbottle' ); ?></strong> <?php esc_html_e( 'Store it like one: it is kept where the rest of the report is kept, it goes when you delete the report, and it is shown to everyone who can read reports. Asking for an address is also a promise to answer, and that promise is yours to make.', 'bugbottle' ); ?></p>
							<p class="description"><?php esc_html_e( 'When the line looks like an email address, the notification email is sent with it as Reply-To, so replying answers the reporter.', 'bugbottle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Evidence', 'bugbottle' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bugbottle_settings[breadcrumbs]" value="1" <?php checked( $s['breadcrumbs'] ); ?>>
								<?php esc_html_e( 'Breadcrumbs: record clicks, navigations and form submits before the report', 'bugbottle' ); ?>
							</label><br>
							<label>
								<input type="checkbox" name="bugbottle_settings[network_log]" value="1" <?php checked( $s['network_log'] ); ?>>
								<?php esc_html_e( 'Network log: record requests that failed or were slow. Never their bodies or headers', 'bugbottle' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Screenshots', 'bugbottle' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bugbottle_settings[screenshot]" value="1" <?php checked( $s['screenshot'] ); ?>>
								<?php esc_html_e( 'Let the reporter attach a picture of the page', 'bugbottle' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. Taking a picture needs a renderer, and a renderer is a second script of about 15 kB (6 kB over the wire) on every page the panel is on, so it is only loaded when this box is ticked. Until version 0.4.1 the plugin shipped no renderer at all and the panel never offered a picture.', 'bugbottle' ); ?></p>
							<p class="description"><strong><?php esc_html_e( 'A picture of the page contains whatever was on the page.', 'bugbottle' ); ?></strong> <?php esc_html_e( 'The name of another customer, a price, an order, a message that was open in a tab beside the bug. Reports are a private post type and the picture is served only to people who can read them, through a plugin route of its own rather than the media library — but everyone who can read a report sees it, and forwarding one forwards the picture. Ask for screenshots only if you would be comfortable reading them.', 'bugbottle' ); ?></p>
							<p class="description">
								<?php
								printf(
									/* translators: 1: the data-bugbottle-mask attribute, wrapped in <code>; 2: the data-bugbottle-block attribute, wrapped in <code> */
									esc_html__( 'What is typed into a field is replaced with bullets before the picture is taken. Mark anything else you do not want in it with %1$s to bullet its text, or %2$s to cover it entirely.', 'bugbottle' ),
									'<code>data-bugbottle-mask</code>',
									'<code>data-bugbottle-block</code>'
								);
								?>
							</p>
							<p class="description"><?php esc_html_e( 'The reporter sees the picture before it is sent, can untick it, and can mark it first: a rectangle, an arrow, and a blur that reads the region back out of the canvas so the original pixels leave with it.', 'bugbottle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Offline queue', 'bugbottle' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bugbottle_settings[queue]" value="1" <?php checked( $s['queue'] ); ?>>
								<?php esc_html_e( 'Keep a report the browser could not send and deliver it when the connection is back', 'bugbottle' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Reports wait in the browser, for up to seven days.', 'bugbottle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Timings and storage snapshot', 'bugbottle' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bugbottle_settings[perf]" value="1" <?php checked( $s['perf'] ); ?>>
								<?php esc_html_e( 'Record how the page performed, and which keys the browser had stored', 'bugbottle' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. The timings are the ones a web vitals report shows: largest contentful paint, layout shift, interaction to next paint, time to first byte, the load events, long tasks and — in Chrome — the JavaScript heap.', 'bugbottle' ); ?></p>
							<p class="description"><?php esc_html_e( 'The storage snapshot lists the key names in localStorage and sessionStorage with the length of each value, and the names of the cookies. It never records a value, and never a cookie value at all. A key name can still say something — "impersonating_user" is a fact about the visit — so read the report screen before you forward one.', 'bugbottle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Shake to report', 'bugbottle' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bugbottle_settings[shake]" value="1" <?php checked( $s['shake'] ); ?>>
								<?php esc_html_e( 'Open the panel when the phone is shaken', 'bugbottle' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. It is the gesture a phone has instead of a keyboard shortcut: three shakes inside a second, with a three-second pause afterwards so one gesture opens one panel.', 'bugbottle' ); ?></p>
							<p class="description">
								<?php
								printf(
									/* translators: %s: the JavaScript call a theme has to make, already wrapped in <code> */
									esc_html__( 'On iPhone and iPad, Safari only reports motion after the visitor has agreed to it, and it will only ask from a button the visitor pressed. This plugin never puts up that prompt for you. If you want the gesture there, call %s from a button of your own; everywhere else it works as soon as you tick this box.', 'bugbottle' ),
									'<code>window.bugbottle.requestShakePermission()</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Scrubbing', 'bugbottle' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bugbottle_settings[scrub]" value="1" <?php checked( $s['scrub'] ); ?>>
								<?php esc_html_e( 'Redact email addresses, tokens, card numbers and query values before the report is sent', 'bugbottle' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bugbottle-signing-keys"><?php esc_html_e( 'Signing key(s)', 'bugbottle' ); ?></label></th>
						<td>
							<textarea id="bugbottle-signing-keys" class="large-text code" rows="3" name="bugbottle_settings[signing_keys]" spellcheck="false"><?php echo esc_textarea( $s['signing_keys'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One key per line. A report must then arrive signed with any one of them, and a report signed with a key that is not here is refused. Several lines is how a key is rotated: add the new one, wait for cached pages carrying the old one to expire, then delete the old one. Empty means no signature is asked for.', 'bugbottle' ); ?></p>
							<p class="description"><strong><?php esc_html_e( 'A key that is sent to the browser is public.', 'bugbottle' ); ?></strong> <?php esc_html_e( 'It is in the page source, so anyone who wants it has it. Signing raises the cost of sending junk to the endpoint from a script that has not read your page — it is spam deterrence beside the rate limit, and it is not authentication. Do not treat it as securing the endpoint.', 'bugbottle' ); ?></p>
							<p class="description"><?php esc_html_e( 'The bundled panel signs what it sends, so filling this in is all there is to it. A report sent by anything else — your own form, a script — has to sign the same way.', 'bugbottle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bugbottle-email"><?php esc_html_e( 'Email recipient', 'bugbottle' ); ?></label></th>
						<td>
							<input id="bugbottle-email" type="email" class="regular-text" name="bugbottle_settings[email_recipient]" value="<?php echo esc_attr( $s['email_recipient'] ); ?>">
							<p class="description"><?php esc_html_e( 'Leave empty to only store reports in wp-admin.', 'bugbottle' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email on submit', 'bugbottle' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bugbottle_settings[email_on_submit]" value="1" <?php checked( $s['email_on_submit'] ); ?>>
								<?php esc_html_e( 'Send the email as soon as a report arrives', 'bugbottle' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function locale_label( string $code ): string {
		$labels = array(
			'auto' => __( 'Auto (site language)', 'bugbottle' ),
			'da'   => __( 'Danish', 'bugbottle' ),
			'en'   => __( 'English', 'bugbottle' ),
			'sv'   => __( 'Swedish', 'bugbottle' ),
			'nb'   => __( 'Norwegian', 'bugbottle' ),
			'de'   => __( 'German', 'bugbottle' ),
			'nl'   => __( 'Dutch', 'bugbottle' ),
			'fr'   => __( 'French', 'bugbottle' ),
			'es'   => __( 'Spanish', 'bugbottle' ),
		);
		return $labels[ $code ] ?? $code;
	}

	private static function contact_label( string $mode ): string {
		$labels = array(
			self::CONTACT_OFF      => __( 'Do not ask', 'bugbottle' ),
			self::CONTACT_OPTIONAL => __( 'Ask, but let them send without it', 'bugbottle' ),
			self::CONTACT_REQUIRED => __( 'Ask, and refuse to send without it', 'bugbottle' ),
		);
		return $labels[ $mode ] ?? $mode;
	}

	private static function position_label( string $position ): string {
		$labels = array(
			'bottom-right' => __( 'Bottom right', 'bugbottle' ),
			'bottom-left'  => __( 'Bottom left', 'bugbottle' ),
			'top-right'    => __( 'Top right', 'bugbottle' ),
			'top-left'     => __( 'Top left', 'bugbottle' ),
		);
		return $labels[ $position ] ?? $position;
	}
}
