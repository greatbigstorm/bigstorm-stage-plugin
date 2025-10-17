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
 * Description:       Adds a "Disallow: /" directive to robots.txt on staging domains ending with .greatbigstorm.com and returns HTTP 410 (Gone) for page requests from known search crawlers. Can be removed once the site is launched to production.
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
	 * Plugin slug used for the modal and filters.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * User meta key for dismissing the remove-plugin admin notice.
	 *
	 * @var string
	 */
	private $dismiss_meta_key = 'bigstorm_stage_dismiss_remove_notice';

	/**
	 * Option name for the staging domain suffix.
	 *
	 * @var string
	 */
	private $option_suffix = 'bigstorm_stage_domain_suffix';

	/**
	 * Initialize the plugin
	 *
	 * @return void
	 */
	public function init() {
		$this->slug = basename( dirname( __FILE__ ) ); // e.g. 'bigstorm-stage'.
		add_filter( 'robots_txt', array( $this, 'modify_robots_txt' ), 10, 2 );
		// Before templates render, potentially return 410 for crawler requests on staging.
		add_action( 'template_redirect', array( $this, 'maybe_send_410_for_crawlers' ), 0 );
		// Add a View details link below the description (meta row), like .org plugins.
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		// Ensure Thickbox is available on the Plugins screen for the modal.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		// Provide data for the modal (like WordPress.org) using the plugins_api filter.
		add_filter( 'plugins_api', array( $this, 'plugins_api_info' ), 10, 3 );

		// Admin notice suggesting removal when not on staging.
		add_action( 'admin_notices', array( $this, 'maybe_show_remove_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'maybe_show_remove_notice' ) );
		add_action( 'admin_init', array( $this, 'handle_dismiss_remove_notice' ) );
		add_action( 'wp_ajax_bigstorm_stage_dismiss', array( $this, 'ajax_dismiss_remove_notice' ) );

		// Settings page to configure the staging domain suffix.
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links_settings' ) );
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
	 * Get the configured staging domain value (normalized). Can be a full domain
	 * like "staging.example.com" or a suffix starting with a dot like ".greatbigstorm.com".
	 *
	 * @return string
	 */
	private function get_staging_suffix() {
		$value = get_option( $this->option_suffix, '.greatbigstorm.com' );
		$value = $this->normalize_suffix( $value );
		if ( '' === $value ) {
			$value = '.greatbigstorm.com';
		}
		return $value;
	}

	/**
	 * Normalize user-provided pattern to a safe domain or suffix value.
	 *
	 * Accepts a URL, a plain domain (e.g., staging.example.com), or a suffix that
	 * starts with a dot (e.g., .greatbigstorm.com). Returns lowercase, trimmed,
	 * without port or path. If value starts with a dot, it is preserved to
	 * indicate suffix matching.
	 *
	 * @param string $value Raw setting input.
	 * @return string Normalized domain or suffix, or empty string on failure.
	 */
	private function normalize_suffix( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		// If a URL is provided, extract host.
		if ( false !== strpos( $value, '://' ) ) {
			$host = parse_url( $value, PHP_URL_HOST );
			$value = ( $host ) ? $host : $value;
		}
		// Remove path if present and strip port.
		$value = preg_replace( '#/.*$#', '', $value );
		$value = preg_replace( '/:\\d+$/', '', $value );
		$value = strtolower( $value );
		$value = rtrim( $value, '.' );
		if ( '' === $value ) {
			return '';
		}
		// Validate and return. Allow either suffix (starts with dot) or full domain.
		if ( '.' === $value[0] ) {
			$domain = ltrim( $value, '.' );
			if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $domain ) ) {
				return '';
			}
			return '.' . $domain;
		}

		// Full domain (no leading dot). Allow single-label too (e.g., localhost) for flexibility.
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $value ) ) {
			return '';
		}
		return $value;
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
		$suffix = $this->get_staging_suffix();
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
	 * Add a "View details" link to the plugin meta row under the description.
	 *
	 * @param string[] $links Meta links.
	 * @param string   $file  Plugin file path relative to plugins directory.
	 * @return string[]
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $links;
		}
		$modal_url   = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . urlencode( $this->slug ) . '&TB_iframe=true&width=600&height=600' );
		$details_link = '<a href="' . esc_url( $modal_url ) . '" class="thickbox open-plugin-details-modal">' . esc_html__( 'View details', 'bigstorm-stage' ) . '</a>';
		$links[] = $details_link;
		return $links;
	}

	/**
	 * Enqueue Thickbox assets on the Plugins screen to support the modal.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook ) {
		if ( 'plugins.php' === $hook ) {
			add_thickbox();
		}

		// If our remove notice would show, add a small inline script to persist dismissals.
		if ( $this->should_show_remove_notice() ) {
			add_action( 'admin_print_footer_scripts', array( $this, 'print_dismiss_script' ) );
		}
	}

	/**
	 * Add Settings link to plugin action links for convenience.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public function plugin_action_links_settings( $links ) {
		$settings_url = self_admin_url( 'options-general.php?page=' . urlencode( $this->slug ) );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'bigstorm-stage' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Register the plugin settings and field.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting( 'bigstorm_stage_settings', $this->option_suffix, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_suffix' ),
			'default'           => '.greatbigstorm.com',
		) );

		add_settings_section(
			'bigstorm_stage_main',
			__( 'Staging Domain', 'bigstorm-stage' ),
			function () {
				echo '<p>' . esc_html__( 'Set the domain or domain suffix that identifies this site as staging. Example values: "staging.example.com" or ".greatbigstorm.com". Default is .greatbigstorm.com', 'bigstorm-stage' ) . '</p>';
			},
			$this->slug
		);

		add_settings_field(
			'bigstorm_stage_domain_suffix_field',
			__( 'Staging domain match', 'bigstorm-stage' ),
			array( $this, 'render_suffix_field' ),
			$this->slug,
			'bigstorm_stage_main'
		);
	}

	/**
	 * Sanitize callback for the domain suffix setting.
	 *
	 * @param string $value Raw input.
	 * @return string Sanitized value or default.
	 */
	public function sanitize_suffix( $value ) {
		$normalized = $this->normalize_suffix( $value );
		return $normalized;
	}

	/**
	 * Add the settings page under Settings.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Big Storm Staging', 'bigstorm-stage' ),
			__( 'Big Storm Staging', 'bigstorm-stage' ),
			'manage_options',
			$this->slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( 'bigstorm_stage_settings' ); ?>
				<?php do_settings_sections( $this->slug ); ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the input field for the domain suffix.
	 *
	 * @return void
	 */
	public function render_suffix_field() {
		$value = get_option( $this->option_suffix, '' );
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( $this->option_suffix ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder=".greatbigstorm.com" />
		<p class="description"><?php echo esc_html__( 'Enter a full domain (e.g., "staging.example.com") for an exact match, or a suffix starting with a dot (e.g., ".greatbigstorm.com") to match any host that ends with it.', 'bigstorm-stage' ); ?></p>
		<?php
	}

	/**
	 * Print a tiny inline script to persist dismissal on the X click for our notice.
	 *
	 * @return void
	 */
	public function print_dismiss_script() {
		?>
		<script>
		(function(){
			document.addEventListener('click', function(e){
				var closeBtn = e.target.closest('#bigstorm-stage-remove-notice .notice-dismiss');
				if (!closeBtn) return;
				var wrap = document.getElementById('bigstorm-stage-remove-notice');
				if (!wrap) return;
				var nonce = wrap.getAttribute('data-nonce');
				if (!nonce) return;
				var data = new window.FormData();
				data.append('action', 'bigstorm_stage_dismiss');
				data.append('security', nonce);
				fetch(ajaxurl, {method: 'POST', credentials: 'same-origin', body: data});
			}, true);
		})();
		</script>
		<?php
	}

	/**
	 * Provide plugin information for the details modal via the plugins_api filter.
	 *
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The type of information being requested from the Plugin Install API.
	 * @param object             $args   Plugin API arguments.
	 * @return object|false
	 */
	public function plugins_api_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$info            = new stdClass();
		$info->name      = 'Big Storm Staging';
		$info->slug      = $this->slug;
		$info->version   = '1.0.0';
		$info->author    = '<a href="https://www.greatbigstorm.com">Big Storm</a>';
		$info->homepage  = 'https://www.greatbigstorm.com';
		$info->requires  = '5.2';
		$info->tested    = '6.4';
		$info->requires_php = '7.2';
		$info->last_updated  = date_i18n( 'Y-m-d' );

		$sections = $this->load_readme_sections();
		if ( empty( $sections ) ) {
			$sections = array(
				'description'  => wp_kses_post( wpautop( 'Adds a "Disallow: /" directive to robots.txt on staging domains ending with .greatbigstorm.com and returns HTTP 410 (Gone) for page requests from known search crawlers. Can be removed once the site is launched to production.' ) ),
				'installation' => wp_kses_post( wpautop( "1. Upload the plugin folder to /wp-content/plugins/\n2. Activate the plugin in WordPress\n3. No configuration needed" ) ),
				'changelog'    => wp_kses_post( wpautop( "= 1.0.0 =\n* Initial release" ) ),
			);
		}
		$info->sections = $sections; // array of 'description','installation','faq','changelog', etc.

		return $info;
	}

	/**
	 * Load and lightly parse readme.txt into modal sections.
	 *
	 * @return array<string,string> Sections keyed by description/install/faq/changelog.
	 */
	private function load_readme_sections() {
		$readme_path = plugin_dir_path( __FILE__ ) . 'readme.txt';
		if ( ! file_exists( $readme_path ) ) {
			return array();
		}
		$raw = @file_get_contents( $readme_path );
		if ( false === $raw || '' === $raw ) {
			return array();
		}

		$map = array(
			'description'  => 'Description',
			'installation' => 'Installation',
			'faq'          => 'Frequently Asked Questions',
			'changelog'    => 'Changelog',
			'other_notes'  => 'Upgrade Notice',
		);

		$sections = array();
		foreach ( $map as $key => $heading ) {
			$pattern = '/==\s*' . preg_quote( $heading, '/' ) . '\s*==\s*(.+?)(?:\n==\s*[^=]+\s*==|\z)/si';
			if ( preg_match( $pattern, $raw, $m ) ) {
				$sections[ $key ] = wp_kses_post( wpautop( trim( $m[1] ) ) );
			}
		}

		return $sections;
	}

	/**
	 * Determine if the remove-plugin notice should be shown on this request.
	 *
	 * @return bool
	 */
	private function should_show_remove_notice() {
		if ( ! is_admin() || ! is_user_logged_in() ) {
			return false;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}
		if ( $this->is_staging_domain() ) {
			return false;
		}
		if ( get_user_meta( get_current_user_id(), $this->dismiss_meta_key, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Handle dismissal of the remove-plugin notice via nonce-protected link.
	 *
	 * @return void
	 */
	public function handle_dismiss_remove_notice() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( empty( $_GET['bigstorm_stage_dismiss'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'bigstorm_stage_dismiss' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		update_user_meta( get_current_user_id(), $this->dismiss_meta_key, 1 );
		// Redirect to remove the query args.
		wp_safe_redirect( remove_query_arg( array( 'bigstorm_stage_dismiss', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * AJAX: Persist dismissal when clicking the core notice dismiss (X) button.
	 *
	 * @return void
	 */
	public function ajax_dismiss_remove_notice() {
		if ( ! is_user_logged_in() || ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'bigstorm_stage_dismiss', 'security' );
		update_user_meta( get_current_user_id(), $this->dismiss_meta_key, 1 );
		wp_send_json_success();
	}

	/**
	 * Show a dismissible admin notice suggesting plugin removal when not on staging.
	 *
	 * @return void
	 */
	public function maybe_show_remove_notice() {
		if ( ! $this->should_show_remove_notice() ) {
			return;
		}

		$basename       = plugin_basename( __FILE__ );
		$deactivate_url = wp_nonce_url( self_admin_url( 'plugins.php?action=deactivate&plugin=' . urlencode( $basename ) ), 'deactivate-plugin_' . $basename );
		$plugins_url    = self_admin_url( 'plugins.php?s=' . rawurlencode( 'Big Storm Staging' ) );
		$nonce         = wp_create_nonce( 'bigstorm_stage_dismiss' );
		$settings_url   = self_admin_url( 'options-general.php?page=' . urlencode( $this->slug ) );

		$notice  = '<div id="bigstorm-stage-remove-notice" class="notice notice-warning is-dismissible" data-nonce="' . esc_attr( $nonce ) . '">';
		$notice .= '<p><strong>' . esc_html__( 'Big Storm Staging', 'bigstorm-stage' ) . ':</strong> ' . esc_html__( 'This site does not appear to be on a staging domain.', 'bigstorm-stage' ) . ' ';
		$notice .= sprintf(
			/* translators: %s: settings screen link */
			esc_html__( 'If you use a different staging host, set it in %s.', 'bigstorm-stage' ),
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings → Big Storm Staging', 'bigstorm-stage' ) . '</a>'
		);
		$notice .= '</p>';
		$notice .= '<p><strong>' . esc_html__( 'You can safely remove this plugin on production.', 'bigstorm-stage' ) . '</strong></p>';
		$notice .= '<p>';
		$notice .= '<a class="button button-secondary" href="' . esc_url( $deactivate_url ) . '">' . esc_html__( 'Deactivate now', 'bigstorm-stage' ) . '</a> | ';
		$notice .= '<a class="button-link" href="' . esc_url( $plugins_url ) . '">' . esc_html__( 'Open Plugins page', 'bigstorm-stage' ) . '</a> | ';
		$notice .= '<a class="button-link" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Open Settings', 'bigstorm-stage' ) . '</a>';
		$notice .= '</p>';
		$notice .= '</div>';

		echo wp_kses_post( $notice );
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
