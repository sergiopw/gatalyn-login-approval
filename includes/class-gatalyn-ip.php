<?php

/**
 * Shared client IP helpers.
 *
 * Extracted from Gatalyn_Login so the approval flow, the login attempt limiter
 * and anything added later all resolve the client IP identically. The
 * GATALYN_TRUST_PROXY branch is the reason this must not be copy-pasted: a
 * second copy that omitted it would read the reverse proxy's own address on
 * proxied installs, so every visitor would look like the same client and a
 * single attacker could lock out the whole site.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Gatalyn_IP
{

    /**
     * Returns the client's IP address.
     *
     * @return string
     */
    public static function get_client_ip()
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
     * Returns true if the current request's IP is in the whitelist.
     *
     * @param array|null $allowed_ips Pre-parsed list. Read from options when null.
     *
     * @return bool
     */
    public static function is_allowed($allowed_ips = null)
    {
        if (null === $allowed_ips) {
            $raw         = (string) get_option('gatalyn_allowed_ips', '');
            $allowed_ips = array_filter(array_map('trim', explode("\n", $raw)));
        }

        if (empty($allowed_ips)) {
            return false;
        }

        $client_ip = self::get_client_ip();

        foreach ($allowed_ips as $allowed) {
            if (strpos($allowed, '/') !== false) {
                if (self::ip_in_cidr($client_ip, $allowed)) {
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
     * @param string $ip   IPv4 address.
     * @param string $cidr CIDR notation.
     *
     * @return bool
     */
    private static function ip_in_cidr($ip, $cidr)
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
}
