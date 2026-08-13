<?php

/**
 * Admin settings page for Login Approval.
 *
 * Registers plugin options under Settings > Login Approval.
 *
 * Options:
 *   gatalyn_enabled              (bool)   Enable / disable the plugin.
 *   gatalyn_mode                 (string) 'before' or 'after'.
 *   gatalyn_allowed_ips          (string) Newline-separated list of allowed IPs / CIDRs.
 *   gatalyn_token_expiry         (int)    Approval link expiry in minutes (default 60).
 *   gatalyn_email_enabled        (bool)   Send approval via email (default true).
 *   gatalyn_email_subject        (string) Email subject line.
 *   gatalyn_email_body           (string) HTML email body with template variables.
 *   gatalyn_telegram_enabled     (bool)   Send approval via Telegram bot.
 *   gatalyn_telegram_bot_token   (string) Telegram bot token from @BotFather.
 *   gatalyn_telegram_chat_id     (string) Destination Telegram chat / group ID.
 *   gatalyn_telegram_message     (string) Message template with the same variables as the email.
 *   gatalyn_limit_attempts_enabled (bool)   Lock out an IP after repeated login failures.
 *   gatalyn_max_attempts         (int)    Failed attempts allowed before locking out (default 5).
 *   gatalyn_lockout_minutes      (int)    Lockout duration in minutes (default 15).
 *   gatalyn_rest_api_mode        (string) 'off', 'users', or 'all'.
 */

if (! defined('ABSPATH')) {
	exit;
}

class Gatalyn_Settings
{

	const OPTIONS_GROUP = 'gatalyn_settings';
	const MENU_SLUG     = 'gatalyn-settings';

	/**
	 * Registers WordPress hooks.
	 */
	public function init()
	{
		add_action('admin_menu', array($this, 'add_menu_page'));
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
		add_action('wp_ajax_gatalyn_telegram_test', array($this, 'ajax_telegram_test'));
		add_action('wp_ajax_gatalyn_telegram_detect_chat_id', array($this, 'ajax_telegram_detect_chat_id'));
	}

	/**
	 * Adds a submenu entry under Settings.
	 */
	public function add_menu_page()
	{
		add_options_page(
			__('Login Approval Settings', 'gatalyn-login-approval'),
			__('Login Approval', 'gatalyn-login-approval'),
			'manage_options',
			self::MENU_SLUG,
			array($this, 'render_settings_page')
		);
	}

