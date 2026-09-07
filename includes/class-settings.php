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
			'scrub'            => true,
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

		foreach ( array( 'enabled', 'logged_in_only', 'allow_anonymous', 'scrub', 'email_on_submit' ) as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}

		$locale = isset( $input['locale'] ) ? (string) $input['locale'] : 'auto';
		$out['locale'] = in_array( $locale, self::LOCALES, true ) ? $locale : 'auto';

		$position = isset( $input['position'] ) ? (string) $input['position'] : 'bottom-right';
		$out['position'] = in_array( $position, self::POSITIONS, true ) ? $position : 'bottom-right';

		$colour = isset( $input['primary_color'] ) ? sanitize_hex_color( (string) $input['primary_color'] ) : null;
		$out['primary_color'] = $colour ?? self::defaults()['primary_color'];

		$out['brand_name']       = sanitize_text_field( (string) ( $input['brand_name'] ?? '' ) );
		$out['logo_url']         = esc_url_raw( (string) ( $input['logo_url'] ?? '' ) );
		$out['trigger_selector'] = sanitize_text_field( (string) ( $input['trigger_selector'] ?? '' ) );

		$recipient = trim( (string) ( $input['email_recipient'] ?? '' ) );
		$out['email_recipient'] = is_email( $recipient ) ? sanitize_email( $recipient ) : '';

		return $out;
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
						<th scope="row"><?php esc_html_e( 'Scrubbing', 'bugbottle' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bugbottle_settings[scrub]" value="1" <?php checked( $s['scrub'] ); ?>>
								<?php esc_html_e( 'Redact email addresses, tokens, card numbers and query values before the report is sent', 'bugbottle' ); ?>
							</label>
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
