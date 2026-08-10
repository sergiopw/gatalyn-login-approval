<?php

/**
 * Login page integration for Login Approval.
 *
 * Hooks into the WordPress login page (wp-login.php) to implement the
 * approval flow. Two modes are supported:
 *
 * "before" mode
 *   1. User visits login page -> plugin shows a form asking for their
 *      username/email.
 *   2. An approval link is emailed to the user's registered address.
 *   3. User clicks the link -> a short-lived session cookie is set and
 *      they are redirected to the normal login form.
 *   4. User enters their password and logs in normally.
 *      The authenticate filter verifies the session cookie before
 *      allowing the login to proceed.
 *
 * "after" mode
 *   1. Normal login form shown.
 *   2. User enters valid credentials -> plugin intercepts, sends an
 *      approval email, and redirects to the pending page.
 *   3. User clicks the approval link -> they are logged in immediately.
 *
 * IP whitelisting skips the approval step for pre-approved addresses.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Gatalyn_Login
{

    /** @var bool */
    private $enabled;

    /** @var string 'before' | 'after' */
    private $mode;

    /** @var string[] */
    private $allowed_ips;

    /**
     * Registers all login-related hooks.
     */
    public function init()
    {
        $raw_enabled   = get_option('gatalyn_enabled', '1');
        $this->enabled = ! in_array($raw_enabled, array('', '0', 0, false, 'false', 'off'), true);
        $this->mode    = get_option('gatalyn_mode', 'before');
        if (! in_array($this->mode, array('before', 'after'), true)) {
            $this->mode = 'before';
        }
        $raw_ips           = (string) get_option('gatalyn_allowed_ips', '');
        $this->allowed_ips = array_filter(array_map('trim', explode("\n", $raw_ips)));

        if (! $this->enabled) {
            return;
        }

        // Handle AJAX-like check_approval VERY early (before login_init)
        // This must happen before wp-login.php starts rendering any HTML
        if ('gatalyn_check_approval' === $this->get_current_action()) {
            add_action('init', array($this, 'handle_check_approval_early'), PHP_INT_MIN);
        }

        add_action('login_init', array($this, 'prevent_login_page_cache'), 1);
        add_action('login_init', array($this, 'handle_login_init'));

        if ('before' === $this->mode) {
            add_action('login_form_login', array($this, 'maybe_render_pre_login_form'));
            add_action('wp_loaded', array($this, 'maybe_render_pre_login_form'), 1);
            add_action('login_form', array($this, 'maybe_output_session_field'));
        }

        add_filter('login_message', array($this, 'login_message_filter'));

        if ('after' === $this->mode) {
            add_filter('authenticate', array($this, 'after_mode_intercept'), 30, 3);
            add_action('login_footer', array($this, 'output_active_marker'));
        } else {
            add_filter('authenticate', array($this, 'before_mode_enforce'), 30, 3);
        }

        if (is_admin() && ! wp_doing_ajax()) {
            add_action('admin_init', array($this, 'handle_health_check_request'));
            add_action('admin_notices', array($this, 'show_health_notice'));
        }
    }

	// -------------------------------------------------------------------------
	// login_init dispatcher
	// -------------------------------------------------------------------------

    /**
     * Fires early in wp-login.php before any output is sent.
     * Handles all custom Gatalyn actions and, in before-mode, intercepts the
     * default login display for users without a valid session.
     */
    public function handle_login_init()
    {
        $request_method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : 'GET';

        $action = isset($_REQUEST['action']) // phpcs:ignore WordPress.Security.NonceVerification
            ? sanitize_key(wp_unslash($_REQUEST['action'])) // phpcs:ignore WordPress.Security.NonceVerification
            : 'login';

        if ('' === $action) {
            $action = 'login';
        }

        if ('gatalyn_approve' === $action) {
            $this->handle_approve();
            return;
        }

        if ('gatalyn_deny' === $action) {
            $this->handle_deny();
            return;
        }

        if ('gatalyn_request_approval' === $action) {
            $this->handle_request_approval();
            return;
        }

        if ('gatalyn_pending' === $action) {
            $this->show_pending_page();
            exit;
        }

        if ('gatalyn_check_approval' === $action) {
            $this->handle_check_approval();
            return;
        }

        if (
            'before' === $this->mode
            && 'GET' === $request_method
            && ! is_user_logged_in()
            && ! $this->is_ip_allowed()
            && ! $this->is_core_non_login_action($action)
            && ! $this->is_gatalyn_action($action)
            && ! $this->has_valid_session()
        ) {
            $this->show_pre_login_form();
            exit;
        }
    }

    /**
     * Prevents caches from serving stale wp-login.php responses.
     */
    public function prevent_login_page_cache()
    {
        nocache_headers();

        if (! defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }

        if (! defined('DONOTCACHEDB')) {
            define('DONOTCACHEDB', true);
        }

        if (! defined('DONOTMINIFY')) {
            define('DONOTMINIFY', true);
        }
    }

    /**
     * Fallback renderer for the pre-login form in before mode.
     */
    public function maybe_render_pre_login_form()
    {
        if (! $this->is_login_request()) {
            return;
        }

        $action = isset($_REQUEST['action']) // phpcs:ignore WordPress.Security.NonceVerification
            ? sanitize_key(wp_unslash($_REQUEST['action'])) // phpcs:ignore WordPress.Security.NonceVerification
            : 'login';

        if (! $this->enabled || 'before' !== $this->mode) {
            return;
        }

        $request_method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : 'GET';

        if ('GET' !== $request_method) {
            return;
        }

        if (is_user_logged_in() || $this->is_ip_allowed() || $this->is_core_non_login_action($action)) {
            return;
        }

        // Don't intercept Gatalyn's own action routes (approve, deny, pending, etc.)
        if ($this->is_gatalyn_action($action)) {
            return;
        }

        if ($this->has_valid_session()) {
            return;
        }

        $this->show_pre_login_form();
        exit;
    }

	// -------------------------------------------------------------------------
	// Custom action handlers
	// -------------------------------------------------------------------------

    /**
     * Processes the pre-login form submission (before mode).
     * Always redirects to the pending page to avoid user enumeration.
     */
    private function handle_request_approval()
    {
        if (
            ! isset($_POST['_wpnonce']) ||
            ! wp_verify_nonce(sanitize_key(wp_unslash($_POST['_wpnonce'])), 'gatalyn_request_approval')
        ) {
            wp_safe_redirect(add_query_arg('gatalyn_error', 'security', wp_login_url()));
            exit;
        }

        $username    = sanitize_text_field(wp_unslash($_POST['gatalyn_username'] ?? ''));
        $redirect_to = sanitize_url(wp_unslash($_POST['redirect_to'] ?? ''));

        if (empty($username)) {
            wp_safe_redirect(
                add_query_arg(
                    array(
                        'gatalyn_error' => 'empty_username',
                    ),
                    wp_login_url()
                )
            );
            exit;
        }

        $user = get_user_by('login', $username);
        if (! $user) {
            $user = get_user_by('email', $username);
        }

        if ($user) {
            // CRITICAL: Clear any revocation marker BEFORE generating new tokens
            // This ensures a fresh approval request isn't blocked by old denies
            Gatalyn_Token::clear_user_revoked($user->ID);

            // Verify revocation was actually cleared (important for Hostinger)
            // Try again if needed
            if (Gatalyn_Token::is_user_revoked($user->ID)) {
                Gatalyn_Token::clear_user_revoked($user->ID);
            }

            $pair = Gatalyn_Token::generate_approval_pair(
                $user->ID,
                'before',
                $redirect_to,
                $username
            );

            // Send notification via the configured channel(s).
            if (get_option('gatalyn_email_enabled', '1')) {
                Gatalyn_Email::send_approval(
                    $user,
                    $pair['approve'],
                    $pair['deny'],
                    'before',
                    $username
                );
            }
            if (get_option('gatalyn_telegram_enabled', '')) {
                Gatalyn_Telegram::send_approval(
                    $user,
                    $pair['approve'],
                    $pair['deny'],
                    'before',
                    $username
                );
            }

            Gatalyn_Token::initialize_request_status(
                $pair['request_id'],
                'before',
                $user->ID,
                $user->user_login,
                $redirect_to
            );

            $this->show_pending_page($pair['request_id']);
            exit;
        }

        // User not found: show the same pending page with a fake request_id to
        // prevent user enumeration. The check_approval endpoint returns 'pending'
        // for unknown IDs, so the page looks identical and leaks nothing.
        $fake_request_id = 'x' . bin2hex(random_bytes(16));
        $this->show_pending_page($fake_request_id);
        exit;
    }

    /**
     * Processes an approval link click.
     */
    private function handle_approve()
    {
        $token = isset($_GET['token']) // phpcs:ignore WordPress.Security.NonceVerification
            ? trim(sanitize_text_field(wp_unslash($_GET['token']))) // phpcs:ignore WordPress.Security.NonceVerification
            : '';

        $data = Gatalyn_Token::verify_approval($token);

        if (false === $data || ('approve' !== ($data['decision'] ?? 'approve'))) {
            wp_safe_redirect(add_query_arg('gatalyn_error', 'invalid_token', wp_login_url()));
            exit;
        }

        Gatalyn_Token::delete_approval($token);

        $user = get_user_by('id', $data['user_id']);
        if (! $user) {
            wp_safe_redirect(add_query_arg('gatalyn_error', 'invalid_token', wp_login_url()));
            exit;
        }

        Gatalyn_Token::clear_user_revoked($user->ID);

        if ('after' === $data['mode']) {
            wp_set_auth_cookie($user->ID, false);
            do_action('wp_login', $user->user_login, $user);

            $redirect_to = ! empty($data['redirect_to'])
                ? $data['redirect_to']
                : admin_url();

            $redirect_to = apply_filters('login_redirect', $redirect_to, $redirect_to, $user);

            wp_safe_redirect($redirect_to);
            exit;
        }

        $session_token = Gatalyn_Token::generate_session($user->ID, $user->user_login);
        $secure        = is_ssl();

        if (! empty($data['request_id'])) {
            Gatalyn_Token::update_request_status(
                $data['request_id'],
                'approved',
                array(
                    'session_token' => $session_token,
                    'user_login'    => $user->user_login,
                )
            );
        }

        // Set session cookie with explicit parameters
        setcookie(
            'gatalyn_session',
            $session_token,
            array(
                'expires'  => time() + 30 * MINUTE_IN_SECONDS,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );

        // Also use header to ensure it's sent
        header('Set-Cookie: gatalyn_session=' . rawurlencode($session_token) . '; Max-Age=' . (30 * MINUTE_IN_SECONDS) . '; Path=' . (COOKIEPATH ?: '/') . '; ' . ($secure ? 'Secure; ' : '') . 'HttpOnly; SameSite=Lax', false);

        wp_safe_redirect(
            add_query_arg(
                array(
                    'gatalyn_approved' => '1',
                    'log'          => rawurlencode($user->user_login),
                    'gatalyn_request_id' => rawurlencode($data['request_id'] ?? ''),
                ),
                wp_login_url()
            )
        );
        exit;
    }

    /**
     * Processes a deny link click.
     */
    private function handle_deny()
    {
        $token = isset($_GET['token']) // phpcs:ignore WordPress.Security.NonceVerification
            ? trim(sanitize_text_field(wp_unslash($_GET['token']))) // phpcs:ignore WordPress.Security.NonceVerification
            : '';

        $data = Gatalyn_Token::verify_approval($token);

        if (false === $data || 'deny' !== ($data['decision'] ?? 'deny')) {
            wp_safe_redirect(add_query_arg('gatalyn_error', 'invalid_token', wp_login_url()));
            exit;
        }

        Gatalyn_Token::delete_approval($token);

        $user = get_user_by('id', $data['user_id']);
        if (! $user) {
            wp_safe_redirect(add_query_arg('gatalyn_error', 'invalid_token', wp_login_url()));
            exit;
        }

        Gatalyn_Token::mark_user_revoked($user->ID);

        if (! empty($data['request_id'])) {
            Gatalyn_Token::update_request_status($data['request_id'], 'denied');
        }

        if (class_exists('WP_Session_Tokens')) {
            $manager = WP_Session_Tokens::get_instance($user->ID);
            $manager->destroy_all();
        }

        wp_clear_auth_cookie();
        $this->clear_session_cookie();

        wp_safe_redirect(add_query_arg('gatalyn_error', 'denied', wp_login_url()));
        exit;
    }

	// -------------------------------------------------------------------------
	// authenticate filter hooks
	// -------------------------------------------------------------------------

    /**
     * "after" mode authenticate filter.
     *
     * @param WP_User|WP_Error|null $user
     * @param string                $username
     * @param string                $password
     *
     * @return WP_User|WP_Error|null
     */
    public function after_mode_intercept($user, $username, $password)
    {
        if (! ($user instanceof WP_User)) {
            return $user;
        }

        if ($this->is_ip_allowed()) {
            return $user;
        }

        $redirect_to = sanitize_url(wp_unslash($_POST['redirect_to'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification

        Gatalyn_Token::clear_user_revoked($user->ID);

        $pair = Gatalyn_Token::generate_approval_pair(
            $user->ID,
            'after',
            $redirect_to,
            $username
        );

        // Send notification via the configured channel(s).
        if (get_option('gatalyn_email_enabled', '1')) {
            Gatalyn_Email::send_approval(
                $user,
                $pair['approve'],
                $pair['deny'],
                'after',
                $username
            );
        }
        if (get_option('gatalyn_telegram_enabled', '')) {
            Gatalyn_Telegram::send_approval(
                $user,
                $pair['approve'],
                $pair['deny'],
                'after',
                $username
            );
        }

        $this->show_pending_page();
        exit;
    }

    /**
     * "before" mode authenticate filter.
     *
     * @param WP_User|WP_Error|null $user
     * @param string                $username
     * @param string                $password
     *
     * @return WP_User|WP_Error|null
     */
    public function before_mode_enforce($user, $username, $password)
    {
        if (! ($user instanceof WP_User)) {
            return $user;
        }

        if ($this->is_ip_allowed()) {
            return $user;
        }

        if (Gatalyn_Token::is_user_revoked($user->ID)) {
            $this->clear_session_cookie();

            return new WP_Error(
                'gatalyn_revoked',
                __('<strong>Error:</strong> This login request has been denied. Please request a new approval email.', 'gatalyn-login-approval')
            );
        }

        $request_status = $this->get_approved_request_status();
        if (is_array($request_status)) {
            if ((int) ($request_status['user_id'] ?? 0) !== $user->ID) {
                return new WP_Error(
                    'gatalyn_request_mismatch',
                    sprintf(
                        /* translators: %s: login URL */
                        __('<strong>Error:</strong> Approval mismatch. Please <a href="%s">request a new approval</a>.', 'gatalyn-login-approval'),
                        esc_url(wp_login_url())
                    )
                );
            }

            Gatalyn_Token::delete_request_status($this->get_request_id());
            $this->clear_session_cookie();

            return $user;
        }

        $session_token = $this->get_request_session_token();
        $session_data  = Gatalyn_Token::verify_session($session_token);

        if (false === $session_data) {
            return new WP_Error(
                'gatalyn_no_session',
                sprintf(
                    /* translators: %s: login URL */
                    __('<strong>Error:</strong> Login requires email approval. Please <a href="%s">request approval</a> first.', 'gatalyn-login-approval'),
                    esc_url(wp_login_url())
                )
            );
        }

        if ((int) $session_data['user_id'] !== $user->ID) {
            Gatalyn_Token::delete_session($session_token);
            $this->clear_session_cookie();

            return new WP_Error(
                'gatalyn_session_mismatch',
                sprintf(
                    /* translators: %s: login URL */
                    __('<strong>Error:</strong> Session mismatch. Please <a href="%s">request a new approval</a>.', 'gatalyn-login-approval'),
                    esc_url(wp_login_url())
                )
            );
        }

        Gatalyn_Token::delete_session($session_token);
        $this->clear_session_cookie();

        return $user;
    }

	// -------------------------------------------------------------------------
	// login_message filter
	// -------------------------------------------------------------------------

    /**
     * Adds informational banners to the login form.
     *
     * @param string $message Existing message HTML.
     *
     * @return string
     */
    public function login_message_filter($message)
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        if (isset($_GET['gatalyn_approved'])) {
            $message .= '<p class="message">'
                . esc_html__('Your login has been approved. Please enter your password to continue.', 'gatalyn-login-approval')
                . '</p>';
        }

        if (isset($_GET['gatalyn_error'])) {
            $error = sanitize_key(wp_unslash($_GET['gatalyn_error']));

            $error_messages = array(
                'invalid_token' => __('The approval link is invalid or has expired. Please try again.', 'gatalyn-login-approval'),
                'security'      => __('Security check failed. Please try again.', 'gatalyn-login-approval'),
                'denied'        => __('Login request denied. Any approved login session has been revoked.', 'gatalyn-login-approval'),
            );

            if (isset($error_messages[$error])) {
                $message .= '<p class="message" style="border-left-color:#cc0000;color:#cc0000;">'
                    . esc_html($error_messages[$error])
                    . '</p>';
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $message;
    }

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

    /**
     * Renders the approval pending page.
     */
    private function show_pending_page($request_id = '')
    {
        if (empty($request_id) && isset($_GET['request_id'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $request_id = sanitize_text_field(wp_unslash($_GET['request_id'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        // The status poller only makes sense once we have a request to poll for.
        // Enqueued before login_header() so it is printed by login_footer().
        if (! empty($request_id)) {
            wp_enqueue_script(
                'gatalyn-pending-poll',
                plugins_url('assets/js/pending-poll.js', GATALYN_PLUGIN_FILE),
                array(),
                GATALYN_VERSION,
                true
            );

            wp_add_inline_script(
                'gatalyn-pending-poll',
                'window.GatalynPendingPoll = ' . wp_json_encode(
                    array(
                        'checkUrl'  => add_query_arg(
                            array(
                                'action'     => 'gatalyn_check_approval',
                                'request_id' => $request_id,
                            ),
                            wp_login_url()
                        ),
                        'requestId' => $request_id,
                        'strings'   => array(
                            'denied'  => __('Approval denied.', 'gatalyn-login-approval'),
                            'expired' => __('Request expired. Please request a new approval.', 'gatalyn-login-approval'),
                        ),
                    )
                ) . ';',
                'before'
            );
        }

        $message = '<p class="message">'
            . esc_html__('An approval link has been sent to your registered email address. Please check your inbox and click the link to proceed.', 'gatalyn-login-approval')
            . '</p>';

        login_header(__('Approval Sent', 'gatalyn-login-approval'), $message);
?>
        <p style="text-align:center;padding:0 24px 12px;">
            <?php esc_html_e('This page will update automatically once the approval link is opened, even if it is approved from another device.', 'gatalyn-login-approval'); ?>
        </p>
        <p id="gatalyn-pending-status" class="message" style="margin:0 24px 20px;<?php echo empty($request_id) ? 'display:none;' : ''; ?>">
            <?php esc_html_e('Waiting for approval...', 'gatalyn-login-approval'); ?>
        </p>
        <p style="text-align:center;padding:0 24px 24px;">
            <a href="<?php echo esc_url(wp_login_url()); ?>">
                &larr; <?php esc_html_e('Back to Login', 'gatalyn-login-approval'); ?>
            </a>
        </p>
    <?php
        login_footer();
    }

    /**
     * Returns the current approval state for a pending before-mode request.
     */
    private function handle_check_approval()
    {
        $request_id = isset($_GET['request_id']) // phpcs:ignore WordPress.Security.NonceVerification
            ? sanitize_text_field(wp_unslash($_GET['request_id'])) // phpcs:ignore WordPress.Security.NonceVerification
            : '';

        if (empty($request_id)) {
            wp_send_json_error(array('message' => 'Invalid request ID'), 400);
        }

        // Prevent caching
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $status = Gatalyn_Token::get_request_status($request_id);

        if (! is_array($status)) {
            wp_send_json_success(array('status' => 'pending'));
            return;
        }

        if ('approved' === ($status['status'] ?? '')) {
            $session_token = $status['session_token'] ?? '';
            if (! empty($session_token)) {
                $secure = is_ssl();
                setcookie(
                    'gatalyn_session',
                    $session_token,
                    array(
                        'expires'  => time() + 30 * MINUTE_IN_SECONDS,
                        'path'     => COOKIEPATH ?: '/',
                        'domain'   => COOKIE_DOMAIN ?: '',
                        'secure'   => $secure,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    )
                );
            }
            wp_send_json_success(
                array(
                    'status'       => 'approved',
                    'redirect_url' => add_query_arg(
                        array(
                            'gatalyn_approved'      => '1',
                            'log'               => rawurlencode($status['user_login'] ?? ''),
                            'gatalyn_request_id'    => rawurlencode($request_id),
                        ),
                        wp_login_url()
                    ),
                )
            );
            return;
        }

        if ('denied' === ($status['status'] ?? '')) {
            wp_send_json_success(
                array(
                    'status'  => 'denied',
                    'message' => __('The approval request was denied. Please request a new approval email.', 'gatalyn-login-approval'),
                )
            );
            return;
        }

        wp_send_json_success(array('status' => 'pending'));
    }

    /**
     * Adds the transient session token to the normal login form after the
     * email approval link has been opened in before mode.
     */
    public function maybe_output_session_field()
    {
        if ('before' !== $this->mode || ! $this->is_login_request()) {
            return;
        }

        $request_id = $this->get_request_id();
        if (! empty($request_id) && is_array($this->get_approved_request_status())) {
            echo '<input type="hidden" name="gatalyn_request_id" value="' . esc_attr($request_id) . '" />';
        }

        $session_token = $this->get_request_session_token();
        if (empty($session_token) || false === Gatalyn_Token::verify_session($session_token)) {
            return;
        }

        echo '<input type="hidden" name="gatalyn_session_token" value="' . esc_attr($session_token) . '" />';
    }

    /**
     * Renders the pre-login form (before mode).
     */
    private function show_pre_login_form()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $gatalyn_error    = isset($_GET['gatalyn_error']) ? sanitize_key(wp_unslash($_GET['gatalyn_error'])) : '';
        $redirect_to  = isset($_GET['redirect_to']) ? sanitize_url(wp_unslash($_GET['redirect_to'])) : admin_url();
        $pre_username = isset($_GET['log']) ? sanitize_text_field(wp_unslash($_GET['log'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        $error_messages = array(
            'empty_username' => __('Please enter your username or email address.', 'gatalyn-login-approval'),
            'security'       => __('Security check failed. Please try again.', 'gatalyn-login-approval'),
        );

        $error_html = '';
        if ($gatalyn_error && isset($error_messages[$gatalyn_error])) {
            $error_html = '<div id="login_error">' . esc_html($error_messages[$gatalyn_error]) . '<br /></div>';
        }

        $header_message = '<p class="message">'
            . esc_html__('Please enter your username or email address. An approval link will be sent to your registered email.', 'gatalyn-login-approval')
            . '</p>';

        login_header(__('Login Approval', 'gatalyn-login-approval'), $header_message);

        echo $error_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>
        <form name="gatalyn_approval_form" id="loginform"
            action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>"
            method="post">

            <input type="hidden" name="action" value="gatalyn_request_approval" />
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>" />
            <?php wp_nonce_field('gatalyn_request_approval'); ?>

            <p>
                <label for="user_login">
                    <?php esc_html_e('Username or Email Address', 'gatalyn-login-approval'); ?>
                </label>
                <input
                    type="text"
                    name="gatalyn_username"
                    id="user_login"
                    class="input"
                    value="<?php echo esc_attr($pre_username); ?>"
                    size="20"
                    autocapitalize="off"
                    autocomplete="username" />
            </p>

            <?php do_action('login_form'); ?>

            <p class="submit">
                <input
                    type="submit"
                    name="wp-submit"
                    id="wp-submit"
                    class="button button-primary button-large"
                    value="<?php esc_attr_e('Send Approval Link', 'gatalyn-login-approval'); ?>" />
            </p>
        </form>

        <p id="nav">
            <?php if (get_option('users_can_register')) : ?>
                <a href="<?php echo esc_url(wp_registration_url()); ?>">
                    <?php esc_html_e('Register', 'gatalyn-login-approval'); ?>
                </a>
                &nbsp;|&nbsp;
            <?php endif; ?>
            <a href="<?php echo esc_url(wp_lostpassword_url()); ?>">
                <?php esc_html_e('Lost your password?', 'gatalyn-login-approval'); ?>
            </a>
        </p>

<?php
        echo '<!-- gatalyn-plugin-active -->';
        login_footer();
    }

	// -------------------------------------------------------------------------
	// Login page health check
	// -------------------------------------------------------------------------

    /**
     * Outputs the hidden marker used by the health check.
     */
    public function output_active_marker()
    {
        echo '<!-- gatalyn-plugin-active -->';
    }

    /**
     * Performs a loopback check against wp-login.php and stores the result.
     */
    public function handle_health_check_request()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['gatalyn_recheck']) && check_admin_referer('gatalyn_recheck')) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            delete_transient('gatalyn_health_result');
            wp_safe_redirect(remove_query_arg(array('gatalyn_recheck', '_wpnonce')));
            exit;
        }

        if (false !== get_transient('gatalyn_health_result')) {
            return;
        }

        $response = wp_remote_get(
            wp_login_url(),
            array(
                'timeout'   => 5,
                'sslverify' => false,
            )
        );

        if (is_wp_error($response)) {
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $ok   = false !== strpos($body, 'gatalyn-plugin-active');

        set_transient('gatalyn_health_result', $ok ? 'ok' : 'fail', HOUR_IN_SECONDS);
    }

    /**
     * Shows an admin warning when wp-login.php appears to be served from stale cache.
     */
    public function show_health_notice()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if ('fail' !== get_transient('gatalyn_health_result')) {
            return;
        }

        $recheck_url = wp_nonce_url(add_query_arg('gatalyn_recheck', '1'), 'gatalyn_recheck');

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
            esc_html__('Login Approval:', 'gatalyn-login-approval'),
            esc_html__('The plugin was not detected on wp-login.php. The page may be served from a stale server cache. Please run Flush Cache in your hosting panel and then', 'gatalyn-login-approval'),
            esc_url($recheck_url),
            esc_html__('check again.', 'gatalyn-login-approval')
        );
    }

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

    /**
     * Returns true if the current request's IP is in the whitelist.
     *
     * @return bool
     */
    private function is_ip_allowed()
    {
        if (empty($this->allowed_ips)) {
            return false;
        }

        $client_ip = $this->get_client_ip();

        foreach ($this->allowed_ips as $allowed) {
            if (strpos($allowed, '/') !== false) {
                if ($this->ip_in_cidr($client_ip, $allowed)) {
                    return true;
                }
            } elseif ($allowed === $client_ip) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether $ip falls within the IPv4 CIDR range $cidr.
     *
     * @param string $ip IPv4 address.
     * @param string $cidr CIDR notation.
     *
     * @return bool
     */
    private function ip_in_cidr($ip, $cidr)
    {
        $parts = explode('/', $cidr, 2);
        if (2 !== count($parts)) {
            return false;
        }

        $subnet = $parts[0];
        $prefix = (int) $parts[1];

        if ($prefix < 0 || $prefix > 32) {
            return false;
        }

        $ip_long     = ip2long($ip);
        $subnet_long = ip2long($subnet);

        if (false === $ip_long || false === $subnet_long) {
            return false;
        }

        $mask = $prefix > 0 ? (~0 << (32 - $prefix)) : 0;

        return ($ip_long & $mask) === ($subnet_long & $mask);
    }

    /**
     * Returns true if a valid Gatalyn session cookie is present.
     *
     * @return bool
     */
    private function has_valid_session()
    {
        if (is_array($this->get_approved_request_status())) {
            return true;
        }

        $session_token = $this->get_request_session_token();

        return false !== Gatalyn_Token::verify_session($session_token);
    }

    /**
     * Returns the approved request identifier from GET or POST.
     *
     * @return string
     */
    private function get_request_id()
    {
        if (isset($_POST['gatalyn_request_id'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return trim(sanitize_text_field(wp_unslash($_POST['gatalyn_request_id']))); // phpcs:ignore WordPress.Security.NonceVerification
        }

        if (isset($_GET['gatalyn_request_id'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return trim(sanitize_text_field(wp_unslash($_GET['gatalyn_request_id']))); // phpcs:ignore WordPress.Security.NonceVerification
        }

        return '';
    }

    /**
     * Returns the approved shared request status, if any.
     *
     * @return array|false
     */
    private function get_approved_request_status()
    {
        $request_id = $this->get_request_id();
        $status     = Gatalyn_Token::get_request_status($request_id);

        if (! is_array($status) || 'approved' !== ($status['status'] ?? '')) {
            return false;
        }

        return $status;
    }

    /**
     * Returns the Gatalyn session token from cookie, POST, or GET.
     *
     * Cookie remains the primary transport, but GET/POST act as a fallback for
     * hosts or browsers where the pre-password redirect loses the custom cookie.
     *
     * @return string
     */
    private function get_request_session_token()
    {
        if (isset($_COOKIE['gatalyn_session'])) {
            $token = trim(sanitize_text_field(wp_unslash($_COOKIE['gatalyn_session'])));
            if (preg_match('/^[a-f0-9]{64}$/i', $token)) {
                return $token;
            }
        }

        if (isset($_POST['gatalyn_session_token'])) { // phpcs:ignore WordPress.Security.NonceVerification
            $token = trim(sanitize_text_field(wp_unslash($_POST['gatalyn_session_token']))); // phpcs:ignore WordPress.Security.NonceVerification
            if (preg_match('/^[a-f0-9]{64}$/i', $token)) {
                return $token;
            }
        }

        // GET fallback removed for security reasons

        return '';
    }

    /**
     * Clears the Gatalyn session cookie.
     */
    private function clear_session_cookie()
    {
        setcookie(
            'gatalyn_session',
            '',
            time() - HOUR_IN_SECONDS,
            COOKIEPATH,
            COOKIE_DOMAIN
        );
    }

    /**
     * Returns the client's IP address.
     *
     * @return string
     */
    private function get_client_ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        if (defined('GATALYN_TRUST_PROXY') && GATALYN_TRUST_PROXY) {
            $forwarded = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))
                : '';

            if (! empty($forwarded)) {
                $parts = explode(',', $forwarded);
                $ip    = trim($parts[0]);
            }
        }

        return $ip;
    }

    /**
     * Returns true when the current login action is not the standard login form.
     *
     * @param string $action Current wp-login action.
     *
     * @return bool
     */
    private function is_core_non_login_action($action)
    {
        $non_login_actions = array(
            'lostpassword',
            'retrievepassword',
            'resetpass',
            'rp',
            'register',
            'postpass',
            'confirmaction',
            'confirm_admin_email',
            'logout',
        );

        return in_array($action, $non_login_actions, true);
    }

    /**
     * Returns true when the given action belongs to Gatalyn itself (approve, deny, etc.).
     * These must never be intercepted by the pre-login form gate.
     *
     * @param string $action Current wp-login action.
     *
     * @return bool
     */
    private function is_gatalyn_action($action)
    {
        $gatalyn_actions = array(
            'gatalyn_approve',
            'gatalyn_deny',
            'gatalyn_pending',
            'gatalyn_request_approval',
            'gatalyn_check_approval',
        );

        return in_array($action, $gatalyn_actions, true);
    }

    /**
     * Returns true when the current request targets wp-login.php.
     *
     * @return bool
     */
    private function is_login_request()
    {
        if (isset($GLOBALS['pagenow']) && 'wp-login.php' === $GLOBALS['pagenow']) {
            return true;
        }

        $script_name = isset($_SERVER['SCRIPT_NAME'])
            ? strtolower(sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME'])))
            : '';

        return false !== strpos($script_name, 'wp-login.php');
    }

    /**
     * Returns the current Gatalyn action from request parameters.
     *
     * @return string
     */
    private function get_current_action()
    {
        $action = isset($_REQUEST['action']) // phpcs:ignore WordPress.Security.NonceVerification
            ? sanitize_key(wp_unslash($_REQUEST['action'])) // phpcs:ignore WordPress.Security.NonceVerification
            : '';

        return $action;
    }

    /**
     * Handles gatalyn_check_approval VERY early (in init hook with PHP_INT_MIN priority).
     * This must execute BEFORE login_init to ensure wp_send_json_success() works
     * and HTML is not rendered.
     *
     * @return void
     */
    public function handle_check_approval_early()
    {
        // Double-check we're in the right action
        if ('gatalyn_check_approval' !== $this->get_current_action()) {
            return;
        }

        // Double-check we're on wp-login.php
        if (! $this->is_login_request()) {
            return;
        }

        $request_id = isset($_GET['request_id']) // phpcs:ignore WordPress.Security.NonceVerification
            ? sanitize_text_field(wp_unslash($_GET['request_id'])) // phpcs:ignore WordPress.Security.NonceVerification
            : '';

        if (empty($request_id)) {
            wp_send_json_error(array('message' => 'Invalid request ID'), 400);
        }

        // Prevent caching
        if (! has_action('wp_send_json_success') ) {
            header('Cache-Control: no-cache, no-store, must-revalidate', true);
            header('Pragma: no-cache', true);
            header('Expires: 0', true);
            header('Content-Type: application/json; charset=UTF-8', true);
        }

        $status = Gatalyn_Token::get_request_status($request_id);

        if (! is_array($status)) {
            wp_send_json_success(array('status' => 'pending'));
        }

        if ('approved' === ($status['status'] ?? '')) {
            $session_token = $status['session_token'] ?? '';
            if (! empty($session_token)) {
                $secure = is_ssl();
                setcookie(
                    'gatalyn_session',
                    $session_token,
                    array(
                        'expires'  => time() + 30 * MINUTE_IN_SECONDS,
                        'path'     => COOKIEPATH ?: '/',
                        'domain'   => COOKIE_DOMAIN ?: '',
                        'secure'   => $secure,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    )
                );
            }
            wp_send_json_success(
                array(
                    'status'       => 'approved',
                    'redirect_url' => add_query_arg(
                        array(
                            'gatalyn_approved'      => '1',
                            'log'               => rawurlencode($status['user_login'] ?? ''),
                            'gatalyn_request_id'    => rawurlencode($request_id),
                        ),
                        wp_login_url()
                    ),
                )
            );
        }

        if ('denied' === ($status['status'] ?? '')) {
            wp_send_json_success(
                array(
                    'status'  => 'denied',
                    'message' => __('The approval request was denied. Please request a new approval email.', 'gatalyn-login-approval'),
                )
            );
        }

        wp_send_json_success(array('status' => 'pending'));
    }
}
