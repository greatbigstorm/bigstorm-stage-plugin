<?php
/**
 * GitHub Update Handler
 *
 * Handles all GitHub-based update functionality including version checking,
 * downloading updates, and normalizing folder names.
 *
 * @package BigStormStaging
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub Updater class
 */
class Big_Storm_GitHub_Updater {
	/**
	 * Plugin slug
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * GitHub repository (owner/repo format)
	 *
	 * @var string
	 */
	private $github_repo;

	/**
	 * Cache key for update metadata
	 *
	 * @var string
	 */
	private $cache_key_update;

	/**
	 * Plugin file path
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Constructor
	 *
	 * @param string $slug        Plugin slug.
	 * @param string $github_repo GitHub repository in owner/repo format.
	 * @param string $plugin_file Main plugin file path.
	 */
	public function __construct( $slug, $github_repo, $plugin_file ) {
		$this->slug             = $slug;
		$this->github_repo      = $github_repo;
		$this->plugin_file      = $plugin_file;
		$this->cache_key_update = 'bigstorm_stage_update_meta';
	}

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'upgrader_process_complete', array( $this, 'clear_update_cache' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'maybe_rename_github_source' ), 10, 4 );
	}

	/**
	 * Check GitHub for a newer release and inject into the update transient.
	 *
	 * @param stdClass $transient Update transient object.
	 * @return stdClass
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient ) || ! isset( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->get_remote_release();
		if ( ! $remote || empty( $remote['version'] ) || empty( $remote['download_url'] ) ) {
			return $transient;
		}

		$current_version = $this->get_current_version();
		if ( ! $current_version || ! $this->is_newer_version( $remote['version'], $current_version ) ) {
			return $transient;
		}

		$plugin_file = plugin_basename( $this->plugin_file );
		$update              = new stdClass();
		$update->slug        = $this->slug;
		$update->plugin      = $plugin_file;
		$update->new_version = $remote['version'];
		$update->url         = 'https://github.com/' . $this->github_repo;
		$update->package     = $remote['download_url'];

		$transient->response[ $plugin_file ] = $update;
		return $transient;
	}

	/**
	 * Clear cached update data after upgrades.
	 *
	 * @param WP_Upgrader $upgrader Upgrader instance.
	 * @param array       $options  Upgrade options.
	 * @return void
	 */
	public function clear_update_cache( $upgrader, $options ) {
		delete_site_transient( $this->cache_key_update );
	}

	/**
	 * Ensure GitHub unzipped folder name matches the plugin folder.
	 *
	 * WordPress expects the plugin folder to match the existing name, but GitHub
	 * creates folders like "{repo}-{sha}". This renames the source during upgrade.
	 *
	 * @param string      $source        File source location.
	 * @param string      $remote_source Remote file source location.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra hook data.
	 * @return string
	 */
	public function maybe_rename_github_source( $source, $remote_source, $upgrader, $hook_extra ) {
		// Only act if this is our plugin being updated.
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( $this->plugin_file ) !== $hook_extra['plugin'] ) {
			return $source;
		}

		// Only act if we have a valid directory to inspect.
		if ( empty( $source ) || ! is_dir( $source ) ) {
			return $source;
		}

		$source = trailingslashit( $source );

		// Proactively remove files we don't want shipped via updates.
		foreach ( array( '.gitignore', 'package.sh' ) as $unwanted ) {
			$unwanted_path = $source . $unwanted;
			if ( file_exists( $unwanted_path ) && is_file( $unwanted_path ) ) {
				@unlink( $unwanted_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		$expected_dirname = dirname( plugin_basename( $this->plugin_file ) );
		$current_basename = basename( untrailingslashit( $source ) );
		if ( $current_basename === $expected_dirname ) {
			return $source; // Already correct.
		}

		$parent = trailingslashit( dirname( untrailingslashit( $source ) ) );
		$target = $parent . $expected_dirname . '/';

		// Remove any prior temp dir with our expected name to prevent rename collisions.
		if ( is_dir( $target ) ) {
			if ( function_exists( 'wp_delete_folder' ) ) {
				wp_delete_folder( $target );
			} else {
				// Best-effort cleanup; ignore failures.
				@rmdir( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		// Attempt to rename inside the upgrade temp directory.
		$renamed = @rename( untrailingslashit( $source ), untrailingslashit( $target ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $renamed ) {
			return $target;
		}

		// If rename fails for any reason, fall back to the original source.
		return $source;
	}

	/**
	 * Get current plugin version from header.
	 *
	 * @return string|null
	 */
	private function get_current_version() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $this->plugin_file, false, false );
		return isset( $data['Version'] ) ? $data['Version'] : null;
	}

	/**
	 * Compare semantic versions (supports prefixed 'v').
	 *
	 * @param string $remote Remote version.
	 * @param string $local  Local version.
	 * @return bool True if remote is newer.
	 */
	private function is_newer_version( $remote, $local ) {
		$remote = ltrim( (string) $remote, 'vV' );
		$local  = ltrim( (string) $local, 'vV' );
		return version_compare( $remote, $local, '>' );
	}

	/**
	 * Fetch and cache latest GitHub release/tag.
	 *
	 * @return array|null { version, download_url, published_at }
	 */
	public function get_remote_release() {
		$cached = get_site_transient( $this->cache_key_update );
		if ( is_array( $cached ) && isset( $cached['version'] ) ) {
			return $cached;
		}

		$remote = null;

		// Try latest release endpoint.
		$release = $this->github_api_get( 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest' );
		if ( $release && isset( $release['tag_name'] ) ) {
			$download = isset( $release['zipball_url'] ) ? $release['zipball_url'] : null;
			if ( isset( $release['assets'] ) && is_array( $release['assets'] ) ) {
				foreach ( $release['assets'] as $asset ) {
					if ( isset( $asset['browser_download_url'] ) && is_string( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['browser_download_url'] ) ) {
						$download = $asset['browser_download_url'];
						break;
					}
				}
			}
			$remote = array(
				'version'      => $release['tag_name'],
				'download_url' => $download,
				'published_at' => isset( $release['published_at'] ) ? $release['published_at'] : null,
			);
		}

		// Fallback to tags.
		if ( ! $remote ) {
			$tags = $this->github_api_get( 'https://api.github.com/repos/' . $this->github_repo . '/tags' );
			if ( is_array( $tags ) && ! empty( $tags ) && isset( $tags[0]['name'] ) ) {
				$remote = array(
					'version'      => $tags[0]['name'],
					'download_url' => isset( $tags[0]['zipball_url'] ) ? $tags[0]['zipball_url'] : null,
					'published_at' => null,
				);
			}
		}

		if ( $remote && ! empty( $remote['version'] ) ) {
			set_site_transient( $this->cache_key_update, $remote, 6 * HOUR_IN_SECONDS );
		}

		return $remote;
	}

	/**
	 * Minimal GitHub API GET helper.
	 *
	 * @param string $url API endpoint URL.
	 * @return array|null Decoded JSON or null on failure.
	 */
	public function github_api_get( $url ) {
		$args = array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
		);
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( 200 !== (int) $code || empty( $body ) ) {
			return null;
		}
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Fetch a file's raw contents from GitHub at a specific ref/tag.
	 *
	 * @param string $path Path within the repo (e.g., readme.txt).
	 * @param string $ref  Git ref (tag/branch/commit SHA).
	 * @return string|null Raw file content or null on failure.
	 */
	public function fetch_github_file_at_ref( $path, $ref ) {
		$endpoint = 'https://api.github.com/repos/' . $this->github_repo . '/contents/' . str_replace( '%2F', '/', rawurlencode( $path ) ) . '?ref=' . rawurlencode( $ref );
		$data     = $this->github_api_get( $endpoint );
		if ( ! is_array( $data ) || empty( $data['content'] ) || empty( $data['encoding'] ) ) {
			return null;
		}
		if ( 'base64' === strtolower( (string) $data['encoding'] ) ) {
			$raw = base64_decode( (string) $data['content'] );
			return is_string( $raw ) ? $raw : null;
		}
		return null;
	}

	/**
	 * Retrieve release notes from GitHub for a specific tag.
	 *
	 * @param string $tag The GitHub release tag name.
	 * @return string|null HTML for changelog or null if not available.
	 */
	public function get_release_notes_html( $tag ) {
		$tag = trim( (string) $tag );
		if ( '' === $tag ) {
			return null;
		}
		$endpoint = 'https://api.github.com/repos/' . $this->github_repo . '/releases/tags/' . rawurlencode( $tag );
		$data = $this->github_api_get( $endpoint );
		if ( ! $data ) {
			// Try toggling the "v" prefix if the tag wasn't found.
			if ( 0 === strpos( $tag, 'v' ) || 0 === strpos( $tag, 'V' ) ) {
				$alt = ltrim( $tag, 'vV' );
			} else {
				$alt = 'v' . $tag;
			}
			$data = $this->github_api_get( 'https://api.github.com/repos/' . $this->github_repo . '/releases/tags/' . rawurlencode( $alt ) );
		}
		if ( ! $data || empty( $data['body'] ) ) {
			return null;
		}
		$body = (string) $data['body'];
		// Render the markdown body as plain text paragraphs.
		$html = wp_kses_post( wpautop( esc_html( $body ) ) );
		// Add a link to the release page.
		if ( ! empty( $data['html_url'] ) ) {
			$html .= '<p><a href="' . esc_url( $data['html_url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View on GitHub', 'bigstorm-stage' ) . '</a></p>';
		}
		return $html;
	}
}
