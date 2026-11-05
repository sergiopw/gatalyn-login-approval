<?php

/**
 * Imports settings and lockout data from Limit Login Attempts.
 *
 * Both the original Limit Login Attempts (johanee / Automattic) and its fork
 * Limit Login Attempts Reloaded keep their settings as one option per key,
 * named 'limit_login_' . $key, with durations in seconds:
 *
 *   allowed_retries     Failures before a lockout.
 *   lockout_duration    Lockout length.
 *   valid_duration      How long failures are remembered.
 *   allowed_lockouts    Lockouts before a long lockout.
 *   long_duration       Long lockout length.
 *   client_type         'REMOTE_ADDR' or 'HTTP_X_FORWARDED_FOR'.
 *   lockout_notify      Comma-separated subset of 'log', 'email'.
 *   notify_email_after  Lockouts before the email goes out.
 *
 * and their data as:
 *
 *   limit_login_lockouts        array( ip => unix time the lockout ends )
 *   limit_login_logged          array( ip => array( username => count ) ); Reloaded
 *                               stores array( 'counter' => n, ... ) instead of n.
 *   limit_login_lockouts_total  int
 *   limit_login_whitelist       array of IPs (Reloaded only; the original only
 *                               offers a filter for this).
 *
 * The import only reads those options. It never changes or deletes them, so
 * the other plugin keeps working until the admin deactivates it, and running
 * the import again is harmless: settings are overwritten with the same values,
 * and the log and total are merged only the first time.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Gatalyn_LLA_Import
{

    /**
     * Unix time of the first import, so log and totals are not merged twice.
     */
    const DONE_OPTION = 'gatalyn_lla_imported';

    /**
     * Returns true when Limit Login Attempts data is present in the database.
     *
     * @return bool
     */
    public static function detected()
    {
        foreach (array('allowed_retries', 'lockout_duration', 'lockouts', 'logged') as $key) {
            if (false !== get_option('limit_login_' . $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unix time of the first import, or 0 when it has not run.
     *
     * @return int
     */
    public static function imported_at()
    {
        return (int) get_option(self::DONE_OPTION, 0);
    }

    /**
     * Runs the import.
     *
     * @return array Summary: settings, lockouts, log_entries, allowed_ips,
     *               skipped_ips (counts) and log_merged (bool).
     */
    public static function run()
    {
        $summary = array(
            'settings'    => self::import_settings(),
            'lockouts'    => 0,
            'allowed_ips' => 0,
            'skipped_ips' => 0,
            'log_entries' => 0,
            'log_merged'  => false,
        );

        // The log goes in before the lockouts, which add a log row for any
        // locked address the log doesn't mention so it can be unlocked there.
        if (0 === self::imported_at()) {
            $summary['log_entries'] = self::import_log();
            $summary['log_merged']  = true;
            update_option(self::DONE_OPTION, time(), false);
        }

        $summary['lockouts'] = self::import_lockouts();

        list($summary['allowed_ips'], $summary['skipped_ips']) = self::import_whitelist();

        return $summary;
    }

    /**
     * Maps the LLA settings onto ours. Returns how many were imported.
     *
     * @return int
     */
    private static function import_settings()
    {
        $imported = 0;

        $map = array(
            // LLA key            => array( our option, divisor, min, max )
            'allowed_retries'    => array('gatalyn_max_attempts', 1, 1, 50),
            'lockout_duration'   => array('gatalyn_lockout_minutes', MINUTE_IN_SECONDS, 1, 1440),
            'valid_duration'     => array('gatalyn_retry_window_hours', HOUR_IN_SECONDS, 1, 168),
            'allowed_lockouts'   => array('gatalyn_long_lockout_after', 1, 0, 100),
            'long_duration'      => array('gatalyn_long_lockout_hours', HOUR_IN_SECONDS, 1, 720),
            'notify_email_after' => array('gatalyn_lockout_notify_after', 1, 1, 100),
        );

        foreach ($map as $key => $target) {
            $value = get_option('limit_login_' . $key);

            if (false === $value || ! is_numeric($value)) {
                continue;
            }

            list($option, $divisor, $min, $max) = $target;

            update_option($option, max($min, min($max, (int) ceil((int) $value / $divisor))));
            $imported++;
        }

        $client_type = get_option('limit_login_client_type');

        if (false !== $client_type && ! defined('GATALYN_TRUST_PROXY')) {
            update_option('gatalyn_trust_proxy', 'HTTP_X_FORWARDED_FOR' === $client_type ? '1' : '');
            $imported++;
        }

        $notify = get_option('limit_login_lockout_notify');

        if (false !== $notify) {
            $methods = array_map('trim', explode(',', (string) $notify));
            update_option('gatalyn_lockout_notify_email', in_array('email', $methods, true) ? '1' : '');
            $imported++;
        }

        if ($imported > 0) {
            update_option('gatalyn_limit_attempts_enabled', '1');
        }

        return $imported;
    }

    /**
     * Carries over lockouts that have not expired yet. Returns how many are
     * in force afterwards.
     *
     * @return int
     */
    private static function import_lockouts()
    {
        $lockouts = get_option('limit_login_lockouts');

        if (! is_array($lockouts)) {
            return 0;
        }

        $count = 0;
        $log   = Gatalyn_Limit_Login::get_log();

        foreach ($lockouts as $ip => $until) {
            if (! filter_var($ip, FILTER_VALIDATE_IP) || (int) $until <= time()) {
                continue;
            }

            // A no-op when we already hold a lockout at least this long, as on
            // a second import; the address still counts as carried over.
            Gatalyn_Limit_Login::lock_until($ip, (int) $until);

            if (! isset($log[$ip])) {
                $log[$ip] = array('lockouts' => 1, 'last' => time(), 'users' => array());
            }

            $count++;
        }

        if ($count > 0) {
            Gatalyn_Limit_Login::save_log($log);
        }

        return $count;
    }

    /**
     * Adds Reloaded's IP whitelist to our allowed IPs.
     *
     * @return array( added, skipped ) Entries we can't represent, such as
     *               ranges written as "a.b.c.d-e.f.g.h", are skipped.
     */
    private static function import_whitelist()
    {
        $whitelist = get_option('limit_login_whitelist');

        if (! is_array($whitelist) || empty($whitelist)) {
            return array(0, 0);
        }

        $current = array_filter(array_map('trim', explode("\n", (string) get_option('gatalyn_allowed_ips', ''))));
        $added   = 0;
        $skipped = 0;

        foreach ($whitelist as $entry) {
            $entry = trim((string) $entry);

            if (! Gatalyn_IP::is_valid_entry($entry)) {
                $skipped++;
                continue;
            }

            if (! in_array($entry, $current, true)) {
                $current[] = $entry;
                $added++;
            }
        }

        if ($added > 0) {
            update_option('gatalyn_allowed_ips', implode("\n", $current));
        }

        return array($added, $skipped);
    }

    /**
     * Merges the LLA lockout log and total into ours. Returns entries merged.
     *
     * @return int
     */
    private static function import_log()
    {
        $total = get_option('limit_login_lockouts_total');

        if (is_numeric($total)) {
            Gatalyn_Limit_Login::add_to_total((int) $total);
        }

        $logged = get_option('limit_login_logged');

        if (! is_array($logged)) {
            return 0;
        }

        $log    = Gatalyn_Limit_Login::get_log();
        $merged = 0;

        foreach ($logged as $ip => $users) {
            if (! filter_var($ip, FILTER_VALIDATE_IP) || ! is_array($users)) {
                continue;
            }

            $entry = isset($log[$ip]) ? $log[$ip] : array('lockouts' => 0, 'last' => 0, 'users' => array());

            foreach ($users as $username => $data) {
                // Original: a plain count. Reloaded: array( 'counter', 'date', ... ).
                $count = is_array($data) ? (int) (isset($data['counter']) ? $data['counter'] : 0) : (int) $data;
                $date  = is_array($data) && isset($data['date']) ? (int) $data['date'] : 0;

                $username = substr(sanitize_user((string) $username), 0, 60);

                if ($count <= 0) {
                    continue;
                }

                if ('' !== $username) {
                    $entry['users'][$username] = (isset($entry['users'][$username]) ? (int) $entry['users'][$username] : 0) + $count;
                }

                $entry['lockouts'] += $count;
                $entry['last']      = max((int) $entry['last'], $date);
            }

            $log[$ip] = $entry;
            $merged++;
        }

        Gatalyn_Limit_Login::save_log($log);

        return $merged;
    }
}
