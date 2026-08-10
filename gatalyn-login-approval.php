<?php

/**
 * Plugin Name: Gatalyn Login Approval
 * Plugin URI:  https://github.com/sergiopw/gatalyn-login-approval
 * Description: Adds email and/or Telegram-based login approval before or after credential entry.
 * Version:     1.0.0
 * Requires at least: 5.6
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Author:      Sergio
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gatalyn-login-approval
 */

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('GATALYN_VERSION')) {
    define('GATALYN_VERSION', '1.0.0');
}

if (! defined('GATALYN_PLUGIN_FILE')) {
    define('GATALYN_PLUGIN_FILE', __FILE__);
}

if (! defined('GATALYN_PLUGIN_DIR')) {
    define('GATALYN_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-token.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-email.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-telegram.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-settings.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-login.php';

/**
 * Sets default options on activation without overriding existing values.
 */
function gatalyn_activate_plugin()
{
    add_option('gatalyn_enabled', '1');
    add_option('gatalyn_mode', 'before');
    add_option('gatalyn_allowed_ips', '');
    add_option('gatalyn_token_expiry', 60);
    add_option('gatalyn_email_enabled', '1');
    add_option('gatalyn_email_subject', '');
    add_option('gatalyn_email_body', '');
    add_option('gatalyn_telegram_enabled', '');
    add_option('gatalyn_telegram_bot_token', '');
    add_option('gatalyn_telegram_chat_id', '');
    add_option('gatalyn_telegram_message', '');

    if (! wp_next_scheduled('gatalyn_daily_garbage_collection')) {
        wp_schedule_event(time(), 'daily', 'gatalyn_daily_garbage_collection');
    }
}
register_activation_hook(__FILE__, 'gatalyn_activate_plugin');

function gatalyn_deactivate_plugin()
{
    wp_clear_scheduled_hook('gatalyn_daily_garbage_collection');
}
register_deactivation_hook(__FILE__, 'gatalyn_deactivate_plugin');

add_action('gatalyn_daily_garbage_collection', array('Gatalyn_Token', 'run_garbage_collection'));

/*
 * Register login hooks immediately at plugin load time.
 */
$GLOBALS['gatalyn_login_handler'] = new Gatalyn_Login();
$GLOBALS['gatalyn_login_handler']->init();

/*
 * Settings page: only needed inside wp-admin.
 */
add_action(
    'plugins_loaded',
    function () {
        if (is_admin()) {
            (new Gatalyn_Settings())->init();
        }
    }
);
