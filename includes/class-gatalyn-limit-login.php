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
 *
 * The counting model follows the original Limit Login Attempts plugin so that
 * its settings import with the same meaning: failures are forgotten once the
 * retry window passes without a new one, and an address that keeps getting
 * locked out within that window earns one long lockout instead of another
 * short one.
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
     * Lockout history, keyed by IP. Non-autoloaded; capped at LOG_MAX entries.
     */
    const LOG_OPTION = 'gatalyn_lockout_log';

    /**
     * Running count of lockouts ever issued.
     */
    const TOTAL_OPTION = 'gatalyn_lockouts_total';

    /**
     * Maximum number of addresses kept in the lockout log.
     */
    const LOG_MAX = 200;

    /**
     * Attempts left after a failure in this request, for the login page notice.
     *
     * @var int|null
     */
    private $remaining = null;

    /**
     * Lockout started by a failure in this request, in seconds.
     *
     * @var int
     */
    private $locked_now = 0;

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
        add_filter('login_errors', array($this, 'append_login_notice'));
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

        $remaining = self::lockout_remaining(Gatalyn_IP::get_client_ip());

        if ($remaining <= 0) {
            return $user;
        }

        return new WP_Error(self::ERROR_CODE, self::lockout_message($remaining));
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

        $ip    = Gatalyn_IP::get_client_ip();
        $now   = time();
        $state = self::get_state($ip);

        // Already locked out: nothing left to count.
        if ($now < $state['locked_until']) {
            return;
        }

        // Quiet for a whole retry window: start over, like a fresh visitor.
        if ($state['valid_until'] > 0 && $now > $state['valid_until']) {
            $state['count']    = 0;
            $state['lockouts'] = 0;
        }

        $max_attempts         = self::max_attempts();
        $state['count']       = $state['count'] + 1;
        $state['valid_until'] = $now + self::retry_window_seconds();

        if ($state['count'] >= $max_attempts) {
            $state['count']    = 0;
            $state['lockouts'] = $state['lockouts'] + 1;

            $long_after = self::long_lockout_after();
            $is_long    = $long_after > 0 && $state['lockouts'] >= $long_after;
            $duration   = $is_long ? self::long_lockout_seconds() : self::lockout_seconds();
            $number     = $state['lockouts'];

            $state['locked_until'] = $now + $duration;

            if ($is_long) {
                $state['lockouts'] = 0;
            }

            $this->locked_now = $duration;

            self::log_lockout($ip, (string) $username);
            $this->notify_lockout($ip, (string) $username, $number, $duration, $is_long);
        } else {
            $this->remaining = $max_attempts - $state['count'];
        }

        self::save_state($ip, $state);
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
        Gatalyn_Store::delete(self::state_key(Gatalyn_IP::get_client_ip()));
    }

    /**
     * Tells the visitor on wp-login.php how many attempts are left, or that the
     * failure they just made started a lockout.
     *
     * @param string $errors Error markup shown above the login form.
     *
     * @return string
     */
    public function append_login_notice($errors)
    {
        if ($this->locked_now > 0) {
            return $errors . '<br />' . self::lockout_message($this->locked_now);
        }

        if (null === $this->remaining || '1' !== (string) get_option('gatalyn_show_remaining', '1')) {
            return $errors;
        }

        return $errors . '<br />' . esc_html(
            sprintf(
                /* translators: %d: number of login attempts left before a lockout. */
                _n('%d attempt remaining.', '%d attempts remaining.', $this->remaining, 'gatalyn-login-approval'),
                $this->remaining
            )
        );
    }

    // -------------------------------------------------------------------------
    // Helpers shared with the admin screen and the importer
    // -------------------------------------------------------------------------

    /**
     * Seconds left in the lockout of $ip, or 0 when it is not locked out.
     *
     * @param string $ip Client IP.
     *
     * @return int
     */
    public static function lockout_remaining($ip)
    {
        $state = self::get_state($ip);
        $left  = $state['locked_until'] - time();

        return $left > 0 ? $left : 0;
    }

    /**
     * Locks $ip out until $until, unless it is already locked out for longer.
     * Used by the importer to carry over lockouts that are still running.
     *
     * @param string $ip    Client IP.
     * @param int    $until Unix timestamp.
     *
     * @return bool Whether the stored lockout changed.
     */
    public static function lock_until($ip, $until)
    {
        $until = (int) $until;
        $state = self::get_state($ip);

        if ($until <= time() || $until <= $state['locked_until']) {
            return false;
        }

        $state['locked_until'] = $until;
        self::save_state($ip, $state);

        return true;
    }

    /**
     * Lifts the lockout of $ip and forgets its failed attempts.
     *
     * @param string $ip Client IP.
     *
     * @return void
     */
    public static function unlock($ip)
    {
        Gatalyn_Store::delete(self::state_key($ip));
    }

    /**
     * Returns the lockout log, most recent last.
     *
     * Each entry: array( 'lockouts' => int, 'last' => int, 'users' => array( username => count ) ).
     *
     * @return array
     */
    public static function get_log()
    {
        $log = get_option(self::LOG_OPTION, array());

        return is_array($log) ? $log : array();
    }

    /**
     * Replaces the lockout log, keeping only the newest LOG_MAX addresses.
     *
     * @param array $log Log in the get_log() format.
     *
     * @return void
     */
    public static function save_log(array $log)
    {
        if (count($log) > self::LOG_MAX) {
            uasort(
                $log,
                function ($a, $b) {
                    return (int) $a['last'] - (int) $b['last'];
                }
            );
            $log = array_slice($log, -self::LOG_MAX, null, true);
        }

        update_option(self::LOG_OPTION, $log, false);
    }

    /**
     * Empties the lockout log. The running total is kept.
     *
     * @return void
     */
    public static function clear_log()
    {
        delete_option(self::LOG_OPTION);
    }

    /**
     * Number of lockouts issued since the plugin (or the importer) started counting.
     *
     * @return int
     */
    public static function total_lockouts()
    {
        return (int) get_option(self::TOTAL_OPTION, 0);
    }

    /**
     * Adds $count to the running total of lockouts.
     *
     * @param int $count Lockouts to add.
     *
     * @return void
     */
    public static function add_to_total($count)
    {
        update_option(self::TOTAL_OPTION, self::total_lockouts() + max(0, (int) $count), false);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Records a lockout of $ip for $username in the log.
     *
     * @param string $ip       Client IP.
     * @param string $username Username tried when the lockout started.
     *
     * @return void
     */
    private static function log_lockout($ip, $username)
    {
        $log   = self::get_log();
        $entry = isset($log[$ip]) && is_array($log[$ip]) ? $log[$ip] : array();
        $users = isset($entry['users']) && is_array($entry['users']) ? $entry['users'] : array();

        // Attackers control this string; keep it short and printable.
        $username = substr(sanitize_user($username), 0, 60);

        if ('' !== $username) {
            $users[$username] = (isset($users[$username]) ? (int) $users[$username] : 0) + 1;
        }

        // Unset first so the address moves to the end, keeping the newest last.
        unset($log[$ip]);
        $log[$ip] = array(
            'lockouts' => (isset($entry['lockouts']) ? (int) $entry['lockouts'] : 0) + 1,
            'last'     => time(),
            'users'    => $users,
        );

        self::save_log($log);
        self::add_to_total(1);
    }

    /**
     * Notifies the site owner by email and/or Telegram about a lockout.
     *
     * Only every Nth lockout of the same address within the retry window is
     * reported, plus every long lockout, so a single scanner doesn't flood the
     * inbox.
     *
     * @param string $ip       Client IP.
     * @param string $username Username tried.
     * @param int    $number   Lockouts of this address within the retry window.
     * @param int    $duration Lockout length in seconds.
     * @param bool   $is_long  Whether this is a long lockout.
     *
     * @return void
     */
    private function notify_lockout($ip, $username, $number, $duration, $is_long)
    {
        $by_email    = '1' === (string) get_option('gatalyn_lockout_notify_email', '');
        $by_telegram = '1' === (string) get_option('gatalyn_lockout_notify_telegram', '');

        if (! $by_email && ! $by_telegram) {
            return;
        }

        $after = max(1, (int) get_option('gatalyn_lockout_notify_after', 4));

        if (! $is_long && 0 !== $number % $after) {
            return;
        }

        $site     = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $minutes  = (int) ceil($duration / MINUTE_IN_SECONDS);
        $username = substr(sanitize_user($username), 0, 60);

        $summary = sprintf(
            /* translators: 1: number of lockouts, 2: IP address, 3: lockout length in minutes. */
            _n(
                '%1$d lockout from IP %2$s. It is now blocked for %3$d minutes.',
                '%1$d lockouts from IP %2$s. It is now blocked for %3$d minutes.',
                $number,
                'gatalyn-login-approval'
            ),
            $number,
            $ip,
            $minutes
        );

        $last_user = sprintf(
            /* translators: %s: username. */
            __('Last username tried: %s', 'gatalyn-login-approval'),
            '' !== $username ? $username : '-'
        );

        if ($by_email) {
            wp_mail(
                get_option('admin_email'),
                sprintf(
                    /* translators: %s: site name. */
                    __('[%s] Too many failed login attempts', 'gatalyn-login-approval'),
                    $site
                ),
                $summary . "\n" . $last_user . "\n\n" . admin_url('options-general.php?page=gatalyn-settings&gatalyn_tab=lockouts')
            );
        }

        $bot_token = (string) get_option('gatalyn_telegram_bot_token', '');
        $chat_id   = (string) get_option('gatalyn_telegram_chat_id', '');

        if ($by_telegram && '' !== $bot_token && '' !== $chat_id) {
            Gatalyn_Telegram::send_message(
                $bot_token,
                $chat_id,
                "\xF0\x9F\x9A\xAB <b>" . esc_html($site) . "</b>\n" . esc_html($summary) . "\n" . esc_html($last_user)
            );
        }
    }

    /**
     * Message shown to a locked-out visitor.
     *
     * @param int $seconds Seconds left in the lockout.
     *
     * @return string
     */
    private static function lockout_message($seconds)
    {
        $minutes = (int) ceil($seconds / MINUTE_IN_SECONDS);

        return sprintf(
            /* translators: %d: number of minutes remaining in the lockout. */
            _n(
                '<strong>Error:</strong> Too many failed login attempts. Try again in %d minute.',
                '<strong>Error:</strong> Too many failed login attempts. Try again in %d minutes.',
                $minutes,
                'gatalyn-login-approval'
            ),
            $minutes
        );
    }

    /**
     * Returns the stored per-IP state, normalised.
     *
     * @param string $ip Client IP.
     *
     * @return array
     */
    private static function get_state($ip)
    {
        $state = Gatalyn_Store::get(self::state_key($ip));

        if (! is_array($state)) {
            $state = array();
        }

        return array(
            'count'        => isset($state['count']) ? (int) $state['count'] : 0,
            'lockouts'     => isset($state['lockouts']) ? (int) $state['lockouts'] : 0,
            'locked_until' => isset($state['locked_until']) ? (int) $state['locked_until'] : 0,
            'valid_until'  => isset($state['valid_until']) ? (int) $state['valid_until'] : 0,
        );
    }

    /**
     * Stores the per-IP state for as long as any part of it still matters.
     *
     * @param string $ip    Client IP.
     * @param array  $state State in the get_state() format.
     *
     * @return void
     */
    private static function save_state($ip, array $state)
    {
        $ttl = max($state['locked_until'], $state['valid_until']) - time();

        Gatalyn_Store::set(self::state_key($ip), $state, max(1, $ttl));
    }

    /**
     * Storage key for $ip.
     *
     * @param string $ip Client IP.
     *
     * @return string
     */
    private static function state_key($ip)
    {
        return self::KEY_PREFIX . md5((string) $ip);
    }

    /**
     * Failed attempts allowed before a lockout starts.
     *
     * @return int
     */
    private static function max_attempts()
    {
        return max(1, (int) get_option('gatalyn_max_attempts', 5));
    }

    /**
     * Lockout duration in seconds.
     *
     * @return int
     */
    private static function lockout_seconds()
    {
        return max(1, (int) get_option('gatalyn_lockout_minutes', 15)) * MINUTE_IN_SECONDS;
    }

    /**
     * How long failed attempts are remembered, in seconds.
     *
     * @return int
     */
    private static function retry_window_seconds()
    {
        return max(1, (int) get_option('gatalyn_retry_window_hours', 12)) * HOUR_IN_SECONDS;
    }

    /**
     * Lockouts within the retry window that trigger a long lockout; 0 disables it.
     *
     * @return int
     */
    private static function long_lockout_after()
    {
        return max(0, (int) get_option('gatalyn_long_lockout_after', 4));
    }

    /**
     * Long lockout duration in seconds.
     *
     * @return int
     */
    private static function long_lockout_seconds()
    {
        return max(1, (int) get_option('gatalyn_long_lockout_hours', 24)) * HOUR_IN_SECONDS;
    }
}
