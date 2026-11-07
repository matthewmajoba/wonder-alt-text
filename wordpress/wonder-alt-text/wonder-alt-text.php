<?php
/**
 * Plugin Name:       Wonder Alt-Text
 * Description:       Finds images in your media library that are missing alt-text and backfills them with AI. Plain, accurate descriptions for product and utility images; vivid, story-like descriptions for scenic ones, written in one of five original narrator personas.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Matthew Ballard
 * Plugin URI:        https://github.com/matthewmajoba/wonder-alt-text
 * Author URI:        https://matthewballard.dev
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wonder-alt-text
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WONDER_ALT_VERSION', '0.1.0' );
define( 'WONDER_ALT_FILE', __FILE__ );
define( 'WONDER_ALT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WONDER_ALT_URL', plugin_dir_url( __FILE__ ) );

require_once WONDER_ALT_DIR . 'includes/class-personas.php';
require_once WONDER_ALT_DIR . 'includes/class-settings.php';
require_once WONDER_ALT_DIR . 'includes/class-api-client.php';
require_once WONDER_ALT_DIR . 'includes/class-generator.php';
require_once WONDER_ALT_DIR . 'includes/class-library.php';
require_once WONDER_ALT_DIR . 'includes/class-admin.php';

add_action(
	'plugins_loaded',
	static function () {
		Wonder_Alt_Settings::init();
		Wonder_Alt_Admin::init();
		Wonder_Alt_Library::init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once WONDER_ALT_DIR . 'includes/class-cli.php';
			WP_CLI::add_command( 'wonder-alt', 'Wonder_Alt_CLI' );
		}
	}
);
