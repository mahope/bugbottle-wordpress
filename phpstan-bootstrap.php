<?php
/**
 * Constants PHPStan cannot see, because they are defined at runtime by the
 * plugin's main file (or by WordPress itself before the plugin loads).
 *
 * Analysis only. Nothing requires this at runtime.
 *
 * @package Bugbottle
 */

declare( strict_types=1 );

define( 'ABSPATH', '/wordpress/' );
define( 'BUGBOTTLE_FILE', '/wordpress/wp-content/plugins/bugbottle/bugbottle.php' );
define( 'BUGBOTTLE_DIR', '/wordpress/wp-content/plugins/bugbottle/' );
define( 'BUGBOTTLE_URL', 'https://example.test/wp-content/plugins/bugbottle/' );
define( 'WP_CONTENT_DIR', '/wordpress/wp-content' );
define( 'HOUR_IN_SECONDS', 3600 );
