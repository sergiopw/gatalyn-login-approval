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
     * Always a canonical address (IPv4-mapped IPv6 collapsed to IPv4), or ''
     * when the connection has no usable address, as under WP-CLI.
     *
     * @return string
     */
    public static function get_client_ip()
    {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? self::normalize(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])))
            : '';

        if (self::trust_proxy()) {
            $forwarded = self::forwarded_ip();

            if ('' !== $forwarded) {
                $ip = $forwarded;
            }
        }

        return $ip;
    }

    /**
     * Whether X-Forwarded-For should be trusted for the client IP.
     *
     * The GATALYN_TRUST_PROXY constant wins when defined, so a host that pins it
     * in wp-config.php can't be overridden from the settings screen. Otherwise
     * the 'gatalyn_trust_proxy' option decides, which is also what the Limit
     * Login Attempts importer sets from that plugin's "behind a proxy" choice.
     *
     * @return bool
     */
    public static function trust_proxy()
    {
        if (defined('GATALYN_TRUST_PROXY')) {
            return (bool) GATALYN_TRUST_PROXY;
        }

        return '1' === (string) get_option('gatalyn_trust_proxy', '');
    }

    /**
     * Resolves the client address from the forwarding headers, or '' when the
     * headers can't be trusted and REMOTE_ADDR must be used as it is.
     *
     * X-Forwarded-For is a list that every proxy on the way appends to, so only
     * its right-hand end was written by a proxy we control: anything to the left
     * is whatever the client chose to send. Reading the first entry, as this
     * class once did, lets any visitor pick the address the plugin sees.
     *
     * Without a list of trusted proxies the connection is assumed to come from
     * one proxy (REMOTE_ADDR) and the last entry it appended is the client. With
     * a list (the 'gatalyn_trusted_proxies' option or the GATALYN_TRUSTED_PROXIES
     * constant) the proxies in it are skipped from the right, which is what a
     * CDN in front of a local proxy needs, and a request that did not arrive
     * through one of them gets no forwarded address at all.
     *
     * @return string
     */
    public static function forwarded_ip()
    {
        $remote = isset($_SERVER['REMOTE_ADDR'])
            ? self::normalize(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])))
            : '';

        if ('' === $remote) {
            return '';
        }

        $trusted = self::trusted_proxies();

        if (! empty($trusted) && ! self::matches($remote, $trusted)) {
            return '';
        }

        // Cloudflare states the visitor address in a header of its own, which
        // only counts when the request really came through a trusted proxy.
        if (! empty($trusted) && isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $cf = self::normalize(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'])));

            if ('' !== $cf) {
                return $cf;
            }
        }

        $header = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']))
            : '';

        if ('' === $header) {
            return '';
        }

        $chain = array_reverse(array_map('trim', explode(',', $header)));

        foreach ($chain as $entry) {
            $entry = self::normalize($entry);

            // An unparseable entry means the header was tampered with: stop.
            if ('' === $entry) {
                return '';
            }

            if (empty($trusted) || ! self::matches($entry, $trusted)) {
                return $entry;
            }
        }

        return '';
    }

    /**
     * Proxy addresses and ranges whose forwarding headers are believed.
     *
     * @return array
     */
    public static function trusted_proxies()
    {
        $raw = defined('GATALYN_TRUSTED_PROXIES')
            ? (string) GATALYN_TRUSTED_PROXIES
            : (string) get_option('gatalyn_trusted_proxies', '');

        return self::parse_list(str_replace(',', "\n", $raw));
    }

    /**
     * Canonical form of an IP address: lower-case, shortest IPv6 spelling, and
     * IPv4-mapped IPv6 (::ffff:1.2.3.4) as plain IPv4. '' when it is not an IP.
     *
     * @param string $ip Address as received.
     *
     * @return string
     */
    public static function normalize($ip)
    {
        $bin = @inet_pton(trim((string) $ip));

        if (false === $bin) {
            return '';
        }

        if (16 === strlen($bin) && 0 === strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12)) {
            $bin = substr($bin, 12);
        }

        return (string) inet_ntop($bin);
    }

    /**
     * The unit that failed logins are counted against: the address itself for
     * IPv4, and the /64 network for IPv6, because a single customer is handed a
     * whole /64 and could otherwise rotate through 2^64 addresses.
     *
     * @param string $ip Client IP.
     *
     * @return string
     */
    public static function bucket($ip)
    {
        $ip  = self::normalize($ip);
        $bin = '' === $ip ? false : @inet_pton($ip);

        if (false === $bin || 16 !== strlen($bin)) {
            return $ip;
        }

        return (string) inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8));
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
            $allowed_ips = self::parse_list((string) get_option('gatalyn_allowed_ips', ''));
        }

        return self::matches(self::get_client_ip(), $allowed_ips);
    }

    /**
     * Returns true if the current request's IP is in the permanent blocklist.
     *
     * @return bool
     */
    public static function is_blocked()
    {
        return self::matches(
            self::get_client_ip(),
            self::parse_list((string) get_option('gatalyn_blocked_ips', ''))
        );
    }

    /**
     * Splits a one-entry-per-line option value into a clean list.
     *
     * @param string $raw Option value.
     *
     * @return array
     */
    public static function parse_list($raw)
    {
        return array_values(array_filter(array_map('trim', explode("\n", $raw))));
    }

    /**
     * Whether $entry is an IPv4/IPv6 address or a CIDR range of either family.
     *
     * @param string $entry List entry.
     *
     * @return bool
     */
    public static function is_valid_entry($entry)
    {
        if (false === strpos($entry, '/')) {
            return (bool) filter_var($entry, FILTER_VALIDATE_IP);
        }

        $parts = explode('/', $entry, 2);

        if (! filter_var($parts[0], FILTER_VALIDATE_IP) || ! ctype_digit($parts[1])) {
            return false;
        }

        $max = false === strpos($parts[0], ':') ? 32 : 128;

        return (int) $parts[1] <= $max;
    }

    /**
     * Whether $ip equals, or falls inside, any entry of $list.
     *
     * @param string $ip   Client IP.
     * @param array  $list Addresses and CIDR ranges.
     *
     * @return bool
     */
    public static function matches($ip, array $list)
    {
        foreach ($list as $entry) {
            if (false !== strpos($entry, '/')) {
                if (self::ip_in_cidr($ip, $entry)) {
                    return true;
                }
            } elseif (self::same_address($entry, $ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compares two addresses by value, so "::1" equals "0:0:0:0:0:0:0:1".
     *
     * @param string $a First address.
     * @param string $b Second address.
     *
     * @return bool
     */
    private static function same_address($a, $b)
    {
        $bin_a = @inet_pton($a);
        $bin_b = @inet_pton($b);

        return false !== $bin_a && $bin_a === $bin_b;
    }

    /**
     * Checks whether $ip falls within the CIDR range $cidr (IPv4 or IPv6).
     *
     * @param string $ip   Client address.
     * @param string $cidr CIDR notation.
     *
     * @return bool
     */
    private static function ip_in_cidr($ip, $cidr)
    {
        $parts = explode('/', $cidr, 2);
        if (2 !== count($parts) || ! ctype_digit($parts[1])) {
            return false;
        }

        $subnet = @inet_pton($parts[0]);
        $addr   = @inet_pton($ip);

        // Different families (or garbage) never match.
        if (false === $subnet || false === $addr || strlen($subnet) !== strlen($addr)) {
            return false;
        }

        $prefix = (int) $parts[1];

        if ($prefix > strlen($subnet) * 8) {
            return false;
        }

        $full = intdiv($prefix, 8);

        if (0 !== strncmp($subnet, $addr, $full)) {
            return false;
        }

        $rest = $prefix % 8;

        if (0 === $rest) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($subnet[$full]) & $mask) === (ord($addr[$full]) & $mask);
    }
}