	/**
	 * Registers all settings, sections, and fields via the Settings API.
	 */
	public function register_settings()
	{

		// ---- options --------------------------------------------------------

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array($this, 'sanitize_checkbox'),
				'default'           => true,
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_mode',
			array(
				'type'              => 'string',
				'sanitize_callback' => array($this, 'sanitize_mode'),
				'default'           => 'before',
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_allowed_ips',
			array(
				'type'              => 'string',
				'sanitize_callback' => array($this, 'sanitize_allowed_ips'),
				'default'           => '',
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_token_expiry',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array($this, 'sanitize_token_expiry'),
				'default'           => 60,
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_email_subject',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_email_body',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_email_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array($this, 'sanitize_checkbox'),
				'default'           => true,
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_telegram_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array($this, 'sanitize_checkbox'),
				'default'           => false,
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_telegram_bot_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => array($this, 'sanitize_telegram_token'),
				'default'           => '',
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_telegram_chat_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_telegram_message',
			array(
				'type'              => 'string',
				'sanitize_callback' => array($this, 'sanitize_telegram_message'),
				'default'           => '',
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_limit_attempts_enabled',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array($this, 'sanitize_checkbox'),
				'default'           => false,
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_max_attempts',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array($this, 'sanitize_max_attempts'),
				'default'           => 5,
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_lockout_minutes',
			array(
				'type'              => 'integer',
				'sanitize_callback' => array($this, 'sanitize_lockout_minutes'),
				'default'           => 15,
			)
		);

		register_setting(
			self::OPTIONS_GROUP,
			'gatalyn_rest_api_mode',
			array(
				'type'              => 'string',
				'sanitize_callback' => array($this, 'sanitize_rest_api_mode'),
				'default'           => 'off',
			)
		);

		// ---- General section ------------------------------------------------

		add_settings_section(
			'gatalyn_general',
			__('General Settings', 'gatalyn-login-approval'),
			null,
			self::MENU_SLUG
		);

		add_settings_field(
			'gatalyn_enabled',
			__('Enable Plugin', 'gatalyn-login-approval'),
			array($this, 'field_enabled'),
			self::MENU_SLUG,
			'gatalyn_general'
		);

		add_settings_field(
			'gatalyn_mode',
			__('Approval Mode', 'gatalyn-login-approval'),
			array($this, 'field_mode'),
			self::MENU_SLUG,
			'gatalyn_general'
		);

		add_settings_field(
			'gatalyn_allowed_ips',
			__('Allowed IP Addresses', 'gatalyn-login-approval'),
			array($this, 'field_allowed_ips'),
			self::MENU_SLUG,
			'gatalyn_general'
		);

		add_settings_field(
			'gatalyn_token_expiry',
			__('Approval Link Expiry', 'gatalyn-login-approval'),
			array($this, 'field_token_expiry'),
			self::MENU_SLUG,
			'gatalyn_general'
		);

		// ---- Email section --------------------------------------------------

		add_settings_section(
			'gatalyn_email',
			__('Email Settings', 'gatalyn-login-approval'),
			array($this, 'email_section_description'),
			self::MENU_SLUG
		);

		add_settings_field(
			'gatalyn_email_enabled',
			__('Send via Email', 'gatalyn-login-approval'),
			array($this, 'field_email_enabled'),
			self::MENU_SLUG,
			'gatalyn_email'
		);

		add_settings_field(
			'gatalyn_email_subject',
			__('Email Subject', 'gatalyn-login-approval'),
			array($this, 'field_email_subject'),
			self::MENU_SLUG,
			'gatalyn_email'
		);

		add_settings_field(
			'gatalyn_email_body',
			__('Email Body', 'gatalyn-login-approval'),
			array($this, 'field_email_body'),
			self::MENU_SLUG,
			'gatalyn_email'
		);

		// ---- Telegram section -----------------------------------------------

		add_settings_section(
			'gatalyn_telegram',
			__('Telegram Settings', 'gatalyn-login-approval'),
			array($this, 'telegram_section_description'),
			self::MENU_SLUG
		);

		add_settings_field(
			'gatalyn_telegram_enabled',
			__('Send via Telegram', 'gatalyn-login-approval'),
			array($this, 'field_telegram_enabled'),
			self::MENU_SLUG,
			'gatalyn_telegram'
		);

		add_settings_field(
			'gatalyn_telegram_bot_token',
			__('Bot Token', 'gatalyn-login-approval'),
			array($this, 'field_telegram_bot_token'),
			self::MENU_SLUG,
			'gatalyn_telegram'
		);

		add_settings_field(
			'gatalyn_telegram_chat_id',
			__('Chat ID', 'gatalyn-login-approval'),
			array($this, 'field_telegram_chat_id'),
			self::MENU_SLUG,
			'gatalyn_telegram'
		);

		add_settings_field(
			'gatalyn_telegram_message',
			__('Message Template', 'gatalyn-login-approval'),
			array($this, 'field_telegram_message'),
			self::MENU_SLUG,
			'gatalyn_telegram'
		);

		// ---- Security hardening section --------------------------------------

		add_settings_section(
			'gatalyn_hardening',
			__('Security Hardening', 'gatalyn-login-approval'),
			array($this, 'hardening_section_description'),
			self::MENU_SLUG
		);

		add_settings_field(
			'gatalyn_limit_attempts_enabled',
			__('Limit Login Attempts', 'gatalyn-login-approval'),
			array($this, 'field_limit_attempts_enabled'),
			self::MENU_SLUG,
			'gatalyn_hardening'
		);

		add_settings_field(
			'gatalyn_max_attempts',
			__('Allowed Failed Attempts', 'gatalyn-login-approval'),
			array($this, 'field_max_attempts'),
			self::MENU_SLUG,
			'gatalyn_hardening'
		);

		add_settings_field(
			'gatalyn_lockout_minutes',
			__('Lockout Duration', 'gatalyn-login-approval'),
			array($this, 'field_lockout_minutes'),
			self::MENU_SLUG,
			'gatalyn_hardening'
		);

		add_settings_field(
			'gatalyn_rest_api_mode',
			__('REST API Access', 'gatalyn-login-approval'),
			array($this, 'field_rest_api_mode'),
			self::MENU_SLUG,
			'gatalyn_hardening'
		);

		add_settings_field(
			'gatalyn_telegram_test',
			__('Test Connection', 'gatalyn-login-approval'),
			array($this, 'field_telegram_test'),
			self::MENU_SLUG,
			'gatalyn_telegram'
		);
	}

	// -------------------------------------------------------------------------
	// Admin scripts & AJAX
	// -------------------------------------------------------------------------

	/**
	 * Enqueues inline JS for the Telegram test button (only on this plugin's settings page).
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_admin_scripts($hook_suffix)
	{
		if ('settings_page_' . self::MENU_SLUG !== $hook_suffix) {
			return;
		}

		$nonce         = wp_create_nonce('gatalyn_telegram_test');
		$nonce_detect  = wp_create_nonce('gatalyn_telegram_detect_chat_id');
		$ajax_url      = admin_url('admin-ajax.php');

		wp_add_inline_script(
			'jquery',
			// ---- Tab switching ----------------------------------------------
			'(function($){' .
			'var SK="gatalyn_active_tab";' .
			'function activateTab(id){' .
			'$(".gatalyn-tab-content").hide();' .
			'$("#gatalyn-tab-"+id).show();' .
			'$(".gatalyn-tab-link").removeClass("nav-tab-active");' .
			'$(".gatalyn-tab-link[data-tab="+id+"]").addClass("nav-tab-active");' .
			'try{sessionStorage.setItem(SK,id);}catch(e){}' .
			'}' .
			'$(document).on("click",".gatalyn-tab-link",function(e){' .
			'e.preventDefault();activateTab($(this).data("tab"));' .
			'});' .
			'$(function(){' .
			'var saved;try{saved=sessionStorage.getItem(SK);}catch(e){}' .
			'activateTab(saved||($("#gatalyn-tabs .gatalyn-tab-link").first().data("tab")));' .
			'});' .
			// ---- Test button ------------------------------------------------
			'$(document).on("click","#gatalyn-telegram-test-btn",function(e){' .
			'e.preventDefault();' .
			'var btn=$(this),result=$("#gatalyn-telegram-test-result");' .
			'btn.prop("disabled",true).text(btn.data("loading"));' .
			'result.text("").removeClass("gatalyn-ok gatalyn-err");' .
			'$.post(' . wp_json_encode($ajax_url) . ',{' .
			'action:"gatalyn_telegram_test",' .
			'nonce:' . wp_json_encode($nonce) . ',' .
			'bot_token:$("#gatalyn_telegram_bot_token").val(),' .
			'chat_id:$("#gatalyn_telegram_chat_id").val()' .
			'},function(resp){' .
			'btn.prop("disabled",false).text(btn.data("label"));' .
			'if(resp.success){result.addClass("gatalyn-ok").text(resp.data);}' .
			'else{result.addClass("gatalyn-err").text(resp.data);}' .
			'},"json").fail(function(){' .
			'btn.prop("disabled",false).text(btn.data("label"));' .
			'result.addClass("gatalyn-err").text("Request failed.");' .
			'});});' .
			// ---- Detect Chat ID button --------------------------------------
			'$(document).on("click","#gatalyn-telegram-detect-btn",function(e){' .
			'e.preventDefault();' .
			'var btn=$(this),result=$("#gatalyn-telegram-detect-result");' .
			'btn.prop("disabled",true).text(btn.data("loading"));' .
			'result.text("").removeClass("gatalyn-ok gatalyn-err");' .
			'$.post(' . wp_json_encode($ajax_url) . ',{' .
			'action:"gatalyn_telegram_detect_chat_id",' .
			'nonce:' . wp_json_encode($nonce_detect) . ',' .
			'bot_token:$("#gatalyn_telegram_bot_token").val()' .
			'},function(resp){' .
			'btn.prop("disabled",false).text(btn.data("label"));' .
			'if(resp.success){' .
			'$("#gatalyn_telegram_chat_id").val(resp.data.chat_id);' .
			'result.addClass("gatalyn-ok").text(resp.data.message);' .
			'}else{result.addClass("gatalyn-err").text(resp.data);}' .
			'},"json").fail(function(){' .
			'btn.prop("disabled",false).text(btn.data("label"));' .
			'result.addClass("gatalyn-err").text("Request failed.");' .
			'});});' .
			'}(jQuery));'
		);

		wp_add_inline_style(
			'wp-admin',
			/* Tab content panels */
			'#gatalyn-tabs{margin-bottom:0;}'
			. '.gatalyn-tab-content{display:none;border:1px solid #c3c4c7;border-top:none;padding:16px 20px 10px;background:#fff;}'
			. '.gatalyn-tab-content table{margin-top:0;}'
			/* Telegram result spans */
			. '#gatalyn-telegram-test-result,#gatalyn-telegram-detect-result{display:inline-block;margin-left:10px;font-weight:600;}'
			. '#gatalyn-telegram-test-result.gatalyn-ok,#gatalyn-telegram-detect-result.gatalyn-ok{color:#00a32a;}'
			. '#gatalyn-telegram-test-result.gatalyn-err,#gatalyn-telegram-detect-result.gatalyn-err{color:#d63638;}'
		);
	}

