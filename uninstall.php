<?php

/**
 * Runs only when the plugin is deleted from the Plugins screen.
 *
 * Removes all plugin options and any remaining Gatalyn transients.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Remove all registered options. Variables are prefixed because uninstall.php
// runs in the global scope.
$gatalyn_options = array(
	'gatalyn_enabled',
	'gatalyn_mode',
	'gatalyn_allowed_ips',
	'gatalyn_token_expiry',
	'gatalyn_email_enabled',
	'gatalyn_email_subject',
	'gatalyn_email_body',
	'gatalyn_telegram_enabled',
	'gatalyn_telegram_bot_token',
	'gatalyn_telegram_chat_id',
	'gatalyn_telegram_message',
	'gatalyn_limit_attempts_enabled',
	'gatalyn_max_attempts',
	'gatalyn_lockout_minutes',
	'gatalyn_retry_window_hours',
	'gatalyn_long_lockout_after',
	'gatalyn_long_lockout_hours',
	'gatalyn_show_remaining',
	'gatalyn_lockout_notify_email',
	'gatalyn_lockout_notify_telegram',
	'gatalyn_lockout_notify_after',
	'gatalyn_trust_proxy',
	'gatalyn_trusted_proxies',
	'gatalyn_blocked_ips',
	'gatalyn_blocked_usernames',
	'gatalyn_rest_api_mode',
	'gatalyn_lockout_log',
	'gatalyn_lockouts_total',
	'gatalyn_lla_imported',
);

/**
 * Removes this plugin's data from the current site.
 *
 * @param string[] $options Option names to delete.
 */
function gatalyn_uninstall_site($options)
{
	global $wpdb;

	foreach ($options as $option) {
		delete_option($option);
	}

	delete_transient('gatalyn_health_result');

	// Remove any Gatalyn transients that may still be stored in the options table,
	// plus the plain gatalyn_request_*/gatalyn_revoked_*/gatalyn_approval_*/gatalyn_ll_*
	// rows written directly via update_option() as a fallback for hosts that don't
	// reliably persist transients (see class-gatalyn-token.php and class-gatalyn-store.php).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE %s
			    OR option_name LIKE %s
			    OR option_name LIKE %s
			    OR option_name LIKE %s
			    OR option_name LIKE %s
			    OR option_name LIKE %s
			    OR option_name LIKE %s",
			$wpdb->esc_like('_transient_gatalyn_') . '%',
			$wpdb->esc_like('_transient_timeout_gatalyn_') . '%',
			$wpdb->esc_like('gatalyn_request_') . '%',
			$wpdb->esc_like('gatalyn_revoked_') . '%',
			$wpdb->esc_like('gatalyn_approval_') . '%',
			$wpdb->esc_like('gatalyn_ll_') . '%',
			$wpdb->esc_like('gatalyn_import_summary_') . '%'
		)
	);

	wp_clear_scheduled_hook('gatalyn_daily_garbage_collection');
}

if (is_multisite()) {
	// Network-activated or not, every site may hold its own settings and state.
	foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $gatalyn_site_id) {
		switch_to_blog($gatalyn_site_id);
		gatalyn_uninstall_site($gatalyn_options);
		restore_current_blog();
	}
} else {
	gatalyn_uninstall_site($gatalyn_options);
}

// Note: deleting the plugin's own files is intentionally NOT done here.
// WordPress core already removes the plugin directory (via WP_Filesystem)
// right after this script runs when a user deletes the plugin from the
// Plugins screen; re-implementing that with raw unlink()/rmdir() calls here
// would just duplicate core behavior while bypassing the filesystem
// abstraction it uses for a reason (FTP-based installs, permission checks,
// etc). This file's job is only to clean up the plugin's own stored data.
