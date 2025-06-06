<?php
/**
 * Big Storm Staging
 *
 * @package           BigStormStaging
 * @author            Big Storm
 * @copyright         2025 Big Storm
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Big Storm Staging
 * Plugin URI:        https://www.greatbigstorm.com
 * Description:       Adds a "Disallow: /" directive to robots.txt on staging domains ending with .greatbigstorm.com. Can be removed once the site is launched to production.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Big Storm
 * Author URI:        https://www.greatbigstorm.com
 * Text Domain:       bigstorm-stage
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class
 */
class Big_Storm_Staging {

	/**
	 * Initialize the plugin
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'robots_txt', array( $this, 'modify_robots_txt' ), 10, 2 );
	}

	/**
	 * Check if current domain is a staging domain
	 *
	 * @return bool True if current domain ends with .greatbigstorm.com
	 */
	public function is_staging_domain() {
		$current_domain = $_SERVER['HTTP_HOST'];
		return ( strpos( $current_domain, '.greatbigstorm.com' ) !== false );
	}

	/**
	 * Modify robots.txt content for staging domains
	 *
	 * @param string $output The robots.txt output.
	 * @param bool   $public Whether the site is considered "public".
	 * @return string Modified robots.txt content
	 */
	public function modify_robots_txt( $output, $public ) {
		// Only modify if this is a staging domain
		if ( $this->is_staging_domain() ) {
			// Replace the existing robots.txt content with a deny all directive
			$output = "User-agent: *\nDisallow: /\n";
			
			// Add a comment to make it clear this was modified by our plugin
			$output .= "\n# Modified by Big Storm Staging Plugin - " . date( 'Y-m-d' );
		}
		
		return $output;
	}
}

// Initialize the plugin
$big_storm_staging = new Big_Storm_Staging();
$big_storm_staging->init();
