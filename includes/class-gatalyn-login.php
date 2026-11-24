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
     * Whether this request was authenticated with an application password.
     * Those are separate, revocable credentials made for REST and XML-RPC
     * clients that cannot open a login page, so neither approval mode applies.
     *
     * @var bool
     */
    private $app_password_auth = false;

    /** Approval requests allowed per client address and window. */
    const THROTTLE_IP_LIMIT = 10;

    /** Approval requests allowed per username and window. */
    const THROTTLE_USER_LIMIT = 3;

    /** Length of the approval request window, in seconds. */
    const THROTTLE_WINDOW = 900;

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

        // Escape hatch for an owner who can no longer receive approvals:
        // define( 'GATALYN_DISABLE', true ); in wp-config.php.
        if (! $this->enabled || (defined('GATALYN_DISABLE') && GATALYN_DISABLE)) {
            return;
        }

        add_action(
            'application_password_did_authenticate',
            function () {
                $this->app_password_auth = true;
            }
        );

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

        // The marker lets the health check tell a cached wp-login.php from a live
        // one, so it has to be on every form this plugin can leave on screen.
        add_action('login_footer', array($this, 'output_active_marker'));

        if ('after' === $this->mode) {
            add_filter('authenticate', array($this, 'after_mode_intercept'), 30, 3);
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
     * Always shows the pending page, whether or not the user exists and whether
     * or not anything was sent, so the response gives nothing away.
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

        // Too many requests from this address or for this account: answer as
        // for an unknown user and send nothing, so the form can't be used to
        // flood somebody's inbox or Telegram chat.
        $exists  = (bool) $user;
        $blocked = '';

        if ($this->ip_is_locked_out()) {
            $blocked = 'locked';
        } elseif (! $this->allow_approval_request($username)) {
            $blocked = 'throttled';
        }

        if ('' !== $blocked) {
            $user = false;
            Gatalyn_Stats::log_attempt($username, $blocked, 'approval');
        } elseif ($user) {
            Gatalyn_Stats::log_attempt($username, 'sent', 'approval');
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

            Gatalyn_Token::initialize_request_status(
                $pair['request_id'],
                'before',
                $user->ID,
                $user->user_login,
                $redirect_to
            );

            $this->respond_then(
                function () use ($pair) {
                    $this->show_pending_page($pair['request_id']);
                },
                function () use ($user, $pair, $username) {
                    $this->send_approval_messages($user, $pair, 'before', $username);
                }
            );
            exit;
        }

        // User not found (or throttled): show the same pending page with a fake
        // request_id. It has the same shape as a real one and the check_approval
        // endpoint answers 'pending' for unknown ids, so nothing leaks.
        // A name that doesn't exist counts as a failed login, like a wrong
        // password does, so guessing names here shows up in the dashboard and
        // ends in a lockout. Throttled or locked-out requests are not counted again.
        if (! $exists && '' === $blocked) {
            Gatalyn_Stats::log_attempt($username, 'unknown', 'approval');
            Gatalyn_Stats::$quiet = true;
            $this->count_unknown_user($username);
            Gatalyn_Stats::$quiet = false;
        }

        $fake_request_id = bin2hex(random_bytes(16));
        $this->show_pending_page($fake_request_id);
        exit;
    }

    /**
     * Sends the approval link through every configured channel.
     *
     * @param WP_User $user       User asking to log in.
     * @param array   $pair       Result of Gatalyn_Token::generate_approval_pair().
     * @param string  $mode       'before' or 'after'.
     * @param string  $identifier Username or email typed by the visitor.
     */
    private function send_approval_messages($user, $pair, $mode, $identifier)
    {
        if (get_option('gatalyn_email_enabled', '1')) {
            Gatalyn_Email::send_approval($user, $pair['approve'], $pair['deny'], $mode, $identifier);
        }

        if (get_option('gatalyn_telegram_enabled', '')) {
            Gatalyn_Telegram::send_approval($user, $pair['approve'], $pair['deny'], $mode, $identifier);
        }
    }

    /**
     * Sends the page to the browser first and only then runs the slow work
     * (wp_mail and the Telegram call, which can take seconds). Otherwise the
     * response time tells an attacker whether the account exists, and the visitor
     * waits on the mail server.
     *
     * Where the server can't close the connection early (no FastCGI), the work
     * simply runs after the output, so behaviour is the same, only slower.
     *
     * @param callable $render Prints the page.
     * @param callable $work   Runs once the page is on its way.
     */
    private function respond_then($render, $work)
    {
        ignore_user_abort(true);

        ob_start();
        $render();
        $html = ob_get_clean();

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built by the renderer above.

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } else {
            if (function_exists('ob_get_level')) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            flush();
        }

        $work();
    }

    /**
     * Counts an approval request against the per-address and per-username
     * limits. Whitelisted addresses are never limited.
     *
     * @param string $username Username or email typed by the visitor.
     *
     * @return bool False once a limit is exceeded.
     */
    private function allow_approval_request($username)
    {
        if ($this->is_ip_allowed()) {
            return true;
        }

        $ip = Gatalyn_IP::bucket(Gatalyn_IP::get_client_ip());

        $ip_ok   = $this->throttle('ip:' . $ip, self::THROTTLE_IP_LIMIT, self::THROTTLE_WINDOW);
        $user_ok = $this->throttle('user:' . strtolower($username), self::THROTTLE_USER_LIMIT, self::THROTTLE_WINDOW);

        return $ip_ok && $user_ok;
    }

    /**
     * Whether the limiter is on and this address is locked out right now.
     *
     * @return bool
     */
    private function ip_is_locked_out()
    {
        if ('1' !== (string) get_option('gatalyn_limit_attempts_enabled', '') || $this->is_ip_allowed()) {
            return false;
        }

        return Gatalyn_Limit_Login::lockout_remaining(Gatalyn_IP::bucket(Gatalyn_IP::get_client_ip())) > 0;
    }

    /**
     * Counts a request for an unknown user as a failed login.
     *
     * @param string $username Name typed in the form.
     *
     * @return void
     */
    private function count_unknown_user($username)
    {
        if (! empty($GLOBALS['gatalyn_limit_login']) && '1' === (string) get_option('gatalyn_limit_attempts_enabled', '')) {
            // Also records the daily counter.
            $GLOBALS['gatalyn_limit_login']->record_failure($username);
            return;
        }

        Gatalyn_Stats::record('failed');
    }

    /**
     * Fixed-window counter kept in Gatalyn_Store.
     *
     * @param string $bucket Counter name.
     * @param int    $limit  Hits allowed per window.
     * @param int    $window Window length in seconds.
     *
     * @return bool False when the limit was already reached.
     */
    private function throttle($bucket, $limit, $window)
    {
        $key   = Gatalyn_Limit_Login::KEY_PREFIX . 'rq_' . md5($bucket);
        $now   = time();
        $state = Gatalyn_Store::get($key);

        if (! is_array($state) || $now >= (int) ($state['until'] ?? 0)) {
            $state = array('n' => 0, 'until' => $now + $window);
        }

        if ((int) $state['n'] >= $limit) {
            return false;
        }

        $state['n'] = (int) $state['n'] + 1;

        Gatalyn_Store::set($key, $state, max(1, (int) $state['until'] - $now));

        return true;
    }

    /**
     * Whether the current request is a POST.
     *
     * @return bool
     */
    private function is_post_request()
    {
        return isset($_SERVER['REQUEST_METHOD']) && 'POST' === strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])));
    }

    /**
     * Returns the token from the approval link (GET) or its confirmation form (POST).
     *
     * @return string
     */
    private function get_decision_token()
    {
        $token = isset($_REQUEST['token']) // phpcs:ignore WordPress.Security.NonceVerification
            ? trim(sanitize_text_field(wp_unslash($_REQUEST['token']))) // phpcs:ignore WordPress.Security.NonceVerification
            : '';

        return $token;
    }

    /**
     * Shows the "are you sure" page an approval or deny link lands on.
     *
     * The link itself must not decide anything: mail scanners, link previewers
     * and antivirus gateways open every URL in a message, and they would approve
     * the login (or deny it, which also ends the user's sessions) before the
     * owner ever looks at it. Only the button, a POST, does.
     *
     * @param string $decision 'approve' or 'deny'.
     * @param string $token    Token from the link.
     */
    private function show_decision_page($decision, $token)
    {
        $approve = 'approve' === $decision;

        $message = '<p class="message">' . esc_html(
            $approve
                ? __('Someone is trying to log in to this site. Approve only if it is you.', 'gatalyn-login-approval')
                : __('Confirm that you did not try to log in. Any approved login session for your account will be ended.', 'gatalyn-login-approval')
        ) . '</p>';

        login_header(
            $approve ? __('Approve Login', 'gatalyn-login-approval') : __('Deny Login', 'gatalyn-login-approval'),
            $message
        );
        ?>
        <form method="post" action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>">
            <input type="hidden" name="action" value="<?php echo $approve ? 'gatalyn_approve' : 'gatalyn_deny'; ?>" />
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>" />
            <p class="submit" style="text-align:center">
                <input
                    type="submit"
                    class="button button-primary button-large"
                    style="float:none"
                    value="<?php echo esc_attr($approve ? __('Approve Login', 'gatalyn-login-approval') : __('Do NOT Authorize', 'gatalyn-login-approval')); ?>" />
            </p>
        </form>
        <?php
        login_footer();
    }

    /**
     * Processes an approval link: asks for confirmation on GET, acts on POST.
     */
    private function handle_approve()
    {
        $token = $this->get_decision_token();
        $data  = Gatalyn_Token::verify_approval($token);

        if (false === $data || ('approve' !== ($data['decision'] ?? 'approve'))) {
            wp_safe_redirect(add_query_arg('gatalyn_error', 'invalid_token', wp_login_url()));
            exit;
        }

        if (! $this->is_post_request()) {
            $this->show_decision_page('approve', $token);
            exit;
        }

        Gatalyn_Token::delete_approval($token);

        $user = get_user_by('id', $data['user_id']);
        if (! $user) {
            wp_safe_redirect(add_query_arg('gatalyn_error', 'invalid_token', wp_login_url()));
            exit;
        }

        Gatalyn_Token::clear_user_revoked($user->ID);
        Gatalyn_Stats::record('approved');

        if ('after' === $data['mode']) {
            // Let the browser that is waiting on the pending page finish its own
            // login as well; see respond_check_approval().
            if (! empty($data['request_id'])) {
                Gatalyn_Token::update_request_status($data['request_id'], 'approved');
            }

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

        $this->set_session_cookie($session_token);

        wp_safe_redirect(
            add_query_arg(
                array(
                    'gatalyn_approved'   => '1',
                    'log'                => rawurlencode($user->user_login),
                    'gatalyn_request_id' => rawurlencode($data['request_id'] ?? ''),
                ),
                wp_login_url()
            )
        );
        exit;
    }

    /**
     * Processes a deny link: asks for confirmation on GET, acts on POST.
     */
    private function handle_deny()
    {
        $token = $this->get_decision_token();
        $data  = Gatalyn_Token::verify_approval($token);

        if (false === $data || 'deny' !== ($data['decision'] ?? 'deny')) {
            wp_safe_redirect(add_query_arg('gatalyn_error', 'invalid_token', wp_login_url()));
            exit;
        }

        if (! $this->is_post_request()) {
            $this->show_decision_page('deny', $token);
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

        Gatalyn_Stats::record('denied');

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

        if ($this->is_ip_allowed() || $this->app_password_auth) {
            return $user;
        }

        // The approval page is drawn with wp-login.php's own functions, so it
        // can only be produced there. Any other password login (XML-RPC, a
        // front-end login form, a script calling wp_signon) is refused instead
        // of being waved through, which would skip the approval entirely.
        if (! $this->is_login_request() || ! function_exists('login_header')) {
            return new WP_Error(
                'gatalyn_approval_required',
                sprintf(
                    /* translators: %s: login URL */
                    __('<strong>Error:</strong> This site asks for approval of every login. Please <a href="%s">log in on the login page</a>.', 'gatalyn-login-approval'),
                    esc_url(wp_login_url())
                )
            );
        }

        $redirect_to = sanitize_url(wp_unslash($_POST['redirect_to'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification

        Gatalyn_Token::clear_user_revoked($user->ID);

        $pair = Gatalyn_Token::generate_approval_pair(
            $user->ID,
            'after',
            $redirect_to,
            $username
        );

        // Give the waiting page something to poll, so it notices an approval
        // made on another device and finishes the login by itself.
        Gatalyn_Token::initialize_request_status(
            $pair['request_id'],
            'after',
            $user->ID,
            $user->user_login,
            $redirect_to
        );

        $this->respond_then(
            function () use ($pair) {
                $this->show_pending_page($pair['request_id']);
            },
            function () use ($user, $pair, $username) {
                $this->send_approval_messages($user, $pair, 'after', $username);
            }
        );
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

        if ($this->is_ip_allowed() || $this->app_password_auth) {
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
                // The approval was for somebody else: burn it, it must not be
                // usable by whoever tried the wrong account.
                Gatalyn_Token::delete_request_status($this->get_request_id());
                $this->clear_session_cookie();

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
     * Returns the current approval state of a pending request as JSON.
     *
     * The status page polls this. For a before-mode request an approval hands
     * the browser the session cookie that unlocks the password form; for an
     * after-mode request the browser that is waiting finishes the login itself.
     * The request id is 128 random bits known only to the page that asked.
     */
    private function respond_check_approval()
    {
        $request_id = isset($_GET['request_id']) // phpcs:ignore WordPress.Security.NonceVerification
            ? Gatalyn_Token::clean_request_id(sanitize_text_field(wp_unslash($_GET['request_id']))) // phpcs:ignore WordPress.Security.NonceVerification
            : '';

        if ('' === $request_id) {
            wp_send_json_error(array('message' => 'Invalid request ID'), 400);
        }

        nocache_headers();

        $status = Gatalyn_Token::get_request_status($request_id);

        if (! is_array($status)) {
            wp_send_json_success(array('status' => 'pending'));
        }

        if ('approved' === ($status['status'] ?? '')) {
            if ('after' === ($status['mode'] ?? '')) {
                $user = get_user_by('id', (int) ($status['user_id'] ?? 0));

                if (! $user) {
                    wp_send_json_success(array('status' => 'pending'));
                }

                // Single use: the request can't be replayed once it logged someone in.
                Gatalyn_Token::delete_request_status($request_id);

                wp_set_auth_cookie($user->ID, false);
                do_action('wp_login', $user->user_login, $user);

                $redirect_to = ! empty($status['redirect_to']) ? $status['redirect_to'] : admin_url();
                $redirect_to = apply_filters('login_redirect', $redirect_to, $redirect_to, $user);

                wp_send_json_success(
                    array(
                        'status'       => 'approved',
                        'redirect_url' => wp_validate_redirect($redirect_to, admin_url()),
                    )
                );
            }

            $session_token = $status['session_token'] ?? '';
            if (! empty($session_token)) {
                $this->set_session_cookie($session_token);
            }

            wp_send_json_success(
                array(
                    'status'       => 'approved',
                    'redirect_url' => add_query_arg(
                        array(
                            'gatalyn_approved'   => '1',
                            'log'                => rawurlencode($status['user_login'] ?? ''),
                            'gatalyn_request_id' => rawurlencode($request_id),
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

    /**
     * login_init route for the status poll (the early hook normally gets there first).
     */
    private function handle_check_approval()
    {
        $this->respond_check_approval();
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

            <p class="submit" style="text-align:center">
                <input
                    type="submit"
                    name="wp-submit"
                    id="wp-submit"
                    class="button button-primary button-large"
                    style="float:none"
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

        // The loopback request costs up to five seconds, so it only runs where
        // the admin is looking at this plugin, not on every wp-admin page.
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ('gatalyn-settings' !== $page && 'plugins.php' !== ($GLOBALS['pagenow'] ?? '')) {
            return;
        }

        $response = wp_remote_get(
            wp_login_url(),
            array(
                'timeout'   => 5,
                // Same filter core uses for its own loopback requests.
                'sslverify' => apply_filters('https_local_ssl_verify', false),
            )
        );

        // A host that blocks loopback requests would otherwise be retried on
        // every page load; remember the failure for a while instead.
        if (is_wp_error($response)) {
            set_transient('gatalyn_health_result', 'error', 6 * HOUR_IN_SECONDS);

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
        return Gatalyn_IP::is_allowed($this->allowed_ips);
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
     * Sends the Gatalyn session cookie.
     *
     * @param string $token Session token.
     */
    private function set_session_cookie($token)
    {
        setcookie(
            'gatalyn_session',
            $token,
            array(
                'expires'  => time() + 30 * MINUTE_IN_SECONDS,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
    }

    /**
     * Clears the Gatalyn session cookie.
     */
    private function clear_session_cookie()
    {
        setcookie(
            'gatalyn_session',
            '',
            array(
                'expires'  => time() - HOUR_IN_SECONDS,
                'path'     => COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
    }

    /**
     * Returns the client's IP address.
     *
     * @return string
     */
    private function get_client_ip()
    {
        return Gatalyn_IP::get_client_ip();
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

        $this->respond_check_approval();
    }

}
