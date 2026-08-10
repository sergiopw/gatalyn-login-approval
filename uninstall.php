<?php

/**
 * Runs only when the plugin is deleted from the Plugins screen.
 *
 * Removes all plugin options and any remaining Gatalyn transients.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Remove all registered options.
$options = array(
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
);

foreach ($options as $option) {
	delete_option($option);
}

// Remove any Gatalyn transients that may still be stored in the options table,
// plus the plain gatalyn_request_*/gatalyn_revoked_*/gatalyn_approval_* rows written
// directly via update_option() as a fallback for hosts that don't reliably
// persist transients (see class-gatalyn-token.php).
global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE %s
		    OR option_name LIKE %s
		    OR option_name LIKE %s
		    OR option_name LIKE %s
		    OR option_name LIKE %s",
		$wpdb->esc_like('_transient_gatalyn_') . '%',
		$wpdb->esc_like('_transient_timeout_gatalyn_') . '%',
		$wpdb->esc_like('gatalyn_request_') . '%',
		$wpdb->esc_like('gatalyn_revoked_') . '%',
		$wpdb->esc_like('gatalyn_approval_') . '%'
	)
);

// Note: deleting the plugin's own files is intentionally NOT done here.
// WordPress core already removes the plugin directory (via WP_Filesystem)
// right after this script runs when a user deletes the plugin from the
// Plugins screen; re-implementing that with raw unlink()/rmdir() calls here
// would just duplicate core behavior while bypassing the filesystem
// abstraction it uses for a reason (FTP-based installs, permission checks,
// etc). This file's job is only to clean up the plugin's own stored data.
