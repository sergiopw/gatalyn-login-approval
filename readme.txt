=== Gatalyn Login Approval ===
Contributors: sergiopw
Tags: login, security, limit login attempts, brute force, telegram
Requires at least: 5.6
Tested up to: 7.0
Stable tag: 1.4.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Login approval by email or Telegram, plus a drop-in replacement for Limit Login Attempts that imports its settings.

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
    *   Long lockout for addresses that keep coming back (for example 24 hours after 4 lockouts).
    *   Failed attempts are forgotten after a configurable quiet period.
    *   Shows how many attempts are left on the login page.
    *   Lockout log with the usernames tried, and one-click unlock.
    *   Lockout alerts by email and/or Telegram.
    *   Reverse proxy / CDN setting, with a hint about which one your site needs.
    *   Permanent blocklists for IP addresses (IPv4 and IPv6, with CIDR ranges) and for usernames such as "admin". Allowed IP addresses always win, and the plugin refuses to save a blocklist that contains your own address.
*   **REST API Restrictions:** Either hides `/wp-json/wp/v2/users` from visitors, which stops username enumeration and affects nothing else, or requires authentication for the whole REST API.

= Switching from Limit Login Attempts =

The original Limit Login Attempts plugin has not been updated in years. Gatalyn Login Approval uses the same counting model, so your settings keep their meaning:

1. Install and activate Gatalyn Login Approval. Keep Limit Login Attempts active for now.
2. Go to **Settings > Login Approval > Lockouts** and click **Import Settings**.
3. Check the **Security** tab, then deactivate Limit Login Attempts.

The import copies the retry limit, lockout and long-lockout durations, the retry window, the reverse proxy setting, email alerts, lockouts that are still running, the lockout log and total, and (from Limit Login Attempts Reloaded) the IP whitelist. It only reads the other plugin's data and never changes it.

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

Yes, you can add your IP address (or multiple IP addresses and IPv4/IPv6 CIDR ranges, one per line) in the plugin settings to completely bypass the approval process. Whitelisted addresses are also exempt from the login attempt lockout. If your site is behind a reverse proxy or CDN, enable the proxy option and, where a CDN sits in front of another proxy, list the proxies under Trusted Proxies; otherwise the plugin sees the proxy's address, not the visitor's.

= I can no longer receive approval emails. How do I get in? =

Add `define( 'GATALYN_DISABLE', true );` to wp-config.php. The approval step is skipped until you remove the line. You can also rename the plugin folder over FTP.

= I locked myself out with the login attempt limiter. What now? =

Wait for the lockout to expire (15 minutes by default). If you cannot wait, another administrator can unlock your address under **Settings > Login Approval > Lockouts**, or you can delete the `gatalyn_limit_attempts_enabled` option from the database, or rename the plugin folder over FTP to deactivate it.

= Which Limit Login Attempts settings can be imported? =

Everything the original plugin stores: allowed retries, lockout duration, allowed lockouts and long lockout duration, the retry reset time, the proxy setting, email notification and its threshold, active lockouts, the log and the lockout total. From Limit Login Attempts Reloaded the IP whitelist is also imported, except ranges written as "start-end", which must be re-entered as CIDR. Reloaded's denylists and cloud features are not imported.

= Will restricting the REST API break my site? =

"Hide the users endpoint" will not: it only removes the route that lists your usernames. "Require login for the whole REST API" can, because plugins and themes often call the API from the front end without a session, for example contact forms, AJAX search, store endpoints, or page builders. Only oEmbed stays public so that existing embeds of your site elsewhere keep working. Test your front end after enabling it.

== Changelog ==

= 1.4.3 =
* Telegram: when the bot already has a webhook, "Detect chat ID" now explains what to do instead of showing the raw API error.

= 1.4.2 =
* Settings: the top bar (Save Changes, link to the Dashboard and the tabs) stays fixed while scrolling and no longer disappears on the Lockouts tab.

= 1.4.1 =
* The Save Changes button (Settings), the Settings and Lockouts buttons (Dashboard) and Clear Log (Lockouts) are now also shown at the top of the page.

= 1.4.0 =
* New option "Login Notification" (off by default): after every successful login the user gets an email and/or Telegram message with time, IP and browser, and a link to close all sessions. The link asks for confirmation before logging out and works for 3 days.

