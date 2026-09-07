<?php
/**
 * Plugin Name:       Bugbottle
 * Plugin URI:        https://github.com/mahope/bugbottle-wordpress
 * Description:       In-app bug reports that arrive with the evidence attached. Adds the bugbottle panel to the front end and receives the reports as a private post type in wp-admin.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Mads Holst Jensen
 * Author URI:        https://mahope.dk
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       bugbottle
 * Domain Path:       /languages
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

namespace Bugbottle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';

/**
 * The bugbottle release `assets/bugbottle.js` was copied from. Bump it in the
 * same commit that replaces the file, so the enqueued URL busts caches when
 * the bundle changes and not when the plugin does.
 */
const LIB_VERSION = '0.4.0';

define( 'BUGBOTTLE_FILE', __FILE__ );
define( 'BUGBOTTLE_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUGBOTTLE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Maps `Bugbottle\Some_Thing` to `includes/class-some-thing.php`.
 *
 * There is no Composer autoloader on purpose: the plugin ships as a zip a site
 * owner drops into wp-content/plugins, and a vendor directory in that zip is a
 * liability nobody asked for.
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$file     = BUGBOTTLE_DIR . 'includes/class-' . str_replace( '_', '-', strtolower( $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Wires the plugin up. Everything else is a class that registers its own hooks.
 */
function bootstrap(): void {
	add_action( 'init', array( Storage::class, 'register_post_type' ) );
	add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );
	add_action( 'rest_api_init', array( Rest::class, 'register_routes' ) );
	add_action( 'wp_enqueue_scripts', array( Assets::class, 'enqueue' ) );
	add_action( 'admin_menu', array( Admin::class, 'register_menu' ) );
	add_action( 'admin_init', array( Settings::class, 'register' ) );
	add_action( 'admin_post_bugbottle_set_status', array( Admin::class, 'handle_status_change' ) );
}

/**
 * Loads the shipped translations. `languages/` first, so a site can still
 * override them from `wp-content/languages/plugins/`.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'bugbottle', false, dirname( plugin_basename( BUGBOTTLE_FILE ) ) . '/languages' );
}

/**
 * On activation: create the upload directory with its deny rules, and make
 * sure the post type exists before the first flush.
 */
function activate(): void {
	Storage::register_post_type();
	Storage::ensure_upload_dir();
	flush_rewrite_rules();
}

function deactivate(): void {
	flush_rewrite_rules();
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\\deactivate' );

bootstrap();
