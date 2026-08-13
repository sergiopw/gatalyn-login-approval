<?php

/**
 * Short-lived key/value storage with an option-backed fallback.
 *
 * Gatalyn_Token already learned that transients are not reliably persisted on
 * every host (see the note in uninstall.php), so it writes each value twice:
 * once as a transient and once as a non-autoloaded option. This class is that
 * same pattern, factored out, because a dropped value matters more here than it
 * does for a token: if a lockout counter silently disappears, the attacker gets
 * their attempts back.
 *
 * Unlike the inline writes in Gatalyn_Token, the option copy carries its own
 * 'expires' timestamp, so reads can honour it even when the transient layer is
 * gone, and the daily garbage collector can drop stale rows.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Gatalyn_Store
{

    /**
     * Stores a value under $key for $expiry_seconds.
     *
     * @param string $key            Option/transient name.
     * @param mixed  $value          Value to store.
     * @param int    $expiry_seconds Lifetime in seconds.
     *
     * @return void
     */
    public static function set($key, $value, $expiry_seconds)
    {
        $expiry_seconds = max(1, (int) $expiry_seconds);

        set_transient($key, $value, $expiry_seconds);

        update_option(
            $key,
            array(
                'data'    => $value,
                'expires' => time() + $expiry_seconds,
            ),
            false
        );
    }

    /**
     * Reads a value, falling back to the option copy when the transient is gone.
     *
     * @param string $key Option/transient name.
     *
     * @return mixed False when missing or expired.
     */
    public static function get($key)
    {
        $value = get_transient($key);

        if (false !== $value) {
            return $value;
        }

        $fallback = get_option($key);

        if (! is_array($fallback) || ! isset($fallback['expires']) || ! array_key_exists('data', $fallback)) {
            return false;
        }

        if (time() > (int) $fallback['expires']) {
            delete_option($key);

            return false;
        }

        return $fallback['data'];
    }

    /**
     * Removes both copies of a value.
     *
     * @param string $key Option/transient name.
     *
     * @return void
     */
    public static function delete($key)
    {
        delete_transient($key);
        delete_option($key);
    }
}
