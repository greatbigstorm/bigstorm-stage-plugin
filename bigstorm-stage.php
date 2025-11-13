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
 * Plugin URI:        https://github.com/greatbigstorm/bigstorm-stage-plugin
 * Description:       Adds a "Disallow: /" directive to robots.txt on staging domains ending with .greatbigstorm.com and returns HTTP 410 (Gone) for page requests from known search crawlers. Can be removed once the site is launched to production.
 * Version:           1.0.5
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

// Load plugin classes.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-admin-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-staging-protection.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-github-updater.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-modal.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-admin-notices.php';

/**
 * Main plugin class
 *
 * Coordinates all plugin components.
 */
class Big_Storm_Staging {
	/**
	 * Plugin slug
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * GitHub repository
	 *
	 * @var string
	 */
	private $github_repo = 'greatbigstorm/bigstorm-stage-plugin';

	/**
	 * Admin settings instance
	 *
	 * @var Big_Storm_Admin_Settings
	 */
	private $settings;

	/**
	 * Staging protection instance
	 *
	 * @var Big_Storm_Staging_Protection
	 */
	private $staging_protection;

	/**
	 * GitHub updater instance
	 *
	 * @var Big_Storm_GitHub_Updater
	 */
	private $github_updater;

	/**
	 * Plugin modal instance
	 *
	 * @var Big_Storm_Plugin_Modal
	 */
	private $plugin_modal;

	/**
	 * Admin notices instance
	 *
	 * @var Big_Storm_Admin_Notices
	 */
	private $admin_notices;

	/**
	 * Initialize the plugin
	 *
	 * @return void
	 */
	public function init() {
		$this->slug = basename( dirname( __FILE__ ) );

		// Initialize components in dependency order.
		$this->settings           = new Big_Storm_Admin_Settings( $this->slug, __FILE__ );
		$this->staging_protection = new Big_Storm_Staging_Protection( $this->settings );
		$this->github_updater     = new Big_Storm_GitHub_Updater( $this->slug, $this->github_repo, __FILE__ );
		$this->plugin_modal       = new Big_Storm_Plugin_Modal( $this->slug, __FILE__, $this->github_updater );
		$this->admin_notices      = new Big_Storm_Admin_Notices( $this->slug, __FILE__, $this->staging_protection );

		// Initialize all components.
		$this->settings->init();
		$this->staging_protection->init();
		$this->github_updater->init();
		$this->plugin_modal->init();
		$this->admin_notices->init();
	}
}

// Initialize the plugin.
$big_storm_staging = new Big_Storm_Staging();
$big_storm_staging->init();
