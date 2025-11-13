<?php
/**
 * Admin Settings Handler
 *
 * Manages the plugin settings page and domain suffix configuration.
 *
 * @package BigStormStaging
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Settings class
 */
class Big_Storm_Admin_Settings {
	/**
	 * Plugin slug
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Option name for the staging domain suffix
	 *
	 * @var string
	 */
	private $option_suffix;

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
	 * @param string $plugin_file Main plugin file path.
	 */
	public function __construct( $slug, $plugin_file ) {
		$this->slug          = $slug;
		$this->plugin_file   = $plugin_file;
		$this->option_suffix = 'bigstorm_stage_domain_suffix';
	}

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $this->plugin_file ), array( $this, 'plugin_action_links_settings' ) );
	}

	/**
	 * Get the configured staging domain value (normalized).
	 *
	 * @return string
	 */
	public function get_staging_suffix() {
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
	 * @param string $value Raw setting input.
	 * @return string Normalized domain or suffix, or empty string on failure.
	 */
	public function normalize_suffix( $value ) {
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

		// Full domain (no leading dot). Allow single-label too (e.g., localhost).
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*$/', $value ) ) {
			return '';
		}
		return $value;
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
	 * @return string Sanitized value.
	 */
	public function sanitize_suffix( $value ) {
		return $this->normalize_suffix( $value );
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
	 * Add Settings link to plugin action links.
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
}
