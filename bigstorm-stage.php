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
		// Before templates render, potentially return 410 for crawler requests on staging.
		add_action( 'template_redirect', array( $this, 'maybe_send_410_for_crawlers' ), 0 );
	}

	/**
	 * Get the current request host, normalized to lowercase and without port.
	 *
	 * @return string
	 */
	private function get_current_host() {
		$host = '';
		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$host = (string) $_SERVER['HTTP_HOST'];
		} elseif ( ! empty( $_SERVER['SERVER_NAME'] ) ) {
			$host = (string) $_SERVER['SERVER_NAME'];
		}

		// Strip port if present and normalize case.
		$host = strtolower( preg_replace( '/:\\d+$/', '', $host ) );
		return $host;
	}

	/**
	 * Check if current domain is a staging domain
	 *
	 * @return bool True if current domain ends with .greatbigstorm.com
	 */
	public function is_staging_domain() {
		$host = $this->get_current_host();
		if ( '' === $host ) {
			return false;
		}
		// Ensure the host truly ends with .greatbigstorm.com
		return (bool) preg_match( '/\.greatbigstorm\.com$/i', $host );
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

	/**
	 * Determine if the current request appears to come from a search crawler.
	 *
	 * Uses a filterable list of known crawler user agent substrings and a
	 * conservative fallback regex for common crawler terms.
	 *
	 * @return bool
	 */
	private function is_search_crawler() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $user_agent ) {
			return false;
		}

		$crawlers = array(
			'googlebot',
			'bingbot',
			'slurp',        // Yahoo
			'duckduckbot',
			'baiduspider',
			'yandexbot',
			'ahrefsbot',
			'semrushbot',
			'applebot',
			'petalbot',
			'sogou',
			'seznambot',
			'dotbot',
			'mj12bot',
			'yeti',         // Naver
			'gigabot',
			'rogerbot',
		);

		/**
		 * Filter the list of known crawler identifiers.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $crawlers   Array of substrings to match against the User-Agent.
		 * @param string   $user_agent The full (lowercased) User-Agent string.
		 */
		$crawlers = apply_filters( 'bigstorm_stage_crawlers', $crawlers, $user_agent );

		foreach ( (array) $crawlers as $needle ) {
			$needle = strtolower( (string) $needle );
			if ( '' !== $needle && false !== strpos( $user_agent, $needle ) ) {
				return true;
			}
		}

		// Conservative fallback regex for common crawler terms.
		return (bool) preg_match( '/\b(bot|crawl|crawler|spider|slurp|fetch|mediapartners)\b/i', $user_agent );
	}

	/**
	 * If on a staging domain and the requester is a known crawler, return HTTP 410.
	 *
	 * Skips admin, AJAX, and robots.txt so crawlers can still read the Disallow rules.
	 * Applies only to GET and HEAD requests on the front-end.
	 *
	 * @return void
	 */
	public function maybe_send_410_for_crawlers() {
		// Only act on staging.
		if ( ! $this->is_staging_domain() ) {
			return;
		}

		// Skip admin and AJAX contexts.
		if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return;
		}

		// Allow robots.txt to be served so crawlers can see Disallow rules.
		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return;
		}

		// Only for GET/HEAD page-like requests.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		if ( ! $this->is_search_crawler() ) {
			return;
		}

		// Send 410 Gone response and stop execution.
		nocache_headers();
		status_header( 410 );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		echo '<!doctype html><html><head><meta charset="' . esc_attr( get_bloginfo( 'charset' ) ) . '"><title>410 Gone</title></head><body><h1>410 Gone</h1><p>This staging URL is not available to search crawlers.</p></body></html>';
		exit;
	}
}

// Initialize the plugin
$big_storm_staging = new Big_Storm_Staging();
$big_storm_staging->init();
