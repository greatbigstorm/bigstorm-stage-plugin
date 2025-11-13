<?php
/**
 * Admin Notices Handler
 *
 * Manages dismissible admin notices for the plugin.
 *
 * @package BigStormStaging
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Notices class
 */
class Big_Storm_Admin_Notices {
	/**
	 * Plugin slug
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * User meta key for dismissing the remove-plugin notice
	 *
	 * @var string
	 */
	private $dismiss_meta_key;

	/**
	 * Plugin file path
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Reference to staging protection instance for domain checking
	 *
	 * @var Big_Storm_Staging_Protection
	 */
	private $staging_protection;

	/**
	 * Constructor
	 *
	 * @param string                       $slug               Plugin slug.
	 * @param string                       $plugin_file        Main plugin file path.
	 * @param Big_Storm_Staging_Protection $staging_protection Staging protection instance.
	 */
	public function __construct( $slug, $plugin_file, $staging_protection ) {
		$this->slug               = $slug;
		$this->plugin_file        = $plugin_file;
		$this->staging_protection = $staging_protection;
		$this->dismiss_meta_key   = 'bigstorm_stage_dismiss_remove_notice';
	}

	/**
	 * Initialize hooks
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_notices', array( $this, 'maybe_show_remove_notice' ) );
		add_action( 'network_admin_notices', array( $this, 'maybe_show_remove_notice' ) );
		add_action( 'admin_init', array( $this, 'handle_dismiss_remove_notice' ) );
		add_action( 'wp_ajax_bigstorm_stage_dismiss', array( $this, 'ajax_dismiss_remove_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_dismiss_script' ) );
	}

	/**
	 * Determine if the remove-plugin notice should be shown.
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
		if ( $this->staging_protection->is_staging_domain() ) {
			return false;
		}
		if ( get_user_meta( get_current_user_id(), $this->dismiss_meta_key, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Enqueue dismiss script if needed.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function maybe_enqueue_dismiss_script( $hook ) {
		if ( $this->should_show_remove_notice() ) {
			add_action( 'admin_print_footer_scripts', array( $this, 'print_dismiss_script' ) );
		}
	}

	/**
	 * Print inline script to persist dismissal via AJAX.
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
	 * Handle dismissal via nonce-protected link.
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
	 * AJAX handler to persist dismissal.
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
	 * Show dismissible admin notice suggesting plugin removal.
	 *
	 * @return void
	 */
	public function maybe_show_remove_notice() {
		if ( ! $this->should_show_remove_notice() ) {
			return;
		}

		$basename       = plugin_basename( $this->plugin_file );
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
}
