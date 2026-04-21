<?php
/**
 * Plugin Name:       BeaverMind
 * Plugin URI:        https://dependentmedia.com/beavermind
 * Description:       AI-powered design automation for Beaver Builder. Uses the Claude API to compose pages from a curated library of Beaver Builder fragments.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dependent Media
 * Author URI:        https://dependentmedia.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beavermind
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BEAVERMIND_VERSION', '0.1.0' );
define( 'BEAVERMIND_FILE', __FILE__ );
define( 'BEAVERMIND_DIR', plugin_dir_path( __FILE__ ) );
define( 'BEAVERMIND_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVERMIND_BASENAME', plugin_basename( __FILE__ ) );

if ( file_exists( BEAVERMIND_DIR . 'vendor/autoload.php' ) ) {
	require_once BEAVERMIND_DIR . 'vendor/autoload.php';
}

require_once BEAVERMIND_DIR . 'includes/class-beavermind.php';

register_activation_hook( __FILE__, array( 'BeaverMind\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BeaverMind\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'BeaverMind\\Plugin', 'instance' ) );