	/**
	 * AJAX: sends a test Telegram message using the values POSTed from the form
	 * (before they are saved), so the admin can verify the configuration live.
	 */
	public function ajax_telegram_test()
	{
		check_ajax_referer('gatalyn_telegram_test', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(__('Insufficient permissions.', 'gatalyn-login-approval'));
		}

		$bot_token = sanitize_text_field(wp_unslash($_POST['bot_token'] ?? ''));
		$chat_id   = sanitize_text_field(wp_unslash($_POST['chat_id'] ?? ''));

		if (empty($bot_token) || empty($chat_id)) {
			wp_send_json_error(__('Please fill in the Bot Token and Chat ID fields first.', 'gatalyn-login-approval'));
		}

		$message = sprintf(
			/* translators: %s: site name */
			__("\xF0\x9F\x94\x8C Login Approval test message from <b>%s</b>. If you see this, Telegram is configured correctly!", 'gatalyn-login-approval'),
			esc_html(get_bloginfo('name'))
		);

		$result = Gatalyn_Telegram::send_message($bot_token, $chat_id, $message);

		if (is_wp_error($result)) {
			wp_send_json_error($result->get_error_message());
		}

		wp_send_json_success(__('Test message sent successfully! Check your Telegram.', 'gatalyn-login-approval'));
	}

