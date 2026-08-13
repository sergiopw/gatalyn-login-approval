=== Gatalyn Login Approval ===
Contributors: sergiopw
Tags: login, security, 2fa, authentication, telegram
Requires at least: 5.6
Tested up to: 7.0
Stable tag: 1.1.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Login approval by email or Telegram, failed-attempt lockout, and REST API restrictions.

== Description ==

Gatalyn Login Approval enhances the security of your WordPress site by requiring an additional layer of approval whenever someone attempts to log in. You can configure the plugin to send an approval link via Email, Telegram, or both.

The plugin supports two modes:
*   **Before Login:** The user enters their username/email first, receives an approval link, and only after clicking it they can enter their password to log in.
*   **After Login:** The user enters their username and password as usual, but the login is held until they approve it via the link sent to them.

Features include:
*   Email and Telegram notifications
*   IP whitelisting to bypass approvals for trusted networks
*   Customizable email/Telegram subjects and messages
*   Token expiration times

Two optional hardening features are also included. Both are disabled by default:

*   **Limit Login Attempts:** Temporarily blocks an IP address after a configurable number of failed logins. Blocked attempts are rejected before the approval step, so you are not notified for every attempt an attacker makes while their address is locked out. Whitelisted addresses are never blocked.
*   **REST API Restrictions:** Either hides `/wp-json/wp/v2/users` from visitors, which stops username enumeration and affects nothing else, or requires authentication for the whole REST API.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/gatalyn-login-approval` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to **Settings > Gatalyn Login Approval** to configure the plugin.
4. (Optional) Set up a Telegram Bot Token and Chat ID to receive approvals via Telegram.

== External Services ==

This plugin utilizes the Telegram API to send login approval notifications to a specified Chat ID when the Telegram integration is enabled by the site administrator. 

*   **Service Used:** Telegram Bot API (https://core.telegram.org/bots/api)
*   **Data Transmitted:** The plugin sends the user's login name, IP address, and approval/denial links to the configured Telegram Chat ID. No passwords or session tokens are transmitted to Telegram. 
*   **Terms of Service:** By using this integration, you are subject to the [Telegram Terms of Service](https://telegram.org/tos).
*   **Privacy Policy:** Please refer to the [Telegram Privacy Policy](https://telegram.org/privacy) for details on how your data is handled.

== Frequently Asked Questions ==

= How do I configure the Telegram Bot? =

You need to create a bot via BotFather on Telegram to get your Bot Token. Then, start a chat with your bot and obtain your Chat ID to enter it in the plugin settings.

= Can I whitelist my IP? =

Yes, you can add your IP address (or multiple IP addresses, one per line) in the plugin settings to completely bypass the approval process. Whitelisted addresses are also exempt from the login attempt lockout.

= I locked myself out with the login attempt limiter. What now? =

Wait for the lockout to expire (15 minutes by default). If you cannot wait, delete the `gatalyn_limit_attempts_enabled` option from the database, or rename the plugin folder over FTP to deactivate it.

= Will restricting the REST API break my site? =

"Hide the users endpoint" will not: it only removes the route that lists your usernames. "Require login for the whole REST API" can, because plugins and themes often call the API from the front end without a session, for example contact forms, AJAX search, store endpoints, or page builders. Only oEmbed stays public so that existing embeds of your site elsewhere keep working. Test your front end after enabling it.

== Changelog ==

= 1.1.0 =
* New: optional login attempt limiting, blocking an IP address for a configurable time after repeated failures. Blocked attempts are rejected before the approval flow runs, so no approval email or Telegram message is sent for them.
* New: optional REST API restrictions, with separate levels for hiding the users endpoint and for requiring authentication site-wide.
* Client IP resolution moved to a shared helper so every module handles the GATALYN_TRUST_PROXY setting identically.

= 1.0.0 =
* Initial public release.
