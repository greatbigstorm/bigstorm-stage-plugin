<?php
/**
 * Plugin Modal Handler
 *
 * Handles the plugin information modal (View Details) and readme parsing.
 *
 * @package BigStormStaging
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Modal class
 */
class Big_Storm_Plugin_Modal {
	/**
	 * Plugin slug
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Plugin file path
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Reference to GitHub updater instance
	 *
	 * @var Big_Storm_GitHub_Updater
	 */
	private $github_updater;

	/**
	 * Constructor
	 *
	 * @param string                   $slug           Plugin slug.
	 * @param string                   $plugin_file    Main plugin file path.
	 * @param Big_Storm_GitHub_Updater $github_updater GitHub updater instance.
	 */
	public function __construct( $slug, $plugin_file, $github_updater ) {
		$this->slug           = $slug;
		$this->plugin_file    = $plugin_file;
		$this->github_updater = $github_updater;
	}

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_thickbox' ) );
		add_filter( 'plugins_api', array( $this, 'plugins_api_info' ), 10, 3 );
	}

	/**
	 * Add "View details" link to plugin meta row.
	 *
	 * @param string[] $links Meta links.
	 * @param string   $file  Plugin file path relative to plugins directory.
	 * @return string[]
	 */
	public function plugin_row_meta( $links, $file ) {
		if ( plugin_basename( $this->plugin_file ) !== $file ) {
			return $links;
		}

		// Avoid duplication: if an update is available, core already shows a View details link.
		$plugin_file   = plugin_basename( $this->plugin_file );
		$updates       = get_site_transient( 'update_plugins' );
		$has_core_link = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) && isset( $updates->response[ $plugin_file ] ) );
		if ( $has_core_link ) {
			return $links;
		}

		$modal_url   = self_admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . urlencode( $this->slug ) . '&TB_iframe=true&width=600&height=600' );
		$details_link = '<a href="' . esc_url( $modal_url ) . '" class="thickbox open-plugin-details-modal">' . esc_html__( 'View details', 'bigstorm-stage' ) . '</a>';
		$links[] = $details_link;
		return $links;
	}

	/**
	 * Enqueue Thickbox assets on the Plugins screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_thickbox( $hook ) {
		if ( 'plugins.php' === $hook ) {
			add_thickbox();
		}
	}

	/**
	 * Provide plugin information for the details modal.
	 *
	 * @param false|object|array $result The result object or array.
	 * @param string             $action The type of information being requested.
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

		$remote          = $this->github_updater->get_remote_release();
		$version         = $remote && ! empty( $remote['version'] ) ? $remote['version'] : $this->get_current_version();

		$info            = new stdClass();
		$info->name      = 'Big Storm Staging';
		$info->slug      = $this->slug;
		$info->version   = $version;
		$info->author    = '<a href="https://www.greatbigstorm.com">Big Storm</a>';
		$info->homepage  = 'https://github.com/greatbigstorm/bigstorm-stage-plugin';
		$info->requires  = '5.2';
		$info->tested    = '6.4';
		$info->requires_php = '7.2';
		$info->last_updated  = $remote && ! empty( $remote['published_at'] ) ? gmdate( 'Y-m-d', strtotime( $remote['published_at'] ) ) : date_i18n( 'Y-m-d' );

		$sections = $this->load_readme_sections();

		// If a requested version exists, prefer tag-specific content.
		$requested_version = ( isset( $args->version ) && is_string( $args->version ) ) ? trim( $args->version ) : '';
		$target_tag        = $requested_version ?: ( $remote['version'] ?? '' );
		if ( $target_tag ) {
			$tag_sections = $this->get_tag_readme_sections( $target_tag );
			if ( ! empty( $tag_sections ) ) {
				$sections = $tag_sections;
			} else {
				// Fallback: use release notes as changelog.
				$notes_html = $this->github_updater->get_release_notes_html( $target_tag );
				if ( $notes_html ) {
					$sections['changelog'] = $notes_html;
				}
			}
		}
		if ( empty( $sections ) ) {
			$sections = array(
				'description'  => wp_kses_post( wpautop( 'Adds a "Disallow: /" directive to robots.txt on staging domains ending with .greatbigstorm.com and returns HTTP 410 (Gone) for page requests from known search crawlers.' ) ),
				'installation' => wp_kses_post( wpautop( "1. Upload the plugin folder to /wp-content/plugins/\n2. Activate the plugin in WordPress\n3. No configuration needed" ) ),
				'changelog'    => wp_kses_post( wpautop( "= 1.0.0 =\n* Initial release" ) ),
			);
		}
		$info->sections = $sections;

		// Optional: download link for modal.
		if ( $remote && ! empty( $remote['download_url'] ) ) {
			$info->download_link = $remote['download_url'];
		}

		return $info;
	}

	/**
	 * Get current plugin version from header.
	 *
	 * @return string
	 */
	private function get_current_version() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $this->plugin_file, false, false );
		return isset( $data['Version'] ) ? $data['Version'] : '1.0.5';
	}

	/**
	 * Try to load README sections from GitHub for a given tag.
	 *
	 * @param string $tag Tag name.
	 * @return array Sections array or empty array on failure.
	 */
	private function get_tag_readme_sections( $tag ) {
		$tag = trim( (string) $tag );
		if ( '' === $tag ) {
			return array();
		}

		$cache_key = 'bigstorm_stage_tag_readme_' . md5( strtolower( $tag ) );
		$cached    = get_site_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Try readme.txt first with tag and toggled v-prefix.
		$raw_txt = $this->github_updater->fetch_github_file_at_ref( 'readme.txt', $tag );
		if ( null === $raw_txt ) {
			$alt = ( 0 === stripos( $tag, 'v' ) ) ? ltrim( $tag, 'vV' ) : 'v' . $tag;
			$raw_txt = $this->github_updater->fetch_github_file_at_ref( 'readme.txt', $alt );
		}

		if ( is_string( $raw_txt ) && '' !== $raw_txt ) {
			$sections = $this->parse_wp_readme_sections( $raw_txt );
			if ( ! empty( $sections ) ) {
				set_site_transient( $cache_key, $sections, 6 * HOUR_IN_SECONDS );
				return $sections;
			}
		}

		// Fallback: README.md
		$raw_md = $this->github_updater->fetch_github_file_at_ref( 'README.md', $tag );
		if ( null === $raw_md ) {
			$alt = ( 0 === stripos( $tag, 'v' ) ) ? ltrim( $tag, 'vV' ) : 'v' . $tag;
			$raw_md = $this->github_updater->fetch_github_file_at_ref( 'README.md', $alt );
		}

		if ( is_string( $raw_md ) && '' !== $raw_md ) {
			$desc = wp_kses_post( wpautop( esc_html( $raw_md ) ) );
			$sections = array( 'description' => $desc );
			set_site_transient( $cache_key, $sections, 6 * HOUR_IN_SECONDS );
			return $sections;
		}

		return array();
	}

	/**
	 * Load and parse readme.txt into modal sections.
	 *
	 * @return array Sections keyed by description/installation/faq/changelog.
	 */
	private function load_readme_sections() {
		$readme_path = plugin_dir_path( $this->plugin_file ) . 'readme.txt';
		if ( ! file_exists( $readme_path ) ) {
			return array();
		}
		$raw = @file_get_contents( $readme_path );
		if ( false === $raw || '' === $raw ) {
			return array();
		}

		return $this->parse_wp_readme_sections( $raw );
	}

	/**
	 * Parse a WordPress-style readme.txt into sections.
	 *
	 * @param string $raw The raw readme.txt contents.
	 * @return array Sections array.
	 */
	private function parse_wp_readme_sections( $raw ) {
		$map = array(
			'description'  => 'Description',
			'installation' => 'Installation',
			'faq'          => 'Frequently Asked Questions',
			'changelog'    => 'Changelog',
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
}