	/**
	 * AJAX: calls getUpdates to detect the admin's Chat ID automatically.
	 * The admin must have sent at least one message to the bot beforehand.
	 */
	public function ajax_telegram_detect_chat_id()
	{
		check_ajax_referer('gatalyn_telegram_detect_chat_id', 'nonce');

		if (! current_user_can('manage_options')) {
			wp_send_json_error(__('Insufficient permissions.', 'gatalyn-login-approval'));
		}

		$bot_token = sanitize_text_field(wp_unslash($_POST['bot_token'] ?? ''));

		if (empty($bot_token)) {
			wp_send_json_error(__('Please enter the Bot Token first.', 'gatalyn-login-approval'));
		}

		$result = Gatalyn_Telegram::get_chat_id_from_updates($bot_token);

		if (is_wp_error($result)) {
			wp_send_json_error($result->get_error_message());
		}

		wp_send_json_success(array(
			'chat_id' => $result,
			/* translators: %s: detected Telegram chat ID */
			'message' => sprintf(__('Chat ID detected and filled in: %s', 'gatalyn-login-approval'), $result),
		));
	}

	// -------------------------------------------------------------------------
	// Page renderer
	// -------------------------------------------------------------------------

	/**
	 * Renders the full settings page with a tabbed layout.
	 *
	 * Three tabs — General, Email, Telegram — share a single <form> so that
	 * all sections are saved together with one submit button. JavaScript shows
	 * only the active tab panel; the hidden panels are still part of the DOM
	 * and submitted with the form.
	 */
	public function render_settings_page()
	{
		if (! current_user_can('manage_options')) {
			return;
		}
?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields(self::OPTIONS_GROUP); ?>

				<!-- Tab navigation -->
				<nav id="gatalyn-tabs" class="nav-tab-wrapper">
					<a href="#" class="nav-tab gatalyn-tab-link" data-tab="general">
						<?php esc_html_e('General', 'gatalyn-login-approval'); ?>
					</a>
					<a href="#" class="nav-tab gatalyn-tab-link" data-tab="email">
						<?php esc_html_e('Email', 'gatalyn-login-approval'); ?>
					</a>
					<a href="#" class="nav-tab gatalyn-tab-link" data-tab="telegram">
						<?php esc_html_e('Telegram', 'gatalyn-login-approval'); ?>
					</a>
				</nav>

				<!-- General tab -->
				<div id="gatalyn-tab-general" class="gatalyn-tab-content">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e('Enable Plugin', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_enabled(); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Approval Mode', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_mode(); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Allowed IP Addresses', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_allowed_ips(); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Approval Link Expiry', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_token_expiry(); ?></td>
						</tr>
					</table>
				</div>

				<!-- Email tab -->
				<div id="gatalyn-tab-email" class="gatalyn-tab-content">
					<?php $this->email_section_description(); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e('Send via Email', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_email_enabled(); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Email Subject', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_email_subject(); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Email Body', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_email_body(); ?></td>
						</tr>
					</table>
				</div>

				<!-- Telegram tab -->
				<div id="gatalyn-tab-telegram" class="gatalyn-tab-content">
					<?php $this->telegram_section_description(); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e('Send via Telegram', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_telegram_enabled(); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Bot Token', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_telegram_bot_token(); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Chat ID', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_telegram_chat_id(); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Message Template', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_telegram_message(); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e('Test Connection', 'gatalyn-login-approval'); ?></th>
							<td><?php $this->field_telegram_test(); ?></td>
						</tr>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
	<?php
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	/** Renders the Enable/Disable checkbox. */
	public function field_enabled()
	{
		$value = get_option('gatalyn_enabled', '1');
	?>
		<label>
			<input
				type="checkbox"
				name="gatalyn_enabled"
				id="gatalyn_enabled"
				value="1"
				<?php checked('1', $value); ?> />
			<?php esc_html_e('Activate the login approval flow', 'gatalyn-login-approval'); ?>
		</label>
	<?php
	}

	/** Renders the Before / After radio buttons. */
	public function field_mode()
	{
		$value = get_option('gatalyn_mode', 'before');
	?>
		<fieldset>
			<label>
				<input
					type="radio"
					name="gatalyn_mode"
					value="before"
					<?php checked('before', $value); ?> />
				<strong><?php esc_html_e('Before credentials', 'gatalyn-login-approval'); ?></strong>
				&mdash;
				<?php esc_html_e('The user enters their username first to request an approval link. After clicking the link they can enter their password.', 'gatalyn-login-approval'); ?>
			</label>
			<br /><br />
			<label>
				<input
					type="radio"
					name="gatalyn_mode"
					value="after"
					<?php checked('after', $value); ?> />
				<strong><?php esc_html_e('After credentials', 'gatalyn-login-approval'); ?></strong>
				&mdash;
				<?php esc_html_e('The user enters username and password as usual. If credentials are valid an approval link is emailed; clicking it completes the login.', 'gatalyn-login-approval'); ?>
			</label>
		</fieldset>
	<?php
	}

	/** Renders the Allowed IPs textarea. */
	public function field_allowed_ips()
	{
		$value = get_option('gatalyn_allowed_ips', '');
	?>
		<textarea
			name="gatalyn_allowed_ips"
			id="gatalyn_allowed_ips"
			rows="6"
			cols="40"
			class="large-text code"><?php echo esc_textarea($value); ?></textarea>
		<p class="description">
			<?php esc_html_e('One IP address or IPv4 CIDR range per line (e.g. 203.0.113.5 or 192.168.0.0/24). Login approval is skipped for these addresses.', 'gatalyn-login-approval'); ?>
		</p>
	<?php
	}

	/** Renders the token expiry number input. */
	public function field_token_expiry()
	{
		$value = (int) get_option('gatalyn_token_expiry', 60);
	?>
		<input
			type="number"
			name="gatalyn_token_expiry"
			id="gatalyn_token_expiry"
			value="<?php echo esc_attr($value); ?>"
			min="1"
			max="1440"
			step="1"
			class="small-text" />
		<?php esc_html_e('minutes', 'gatalyn-login-approval'); ?>
		<p class="description">
			<?php esc_html_e('How long the approval link remains valid (1–1440 minutes). After this time the user must request a new link.', 'gatalyn-login-approval'); ?>
		</p>
	<?php
	}

	/** Renders the email section description. */
	public function email_section_description()
	{
	?>
		<p>
			<?php esc_html_e('Customise the approval email. Leave a field empty to use the default value.', 'gatalyn-login-approval'); ?>
		</p>
		<p>
			<?php esc_html_e('Available template variables:', 'gatalyn-login-approval'); ?>
			<code>{approval_link}</code>,
			<code>{deny_link}</code>,
			<code>{user_name}</code>,
			<code>{identifier}</code>,
			<code>{site_name}</code>,
			<code>{expiry_minutes}</code>,
			<code>{mode_info}</code>.
		</p>
	<?php
	}

	/** Renders the email subject text input. */
	public function field_email_subject()
	{
		$value       = get_option('gatalyn_email_subject', '');
		$placeholder = Gatalyn_Email::default_subject();
	?>
		<input
			type="text"
			name="gatalyn_email_subject"
			id="gatalyn_email_subject"
			value="<?php echo esc_attr($value); ?>"
			placeholder="<?php echo esc_attr($placeholder); ?>"
			class="large-text" />
	<?php
	}

	/** Renders the email body textarea. */
	public function field_email_body()
	{
		$value       = get_option('gatalyn_email_body', '');
		$placeholder = esc_textarea(Gatalyn_Email::default_body());
	?>
		<textarea
			name="gatalyn_email_body"
			id="gatalyn_email_body"
			rows="12"
			cols="60"
			class="large-text code"
			placeholder="<?php echo $placeholder; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped 
							?>"><?php echo esc_textarea($value); ?></textarea>
		<p class="description">
			<?php esc_html_e('HTML is allowed. Use the template variables listed above.', 'gatalyn-login-approval'); ?>
		</p>
<?php
	}

	/** Renders the enable-email checkbox. */
	public function field_email_enabled()
	{
		$value = get_option('gatalyn_email_enabled', '1');
	?>
		<label>
			<input
				type="checkbox"
				name="gatalyn_email_enabled"
				id="gatalyn_email_enabled"
				value="1"
				<?php checked('1', $value); ?> />
			<?php esc_html_e('Send the approval link by email to the user\'s registered address', 'gatalyn-login-approval'); ?>
		</label>
	<?php
	}

	// -------------------------------------------------------------------------
	// Telegram field renderers
	// -------------------------------------------------------------------------

	/** Renders the Telegram section description. */
	public function telegram_section_description()
	{
	?>
		<p>
			<?php esc_html_e('Configure a Telegram bot to receive approval notifications. You need a bot token from @BotFather and the Chat ID of the destination chat.', 'gatalyn-login-approval'); ?>
		</p>
		<p>
			<?php esc_html_e('Available template variables:', 'gatalyn-login-approval'); ?>
			<code>{approval_link}</code>,
			<code>{deny_link}</code>,
			<code>{user_name}</code>,
			<code>{identifier}</code>,
			<code>{site_name}</code>,
			<code>{expiry_minutes}</code>,
			<code>{mode_info}</code>.
		</p>
	<?php
	}

	/** Renders the enable-Telegram checkbox. */
	public function field_telegram_enabled()
	{
		$value = get_option('gatalyn_telegram_enabled', '');
	?>
		<label>
			<input
				type="checkbox"
				name="gatalyn_telegram_enabled"
				id="gatalyn_telegram_enabled"
				value="1"
				<?php checked('1', $value); ?> />
			<?php esc_html_e('Send the approval link via Telegram bot', 'gatalyn-login-approval'); ?>
		</label>
	<?php
	}

	/** Renders the Bot Token text input. */
	public function field_telegram_bot_token()
	{
		$value = get_option('gatalyn_telegram_bot_token', '');
	?>
		<input
			type="text"
			name="gatalyn_telegram_bot_token"
			id="gatalyn_telegram_bot_token"
			value="<?php echo esc_attr($value); ?>"
			placeholder="123456789:ABCdefGhIJKlmNoPQRstuVWXyz"
			class="large-text"
			autocomplete="off" />
		<p class="description">
			<?php
				/* translators: @BotFather is a Telegram bot name, keep it as-is */
				esc_html_e('Obtain a token by creating a new bot with @BotFather on Telegram.', 'gatalyn-login-approval');
			?>
		</p>
	<?php
	}

	/** Renders the Chat ID text input. */
	public function field_telegram_chat_id()
	{
		$value = get_option('gatalyn_telegram_chat_id', '');
	?>
		<input
			type="text"
			name="gatalyn_telegram_chat_id"
			id="gatalyn_telegram_chat_id"
			value="<?php echo esc_attr($value); ?>"
			placeholder="-1001234567890"
			class="regular-text" />
		<button
			type="button"
			id="gatalyn-telegram-detect-btn"
			class="button button-secondary"
			style="margin-left:6px;"
			data-label="<?php esc_attr_e('Detect Chat ID automatically', 'gatalyn-login-approval'); ?>"
			data-loading="<?php esc_attr_e('Detecting&hellip;', 'gatalyn-login-approval'); ?>">
			<?php esc_html_e('Detect Chat ID automatically', 'gatalyn-login-approval'); ?>
		</button>
		<span id="gatalyn-telegram-detect-result"></span>
		<p class="description">
			<?php esc_html_e('How to find your Chat ID:', 'gatalyn-login-approval'); ?>
			<ol style="margin:.4em 0 0 1.4em;padding:0;">
				<li><?php esc_html_e('Enter your Bot Token above and save, or leave it typed in the field.', 'gatalyn-login-approval'); ?></li>
				<li><?php esc_html_e('Open Telegram and send any message to your bot (e.g. "hello").', 'gatalyn-login-approval'); ?></li>
				<li><?php esc_html_e('Click "Detect Chat ID automatically" — the plugin will read the message and fill in the field.', 'gatalyn-login-approval'); ?></li>
			</ol>
		</p>
	<?php
	}

	/** Renders the message template textarea. */
	public function field_telegram_message()
	{
		$value       = get_option('gatalyn_telegram_message', '');
		$placeholder = Gatalyn_Telegram::default_message();
	?>
		<textarea
			name="gatalyn_telegram_message"
			id="gatalyn_telegram_message"
			rows="10"
			cols="60"
			class="large-text code"
			placeholder="<?php echo esc_textarea($placeholder); ?>"><?php echo esc_textarea($value); ?></textarea>
		<p class="description">
			<?php esc_html_e('Plain text with basic HTML tags (<b>, <i>, <code>, <a>). Leave empty to use the default template.', 'gatalyn-login-approval'); ?>
		</p>
	<?php
	}

	/** Renders the Telegram test button. */
	public function field_telegram_test()
	{
	?>
		<button
			type="button"
			id="gatalyn-telegram-test-btn"
			class="button button-secondary"
			data-label="<?php esc_attr_e('Send test message', 'gatalyn-login-approval'); ?>"
			data-loading="<?php esc_attr_e('Sending&hellip;', 'gatalyn-login-approval'); ?>">
			<?php esc_html_e('Send test message', 'gatalyn-login-approval'); ?>
		</button>
		<span id="gatalyn-telegram-test-result"></span>
		<p class="description">
			<?php esc_html_e('Uses the Bot Token and Chat ID values currently entered in the fields above (without saving).', 'gatalyn-login-approval'); ?>
		</p>
	<?php
	}

	// -------------------------------------------------------------------------
	// Sanitize callbacks
	// -------------------------------------------------------------------------

	/**
	 * Returns '1' for truthy values, '' otherwise.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	public function hardening_section_description()
	{
	?>
		<p>
			<?php esc_html_e('Optional protections that work independently of the approval flow. Both are off by default because they can lock you out of your own site or disable front-end features.', 'gatalyn-login-approval'); ?>
		</p>
		<p>
			<?php esc_html_e('Addresses listed under Allowed IP Addresses are never locked out.', 'gatalyn-login-approval'); ?>
		</p>
	<?php
	}
	public function field_limit_attempts_enabled()
	{
		$value = get_option('gatalyn_limit_attempts_enabled', '');
	?>
		<label>
			<input
				type="checkbox"
				name="gatalyn_limit_attempts_enabled"
				id="gatalyn_limit_attempts_enabled"
				value="1"
				<?php checked('1', $value); ?> />
			<?php esc_html_e('Temporarily block an IP address after repeated failed logins', 'gatalyn-login-approval'); ?>
		</label>
		<p class="description">
			<?php esc_html_e('A blocked attempt is rejected before the approval step, so no approval email or Telegram message is sent while an address is locked out.', 'gatalyn-login-approval'); ?>
		</p>
	<?php
	}
	public function field_max_attempts()
	{
		$value = (int) get_option('gatalyn_max_attempts', 5);
	?>
		<input
			type="number"
			name="gatalyn_max_attempts"
			id="gatalyn_max_attempts"
			value="<?php echo esc_attr($value); ?>"
			min="1"
			max="50"
			step="1"
			class="small-text" />
		<?php esc_html_e('attempts', 'gatalyn-login-approval'); ?>
		<p class="description">
			<?php esc_html_e('Failed logins allowed from one IP address before the lockout starts (1–50).', 'gatalyn-login-approval'); ?>
		</p>
	<?php
	}
	public function field_lockout_minutes()
	{
		$value = (int) get_option('gatalyn_lockout_minutes', 15);
	?>
		<input
			type="number"
			name="gatalyn_lockout_minutes"
			id="gatalyn_lockout_minutes"
			value="<?php echo esc_attr($value); ?>"
			min="1"
			max="1440"
			step="1"
			class="small-text" />
		<?php esc_html_e('minutes', 'gatalyn-login-approval'); ?>
		<p class="description">
			<?php esc_html_e('How long the address stays blocked (1–1440 minutes).', 'gatalyn-login-approval'); ?>
		</p>
	<?php
	}
	public function field_rest_api_mode()
	{
		$value = (string) get_option('gatalyn_rest_api_mode', 'off');
	?>
		<select name="gatalyn_rest_api_mode" id="gatalyn_rest_api_mode">
			<option value="off" <?php selected('off', $value); ?>>
				<?php esc_html_e('No restriction', 'gatalyn-login-approval'); ?>
			</option>
			<option value="users" <?php selected('users', $value); ?>>
				<?php esc_html_e('Hide the users endpoint from visitors (recommended)', 'gatalyn-login-approval'); ?>
			</option>
			<option value="all" <?php selected('all', $value); ?>>
				<?php esc_html_e('Require login for the whole REST API', 'gatalyn-login-approval'); ?>
			</option>
		</select>
		<p class="description">
			<?php esc_html_e('"Hide the users endpoint" stops /wp-json/wp/v2/users from listing your usernames, which is what makes brute-force attacks cheap. It affects nothing else, so it is safe to leave on.', 'gatalyn-login-approval'); ?>
		</p>
		<p class="description">
			<strong><?php esc_html_e('Warning:', 'gatalyn-login-approval'); ?></strong>
			<?php esc_html_e('"Require login for the whole REST API" will break any front-end feature that calls the API without a session, such as contact forms, AJAX search, store endpoints, or page builders. Only oEmbed stays public. Test your site after enabling it.', 'gatalyn-login-approval'); ?>
		</p>
	<?php
	}
	public function sanitize_checkbox($value)
	{
		return $value ? '1' : '';
	}
	public function sanitize_max_attempts($value)
	{
		$value = absint($value);
		return max(1, min(50, $value));
	}
	public function sanitize_lockout_minutes($value)
	{
		$value = absint($value);
		return max(1, min(1440, $value));
	}
	public function sanitize_rest_api_mode($value)
	{
		return in_array($value, array('off', 'users', 'all'), true) ? $value : 'off';
	}

	/**
	 * Ensures the mode is one of the allowed values.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	public function sanitize_mode($value)
	{
		return in_array($value, array('before', 'after'), true) ? $value : 'before';
	}

	/**
	 * Validates each line as an IP address or IPv4 CIDR range.
	 * Invalid lines are silently discarded.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public function sanitize_allowed_ips($value)
	{
		$lines     = array_filter(array_map('trim', explode("\n", (string) $value)));
		$valid     = array();

		foreach ($lines as $line) {
			if (strpos($line, '/') !== false) {
				// IPv4 CIDR.
				$parts = explode('/', $line, 2);
				if (
					filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
					&& is_numeric($parts[1])
					&& (int) $parts[1] >= 0
					&& (int) $parts[1] <= 32
				) {
					$valid[] = $line;
				}
			} elseif (filter_var($line, FILTER_VALIDATE_IP)) {
				$valid[] = $line;
			}
		}

		return implode("\n", $valid);
	}

	/**
	 * Clamps the token expiry to a sensible range (1–1440 minutes).
	 *
	 * @param mixed $value
	 *
	 * @return int
	 */
	public function sanitize_token_expiry($value)
	{
		$value = absint($value);
		return max(1, min(1440, $value));
	}

	/**
	 * Validates that the Telegram bot token matches the expected format
	 * (digits:alphanumeric string). Returns the value as-is but shows a
	 * warning if the format looks wrong.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	public function sanitize_telegram_token($value)
	{
		$value = sanitize_text_field((string) $value);
		// Telegram tokens look like: 123456789:ABCdefGhIJKlmNoPQRstuVWXyz_-
		if ('' !== $value && ! preg_match('/^\d+:[A-Za-z0-9_-]{35,}$/', $value)) {
			add_settings_error(
				'gatalyn_telegram_bot_token',
				'gatalyn_telegram_token_invalid',
				__('The Telegram bot token format appears to be invalid. It should look like: 123456789:ABCdefGhIJKlmNoPQRstuVWXyz', 'gatalyn-login-approval'),
				'warning'
			);
		}
		return $value;
	}

	/**
	 * Sanitizes the Telegram message allowing only basic HTML tags
	 * supported by Telegram (b, strong, i, em, u, ins, s, strike, del, a, code, pre).
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public function sanitize_telegram_message($value)
	{
		$allowed_html = array(
			'b'      => array(),
			'strong' => array(),
			'i'      => array(),
			'em'     => array(),
			'u'      => array(),
			'ins'    => array(),
			's'      => array(),
			'strike' => array(),
			'del'    => array(),
			'a'      => array(
				'href' => array(),
			),
			'code'   => array(
				'class' => array(),
			),
			'pre'    => array(),
		);

		return wp_kses($value, $allowed_html);
	}
}
