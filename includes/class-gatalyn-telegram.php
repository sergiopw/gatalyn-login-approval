<?php

/**
 * Telegram notification sender for Login Approval.
 *
 * Sends an approval request message to a configured Telegram chat using
 * the Telegram Bot API (sendMessage). No external libraries required;
 * uses WordPress wp_remote_post() for all HTTP calls.
 *
 * Configuration options:
 *   gatalyn_telegram_enabled   (bool)   Enable / disable Telegram notifications.
 *   gatalyn_telegram_bot_token (string) Bot token obtained from @BotFather.
 *   gatalyn_telegram_chat_id   (string) Destination chat ID (user, group or channel).
 *   gatalyn_telegram_message   (string) Message template with the same variables as the email.
 *
 * Template variables supported:
 *   {approval_link}   - The full approval URL.
 *   {deny_link}       - The full deny URL.
 *   {user_name}       - The user's display name.
 *   {identifier}      - Username/email used in the request.
 *   {site_name}       - The site name.
 *   {expiry_minutes}  - Token expiry in minutes.
 *   {mode_info}       - A human-readable description of what happens after approval.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Gatalyn_Telegram
{

    /** Telegram Bot API base URL (without trailing slash). */
    const API_BASE = 'https://api.telegram.org/bot';

    /**
     * Sends an approval request message to the configured Telegram chat.
     *
     * @param WP_User $user          The user object.
     * @param string  $approve_token The approval token.
     * @param string  $deny_token    The deny token.
     * @param string  $mode          'before' or 'after'.
     * @param string  $identifier    Username/email used in the request.
     *
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function send_approval(WP_User $user, $approve_token, $deny_token, $mode = 'before', $identifier = '')
    {
        $bot_token = get_option('gatalyn_telegram_bot_token', '');
        $chat_id   = get_option('gatalyn_telegram_chat_id', '');

        if (empty($bot_token) || empty($chat_id)) {
            return new WP_Error(
                'gatalyn_telegram_config',
                __('Telegram bot token or chat ID is not configured.', 'gatalyn-login-approval')
            );
        }

        $approval_url = add_query_arg(
            array(
                'action' => 'gatalyn_approve',
                'token'  => rawurlencode($approve_token),
            ),
            wp_login_url()
        );

        $deny_url = add_query_arg(
            array(
                'action' => 'gatalyn_deny',
                'token'  => rawurlencode($deny_token),
            ),
            wp_login_url()
        );

        $expiry_minutes = max(1, (int) get_option('gatalyn_token_expiry', 60));

        if ('after' === $mode) {
            $mode_info = __('The user will be logged in immediately after clicking the approval link.', 'gatalyn-login-approval');
        } else {
            $mode_info = __('After clicking the approval link the user will be redirected to enter their password.', 'gatalyn-login-approval');
        }

        $message_template = get_option('gatalyn_telegram_message', '');
        $message_template = ! empty($message_template) ? $message_template : self::default_message();

        $message = str_replace(
            array('{approval_link}', '{deny_link}', '{user_name}', '{identifier}', '{site_name}', '{expiry_minutes}', '{mode_info}'),
            array(
                esc_url($approval_url),
                esc_url($deny_url),
                $user->display_name,
                $identifier,
                get_bloginfo('name'),
                (string) $expiry_minutes,
                $mode_info,
            ),
            $message_template
        );

        return self::send_message($bot_token, $chat_id, $message);
    }

    /**
     * Sends a raw text message to a Telegram chat via the Bot API.
     *
     * @param string $bot_token Bot token.
     * @param string $chat_id   Destination chat ID.
     * @param string $text      Message text (supports Markdown).
     *
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public static function send_message($bot_token, $chat_id, $text)
    {
        $url = self::API_BASE . sanitize_text_field($bot_token) . '/sendMessage';

        $response = wp_remote_post(
            $url,
            array(
                'timeout'     => 15,
                'redirection' => 3,
                'headers'     => array('Content-Type' => 'application/json'),
                'body'        => wp_json_encode(
                    array(
                        'chat_id'                  => $chat_id,
                        'text'                     => $text,
                        'parse_mode'               => 'HTML',
                        'disable_web_page_preview' => true,
                    )
                ),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        if (200 !== (int) $status) {
            $body = wp_remote_retrieve_body($response);
            return new WP_Error(
                'gatalyn_telegram_api',
                /* translators: 1: HTTP status code 2: API response body */
                sprintf(__('Telegram API error (HTTP %1$s): %2$s', 'gatalyn-login-approval'), $status, $body)
            );
        }

        return true;
    }

    /**
     * Returns the default Telegram message template.
     *
     * @return string
     */
    public static function default_message()
    {
        return "\xF0\x9F\x94\x91 <b>" . __('Login Approval Required', 'gatalyn-login-approval') . "</b>\n\n"
            . '<b>' . __('Site:', 'gatalyn-login-approval') . '</b> {site_name}' . "\n"
            . '<b>' . __('User:', 'gatalyn-login-approval') . '</b> {user_name}' . "\n"
            . '<b>' . __('Identifier:', 'gatalyn-login-approval') . '</b> {identifier}' . "\n"
            . '<b>' . __('Expires in:', 'gatalyn-login-approval') . '</b> {expiry_minutes} ' . __('minutes', 'gatalyn-login-approval') . "\n\n"
            . '{mode_info}' . "\n\n"
            . "\xE2\x9C\x85 <b>" . __('Approve:', 'gatalyn-login-approval') . "</b>\n" . '{approval_link}' . "\n\n"
            . "\xE2\x9D\x8C <b>" . __('Deny:', 'gatalyn-login-approval') . "</b>\n" . '{deny_link}';
    }

    /**
     * Calls the Telegram getUpdates API and returns the chat ID of the most
     * recent message received by the bot. The admin must have sent at least
     * one message to the bot before calling this.
     *
     * @param string $bot_token Bot token.
     *
     * @return string|WP_Error The chat ID as a string, or WP_Error on failure.
     */
    public static function get_chat_id_from_updates($bot_token)
    {
        $url = self::API_BASE . sanitize_text_field($bot_token) . '/getUpdates';

        $response = wp_remote_get(
            $url,
            array(
                'timeout'     => 15,
                'redirection' => 3,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = wp_remote_retrieve_body($response);

        if (200 !== (int) $status) {
            return new WP_Error(
                'gatalyn_telegram_api',
                /* translators: 1: HTTP status code 2: API response body */
                sprintf(__('Telegram API error (HTTP %1$s): %2$s', 'gatalyn-login-approval'), $status, $body)
            );
        }

        $data = json_decode($body, true);

        if (empty($data['ok']) || empty($data['result'])) {
            return new WP_Error(
                'gatalyn_telegram_no_updates',
                __('No messages found. Please send any message to your bot in Telegram first, then try again.', 'gatalyn-login-approval')
            );
        }

        // Use the most recent update.
        $latest = end($data['result']);

        // Support messages, channel posts, and callback queries.
        $chat_id = null;
        if (! empty($latest['message']['chat']['id'])) {
            $chat_id = $latest['message']['chat']['id'];
        } elseif (! empty($latest['channel_post']['chat']['id'])) {
            $chat_id = $latest['channel_post']['chat']['id'];
        } elseif (! empty($latest['callback_query']['message']['chat']['id'])) {
            $chat_id = $latest['callback_query']['message']['chat']['id'];
        }

        if (null === $chat_id) {
            return new WP_Error(
                'gatalyn_telegram_no_chat_id',
                __('Could not extract a chat ID from the bot updates. Make sure you sent a message to the bot directly.', 'gatalyn-login-approval')
            );
        }

        return (string) $chat_id;
    }
}