= 1.3.4 =
* An approval is only valid for the user it was given for; trying another account with it also burns the approval.
* The "Approve Login" and "Do NOT Authorize" buttons are centered.

= 1.3.3 =
* The installed version is shown next to the title on the Dashboard and Settings pages.

= 1.3.2 =
* New: the dashboard lists the last 50 attempts: what was typed, IP, date and result (link sent, unknown name, too many requests, locked out, wrong password).

= 1.3.1 =
* Names that don't exist, typed in the approval form, now count as failed logins (dashboard, lockouts). A locked-out address gets no approval email.
* The "Send Approval Link" button is centered.

= 1.3.0 =
* New: own top-level "Login Approval" menu with a Dashboard (failed logins, lockouts, approvals and denials, 14-day chart) and the Settings page. Old Settings links keep working.

= 1.2.1 =
* Security: with the reverse proxy option on, the visitor address is now taken from the end of X-Forwarded-For that your proxy wrote, not the start, which any visitor could forge to dodge lockouts and the approval step. New "Trusted Proxies" setting for a CDN in front of another proxy (Cloudflare's CF-Connecting-IP is honoured when the request came through a trusted proxy).
* Security: approval and deny links now open a confirmation page and only act on the button. Mail scanners that open every link can no longer approve or deny a login by themselves.
* Security: approval requests are limited per address and per username, and the response no longer reveals whether an account exists (same-shaped request id, and the email is sent after the page is returned).
* Security: approval tokens and request ids are stored hashed, and their fallback rows are no longer autoloaded.
* Security: a successful login no longer wipes the failed-attempt counter, which let a user with an account of their own guess other passwords without limit. IPv6 visitors are counted per /64 network. Failed application-password logins now count, and a locked-out address can't use them.
* Fix: "After credentials" mode caused a fatal error for any password login that was not on wp-login.php (XML-RPC, front-end login forms). Those logins are now refused with a message instead.
* Fix: in "After credentials" mode the waiting page now updates by itself and finishes the login when the approval is made on another device.
* Fix: application passwords (REST API clients) are no longer blocked by the approval step.
* Fix: Telegram messages with characters such as "<" in a name were rejected by Telegram.
* Fix: the login-page health check no longer slows every admin page on hosts that block loopback requests, and no longer warns about a stale cache when the server's own address is whitelisted.
* Fix: email subject now supports the same template variables as the body.
* Fix: the settings page can no longer be saved with no way to deliver approval links. New GATALYN_DISABLE constant in wp-config.php turns the approval step off in an emergency.
* Fix: uninstalling now cleans every site of a multisite network.
* Other: a filter, gatalyn_rest_public_routes, lets other plugins keep their own endpoints public when the whole REST API requires login. The login status page no longer writes to the browser console.

= 1.2.0 =
* New: permanent blocklists for IP addresses and usernames. Blocked usernames count as failed attempts, so bots guessing "admin" get locked out.
* New: IPv6 addresses and ranges are now supported in the allowed and blocked IP lists (previously only IPv4 ranges worked).
* New: import settings, active lockouts, log and whitelist from Limit Login Attempts and Limit Login Attempts Reloaded.
* New: long lockout after repeated lockouts, and a configurable window after which failed attempts are forgotten.
* New: remaining attempts are shown on the login page.
* New: Lockouts tab with the lockout log, usernames tried, total count and an Unlock button.
* New: lockout alerts by email and Telegram.
* New: reverse proxy setting on the settings screen. The GATALYN_TRUST_PROXY constant still takes precedence when defined.
* Fix: the Security Hardening settings added in 1.1.0 were not shown on the settings page, and saving the page reset them (attempt limiting off, 1 allowed attempt, 1-minute lockout). They now have their own Security tab.

= 1.1.0 =
* New: optional login attempt limiting, blocking an IP address for a configurable time after repeated failures. Blocked attempts are rejected before the approval flow runs, so no approval email or Telegram message is sent for them.
* New: optional REST API restrictions, with separate levels for hiding the users endpoint and for requiring authentication site-wide.
* Client IP resolution moved to a shared helper so every module handles the GATALYN_TRUST_PROXY setting identically.

= 1.0.0 =
* Initial public release.
