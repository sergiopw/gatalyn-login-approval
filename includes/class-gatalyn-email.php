<?php

/**
 * Email sending for Login Approval.
 *
 * Sends HTML approval emails with approve and deny links.
 * The email body supports the following template variables:
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

class Gatalyn_Email
{

    /**
     * Sends an approval request email to the user.
     *
     * @param WP_User $user          The user object.
     * @param string  $approve_token The approval token.
     * @param string  $deny_token    The deny token.
     * @param string  $mode          'before' or 'after'.
     * @param string  $identifier    Username/email used in the request.
     *
     * @return bool Whether wp_mail() reported success.
     */
    public static function send_approval(WP_User $user, $approve_token, $deny_token, $mode = 'before', $identifier = '')
    {
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

        $subject_tpl   = get_option('gatalyn_email_subject', '');
        $subject       = ! empty($subject_tpl) ? $subject_tpl : self::default_subject();
        $body_template = get_option('gatalyn_email_body', '');
        $body_template = ! empty($body_template) ? $body_template : self::default_body();

        $expiry_minutes = max(1, (int) get_option('gatalyn_token_expiry', 60));

        if ('after' === $mode) {
            $mode_info = __('You will be logged in immediately after clicking the approval link.', 'gatalyn-login-approval');
        } else {
            $mode_info = __('After clicking the approval link you will be redirected to enter your password.', 'gatalyn-login-approval');
        }

        $body = str_replace(
            array('{approval_link}', '{deny_link}', '{user_name}', '{identifier}', '{site_name}', '{expiry_minutes}', '{mode_info}'),
            array(
                esc_url($approval_url),
                esc_url($deny_url),
                esc_html($user->display_name),
                esc_html($identifier),
                esc_html(get_bloginfo('name')),
                (string) $expiry_minutes,
                esc_html($mode_info),
            ),
            $body_template
        );

        $headers = array('Content-Type: text/html; charset=UTF-8');

        return wp_mail($user->user_email, wp_specialchars_decode($subject), $body, $headers);
    }

    /**
     * Returns the default email subject (used when the option is empty).
     *
     * @return string
     */
    public static function default_subject()
    {
        /* translators: %s: site name */
        return sprintf(__('[%s] Login Approval Required', 'gatalyn-login-approval'), get_bloginfo('name'));
    }

    /**
     * Returns the default HTML email body template.
     *
     * @return string
     */
    public static function default_body()
    {
        return '<p>' . __('Hello {user_name},', 'gatalyn-login-approval') . '</p>'
            . '<p>' . __('A login request has been made on <strong>{site_name}</strong>.', 'gatalyn-login-approval') . '</p>'
            . '<p>' . __('Login identifier used: <strong>{identifier}</strong>', 'gatalyn-login-approval') . '</p>'
            . '<p>{mode_info}</p>'
            . '<p style="margin:24px 0;">'
            .   '<a href="{approval_link}" style="background:#0073aa;color:#ffffff;padding:14px 28px;text-decoration:none;border-radius:6px;font-family:sans-serif;font-size:16px;font-weight:600;display:inline-block;margin-right:10px;">'
            .   __('Approve Login', 'gatalyn-login-approval')
            .   '</a>'
            .   '<a href="{deny_link}" style="background:#cc0000;color:#ffffff;padding:14px 28px;text-decoration:none;border-radius:6px;font-family:sans-serif;font-size:16px;font-weight:600;display:inline-block;">'
            .   __('Do NOT Authorize', 'gatalyn-login-approval')
            .   '</a>'
            . '</p>'
            . '<p style="word-break:break-all;">'
            .   __('Or copy and paste these links into your browser:', 'gatalyn-login-approval')
            .   '<br/><a href="{approval_link}">{approval_link}</a>'
            .   '<br/><a href="{deny_link}">{deny_link}</a>'
            . '</p>'
            . '<p><small>'
            .   __('These links expire in {expiry_minutes} minutes. If you did not attempt to log in, please click "Do NOT Authorize".', 'gatalyn-login-approval')
            . '</small></p>';
    }
}
