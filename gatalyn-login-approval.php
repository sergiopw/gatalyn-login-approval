<?php

/**
 * Plugin Name: Gatalyn Login Approval
 * Plugin URI:  https://github.com/sergiopw/gatalyn-login-approval
 * Description: Login approval by email or Telegram, Limit Login Attempts-style lockouts (with settings import), and REST API restrictions.
 * Version:     1.2.0
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
    define('GATALYN_VERSION', '1.2.0');
}

if (! defined('GATALYN_PLUGIN_FILE')) {
    define('GATALYN_PLUGIN_FILE', __FILE__);
}

if (! defined('GATALYN_PLUGIN_DIR')) {
    define('GATALYN_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-ip.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-store.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-token.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-email.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-telegram.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-settings.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-login.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-limit-login.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-lla-import.php';
require_once GATALYN_PLUGIN_DIR . 'includes/class-gatalyn-rest-api.php';

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

    // Hardening modules ship disabled: both can lock a site owner out of their
    // own install or break front-end features, so they must be opted into.
    add_option('gatalyn_limit_attempts_enabled', '');
    add_option('gatalyn_max_attempts', 5);
    add_option('gatalyn_lockout_minutes', 15);
    add_option('gatalyn_retry_window_hours', 12);
    add_option('gatalyn_long_lockout_after', 4);
    add_option('gatalyn_long_lockout_hours', 24);
    add_option('gatalyn_show_remaining', '1');
    add_option('gatalyn_lockout_notify_email', '');
    add_option('gatalyn_lockout_notify_telegram', '');
    add_option('gatalyn_lockout_notify_after', 4);
    add_option('gatalyn_trust_proxy', '');
    add_option('gatalyn_rest_api_mode', 'off');

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
 * Hardening modules. Both register on the same early pass as the login handler:
 * the limiter filters 'authenticate', and REST restrictions must be in place
 * before rest_api_init runs.
 */
$GLOBALS['gatalyn_limit_login'] = new Gatalyn_Limit_Login();
$GLOBALS['gatalyn_limit_login']->init();

$GLOBALS['gatalyn_rest_api'] = new Gatalyn_REST_API();
$GLOBALS['gatalyn_rest_api']->init();

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
