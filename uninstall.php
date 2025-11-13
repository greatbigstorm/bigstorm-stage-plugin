<?php
/**
 * Uninstall Big Storm Staging
 *
 * Removes all plugin data from the database when the plugin is uninstalled.
 *
 * @package BigStormStaging
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
delete_option( 'bigstorm_stage_domain_suffix' );
delete_option( 'bigstorm_stage_block_robots' );

// Delete site options for multisite.
delete_site_option( 'bigstorm_stage_domain_suffix' );
delete_site_option( 'bigstorm_stage_block_robots' );

// Delete transients used for caching.
delete_site_transient( 'bigstorm_stage_update_meta' );

// Delete all tag readme transients (they follow a pattern).
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_bigstorm_stage_tag_readme_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_timeout_bigstorm_stage_tag_readme_%'" );

// For multisite, delete from the sitemeta table.
if ( is_multisite() ) {
	$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_bigstorm_stage_tag_readme_%'" );
	$wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_timeout_bigstorm_stage_tag_readme_%'" );
}

// Delete user meta for dismissed notices.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'bigstorm_stage_dismiss_remove_notice'" );
