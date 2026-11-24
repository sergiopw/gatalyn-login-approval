<?php

/**
 * "Someone logged in" notice with a button that closes the sessions.
 *
 * After every successful login (when the option is on) the user gets an email
 * and/or a Telegram message with the time, address and browser, and a link that
 * logs the account out of every device. The link opens a confirmation page and
 * only a POST does the logging out, like the approve and deny links, so mail
 * scanners that open links can't trigger it.
 *
 * @package Gatalyn_Login_Approval
 */

if (! defined('ABSPATH')) {
	exit;
}

class Gatalyn_Login_Notice
{
	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init()
	{
		add_action('wp_login', array($this, 'notify'), 20, 2);
		add_action('login_form_gatalyn_logout', array($this, 'handle_logout_link'));
	}

	/**
	 * Sends the notice for a login that just succeeded.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user       User.
	 *
	 * @return void
	 */
	public function notify($user_login, $user = null)
	{
		if ('1' !== (string) get_option('gatalyn_login_notice', '') || ! ($user instanceof WP_User)) {
			return;
		}

		$ip      = Gatalyn_IP::get_client_ip();
		$agent   = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 200) : '';
		$when    = wp_date('j/n/Y H:i:s T');
		$site    = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
		$token   = Gatalyn_Token::generate_logout($user->ID);
		$url     = add_query_arg(
			array(
				'action' => 'gatalyn_logout',
				'token'  => rawurlencode($token),
			),
			wp_login_url()
		);

		if (get_option('gatalyn_email_enabled', '1') && '' !== $user->user_email) {
			$this->send_email($user, $site, $when, $ip, $agent, $url);
		}

		if (get_option('gatalyn_telegram_enabled', '')) {
			$this->send_telegram($user, $site, $when, $ip, $agent, $url);
		}
	}

	private function send_email($user, $site, $when, $ip, $agent, $url)
	{
		/* translators: %s: site name */
		$subject = sprintf(__('[%s] New login to your account', 'gatalyn-login-approval'), $site);

		$body = '<p>' . sprintf(
			/* translators: %s: user name */
			esc_html__('Hello %s,', 'gatalyn-login-approval'),
			esc_html($user->display_name)
		) . '</p>'
			. '<p>' . sprintf(
				/* translators: %s: site name */
				esc_html__('Someone has just logged in to your account on %s.', 'gatalyn-login-approval'),
				'<strong>' . esc_html($site) . '</strong>'
			) . '</p>'
			. '<p>' . esc_html__('Time:', 'gatalyn-login-approval') . ' <strong>' . esc_html($when) . '</strong><br/>'
			. esc_html__('IP address:', 'gatalyn-login-approval') . ' <strong>' . esc_html($ip) . '</strong><br/>'
			. esc_html__('Browser:', 'gatalyn-login-approval') . ' <strong>' . esc_html($agent) . '</strong></p>'
			. '<p style="margin:24px 0;"><a href="' . esc_url($url) . '" style="background:#cc0000;color:#ffffff;padding:14px 28px;text-decoration:none;border-radius:6px;font-family:sans-serif;font-size:16px;font-weight:600;display:inline-block;">'
			. esc_html__('That was not me: close the session', 'gatalyn-login-approval') . '</a></p>'
			. '<p><small>' . esc_html__('If it was you, you can ignore this message. The button logs the account out of every device and the link expires in 3 days.', 'gatalyn-login-approval') . '</small></p>';

		wp_mail($user->user_email, wp_specialchars_decode($subject), $body, array('Content-Type: text/html; charset=UTF-8'));
	}

	private function send_telegram($user, $site, $when, $ip, $agent, $url)
	{
		$bot_token = get_option('gatalyn_telegram_bot_token', '');
		$chat_id   = get_option('gatalyn_telegram_chat_id', '');

		if ('' === $bot_token || '' === $chat_id) {
			return;
		}

		$text = sprintf(
			/* translators: 1: user name, 2: site name */
			__('New login: %1$s on %2$s', 'gatalyn-login-approval'),
			esc_html($user->display_name),
			esc_html($site)
		) . "\n"
			. esc_html($when) . "\n"
			. 'IP: ' . esc_html($ip) . "\n"
			. esc_html($agent) . "\n\n"
			. '<a href="' . esc_url($url) . '">' . esc_html__('That was not me: close the session', 'gatalyn-login-approval') . '</a>';

		Gatalyn_Telegram::send_message($bot_token, $chat_id, $text);
	}

	/**
	 * wp-login.php?action=gatalyn_logout: confirmation page, then the logout.
	 *
	 * @return void
	 */
	public function handle_logout_link()
	{
		// phpcs:disable WordPress.Security.NonceVerification
		$token = isset($_REQUEST['token']) ? trim(sanitize_text_field(wp_unslash($_REQUEST['token']))) : '';
		$post  = isset($_SERVER['REQUEST_METHOD']) && 'POST' === strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])));
		// phpcs:enable

		nocache_headers();

		$data = Gatalyn_Token::verify_logout($token);
		if (! $data) {
			$this->page(
				__('This link has expired or was already used.', 'gatalyn-login-approval'),
				''
			);
			exit;
		}

		if (! $post) {
			$this->page(
				__('Log out of every device where this account is signed in?', 'gatalyn-login-approval'),
				'<form method="post" action="' . esc_url(site_url('wp-login.php', 'login_post')) . '">'
				. '<input type="hidden" name="action" value="gatalyn_logout" />'
				. '<input type="hidden" name="token" value="' . esc_attr($token) . '" />'
				. '<p class="submit" style="text-align:center"><input type="submit" class="button button-primary button-large" style="float:none" value="' . esc_attr__('Close all sessions', 'gatalyn-login-approval') . '" /></p>'
				. '</form>'
			);
			exit;
		}

		Gatalyn_Token::delete_logout($token);

		$user = get_user_by('id', (int) $data['user_id']);
		if ($user && class_exists('WP_Session_Tokens')) {
			WP_Session_Tokens::get_instance($user->ID)->destroy_all();
		}

		wp_clear_auth_cookie();

		$this->page(__('Done. The account is logged out everywhere. Change the password if it was not you.', 'gatalyn-login-approval'), '');
		exit;
	}

	/**
	 * @param string $message Text above the form.
	 * @param string $form    HTML of the form, already escaped.
	 *
	 * @return void
	 */
	private function page($message, $form)
	{
		login_header(__('Close sessions', 'gatalyn-login-approval'), '<p class="message">' . esc_html($message) . '</p>');
		echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
		login_footer();
	}
}
