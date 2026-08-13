<?php

/**
 * Blocks an IP after too many failed login attempts.
 *
 * Hook choice matters here, in two ways.
 *
 * Failures are counted on 'wp_login_failed', which is the action that exists
 * for exactly that and receives the WP_Error describing the failure. The
 * 'wp_authenticate' action is not usable for this: it fires at the top of
 * wp_signon() with the credentials passed by reference, before anything has
 * been validated, so there is nothing to count yet.
 *
 * The block itself runs on the 'authenticate' filter at priority 25, which sits
 * between core's wp_authenticate_username_password() (20) and the approval
 * interceptors in Gatalyn_Login (30). That gap is deliberate: both approval
 * handlers start with `if (! ($user instanceof WP_User)) return $user;`, so the
 * WP_Error returned here travels through them untouched and the approval email
 * or Telegram message is never sent. Blocking any later would notify the site
 * owner on every attempt an attacker makes while locked out.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Gatalyn_Limit_Login
{

    /**
     * Prefix for the per-IP state entry. Kept in sync with uninstall.php and
     * with the prefix list in Gatalyn_Token::run_garbage_collection().
     */
    const KEY_PREFIX = 'gatalyn_ll_';

    /**
     * Error code returned for a locked-out attempt.
     */
    const ERROR_CODE = 'gatalyn_locked_out';

    /**
     * Registers WordPress hooks.
     *
     * @return void
     */
    public function init()
    {
        if ('1' !== (string) get_option('gatalyn_limit_attempts_enabled', '')) {
            return;
        }

        add_action('wp_login_failed', array($this, 'record_failure'), 10, 2);
        add_filter('authenticate', array($this, 'block_locked_out'), 25, 3);
        add_action('wp_login', array($this, 'clear_attempts'), 10, 2);
    }

    /**
     * Rejects the attempt while the client IP is locked out.
     *
     * @param null|WP_User|WP_Error $user     Result so far.
     * @param string                $username Submitted username.
     * @param string                $password Submitted password.
     *
     * @return null|WP_User|WP_Error
     */
    public function block_locked_out($user, $username, $password)
    {
        if ('' === (string) $username) {
            return $user;
        }

        if (Gatalyn_IP::is_allowed()) {
            return $user;
        }

        $remaining = $this->lockout_remaining();

        if ($remaining <= 0) {
            return $user;
        }

        $minutes = (int) ceil($remaining / MINUTE_IN_SECONDS);

        return new WP_Error(
            self::ERROR_CODE,
            sprintf(
                /* translators: %d: number of minutes remaining in the lockout. */
                _n(
                    '<strong>Error:</strong> Too many failed login attempts. Try again in %d minute.',
                    '<strong>Error:</strong> Too many failed login attempts. Try again in %d minutes.',
                    $minutes,
                    'gatalyn-login-approval'
                ),
                $minutes
            )
        );
    }

    /**
     * Counts a failed attempt and starts a lockout once the limit is reached.
     *
     * @param string        $username Submitted username.
     * @param WP_Error|null $error    Failure reported by core.
     *
     * @return void
     */
    public function record_failure($username, $error = null)
    {
        // Returning a WP_Error from the 'authenticate' filter makes core fire
        // 'wp_login_failed' with that same error, so every block we issue comes
        // straight back here. The locked_until check below already covers the
        // normal path; this guard is what still holds when the stored state has
        // gone missing between the two (the dropped-transient case this plugin
        // already works around elsewhere), where a self-inflicted failure would
        // otherwise be counted as a fresh attempt.
        if ($error instanceof WP_Error && self::ERROR_CODE === $error->get_error_code()) {
            return;
        }

        if (Gatalyn_IP::is_allowed()) {
            return;
        }

        $max_attempts = $this->max_attempts();
        $lockout      = $this->lockout_seconds();
        $state        = $this->get_state();

        // Already locked out: nothing left to count.
        if (time() < (int) $state['locked_until']) {
            return;
        }

        $state['count'] = (int) $state['count'] + 1;

        if ($state['count'] >= $max_attempts) {
            $state['count']        = 0;
            $state['locked_until'] = time() + $lockout;
        }

        Gatalyn_Store::set($this->state_key(), $state, $lockout);
    }

    /**
     * Clears the counter once the user gets in.
     *
     * @param string  $user_login Username.
     * @param WP_User $user       Authenticated user.
     *
     * @return void
     */
    public function clear_attempts($user_login = '', $user = null)
    {
        Gatalyn_Store::delete($this->state_key());
    }

    /**
     * Seconds left in the current lockout, or 0 when not locked out.
     *
     * @return int
     */
    private function lockout_remaining()
    {
        $state = $this->get_state();
        $left  = (int) $state['locked_until'] - time();

        return $left > 0 ? $left : 0;
    }

    /**
     * Returns the stored per-IP state, normalised.
     *
     * @return array
     */
    private function get_state()
    {
        $state = Gatalyn_Store::get($this->state_key());

        if (! is_array($state)) {
            $state = array();
        }

        return array(
            'count'        => isset($state['count']) ? (int) $state['count'] : 0,
            'locked_until' => isset($state['locked_until']) ? (int) $state['locked_until'] : 0,
        );
    }

    /**
     * Storage key for the current client IP.
     *
     * @return string
     */
    private function state_key()
    {
        return self::KEY_PREFIX . md5(Gatalyn_IP::get_client_ip());
    }

    /**
     * Failed attempts allowed before a lockout starts.
     *
     * @return int
     */
    private function max_attempts()
    {
        return max(1, (int) get_option('gatalyn_max_attempts', 5));
    }

    /**
     * Lockout duration in seconds.
     *
     * @return int
     */
    private function lockout_seconds()
    {
        return max(1, (int) get_option('gatalyn_lockout_minutes', 15)) * MINUTE_IN_SECONDS;
    }
}
