<?php
/**
 * Staging Protection Handler
 *
 * Handles robots.txt modification and crawler blocking on staging domains.
 *
 * @package BigStormStaging
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staging Protection class
 */
class Big_Storm_Staging_Protection {
	/**
	 * Reference to admin settings instance
	 *
	 * @var Big_Storm_Admin_Settings
	 */
	private $settings;

	/**
	 * Constructor
	 *
	 * @param Big_Storm_Admin_Settings $settings Admin settings instance.
	 */
	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'robots_txt', array( $this, 'modify_robots_txt' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_send_410_for_crawlers' ), 0 );
	}

	/**
	 * Get the current request host, normalized.
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
	 * Check if current domain is a staging domain.
	 *
	 * @return bool
	 */
	public function is_staging_domain() {
		$host = $this->get_current_host();
		if ( '' === $host ) {
			return false;
		}
		$suffix = $this->settings->get_staging_suffix();
		if ( '' === $suffix ) {
			return false;
		}
		// Suffix-style match when it begins with a dot; otherwise exact host match.
		if ( '.' === $suffix[0] ) {
			$pattern = '/' . preg_quote( $suffix, '/' ) . '$/i';
			return (bool) preg_match( $pattern, $host );
		}
		return ( 0 === strcasecmp( $host, $suffix ) );
	}

	/**
	 * Modify robots.txt content for staging domains.
	 *
	 * @param string $output The robots.txt output.
	 * @param bool   $public Whether the site is considered "public".
	 * @return string Modified robots.txt content.
	 */
	public function modify_robots_txt( $output, $public ) {
		// Only modify if this is a staging domain and robots blocking is enabled.
		if ( $this->is_staging_domain() && $this->settings->is_robots_blocking_enabled() ) {
			// Replace with a deny all directive
			$output = "User-agent: *\nDisallow: /\n";
			
			// Add a comment to make it clear this was modified
			$output .= "\n# Modified by Big Storm Staging Plugin - " . date( 'Y-m-d' );
		}
		
		return $output;
	}

	/**
	 * Determine if the current request appears to come from a search crawler.
	 *
	 * @return bool
	 */
	private function is_search_crawler() {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
		if ( '' === $user_agent ) {
			return false;
		}

		// Allowlist: explicitly permit certain known non-search bots.
		$allowlist = array(
			// Plesk screenshot bot
			'plesk screenshot bot',
			// Utilities
			'MxToolbox',
			// Monitoring services
			'StatusCake',
			'uptimerobot',
			'pingdom',
		);

		/**
		 * Filter the list of allowed bot identifiers.
		 *
		 * @since 1.0.3
		 *
		 * @param string[] $allowlist  Array of substrings to match against the User-Agent.
		 * @param string   $user_agent The full (lowercased) User-Agent string.
		 */
		$allowlist = apply_filters( 'bigstorm_stage_crawler_allowlist', $allowlist, $user_agent );
		foreach ( (array) $allowlist as $ok ) {
			$ok = strtolower( (string) $ok );
			if ( '' !== $ok && false !== strpos( $user_agent, $ok ) ) {
				return false;
			}
		}

		$crawlers = array(
			'googlebot',
			'google-inspectiontool',
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
	 * Send HTTP 410 response to crawlers on staging domains.
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
