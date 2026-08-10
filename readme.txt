=== Gatalyn Login Approval ===
Contributors: sergiopw
Tags: login, security, 2fa, authentication, telegram
Requires at least: 5.6
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds email and/or Telegram-based login approval before or after credential entry.

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

Yes, you can add your IP address (or multiple IP addresses, one per line) in the plugin settings to completely bypass the approval process.

== Changelog ==

= 1.0.0 =
* Initial public release.
