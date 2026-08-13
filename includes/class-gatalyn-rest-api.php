<?php

/**
 * Restricts REST API access for unauthenticated visitors.
 *
 * Three levels, because "disable the REST API" means two very different things
 * in practice:
 *
 *   off   - no restriction.
 *   users - only /wp/v2/users is hidden from anonymous callers. This closes
 *           username enumeration, which is what makes brute forcing cheap, and
 *           breaks nothing else, so it is safe to leave on permanently.
 *   all   - every route requires authentication, except oEmbed. This is the
 *           blunt option: anything on the front end that talks to the REST API
 *           without a session (contact forms, AJAX search, store endpoints,
 *           page builders) will stop working.
 *
 * The oEmbed exemption is not optional in 'all' mode. /oembed/1.0/ is what other
 * sites call to render an embed of this one, so blocking it silently breaks
 * every existing embed of the site elsewhere on the web.
 */

if (! defined('ABSPATH')) {
    exit;
}

class Gatalyn_REST_API
{

    /**
     * Routes hidden from anonymous callers in 'users' mode.
     */
    const USER_ROUTES = array(
        '/wp/v2/users',
        '/wp/v2/users/(?P<id>[\d]+)',
    );

    /**
     * Registers WordPress hooks.
     *
     * @return void
     */
    public function init()
    {
        $mode = $this->mode();

        if ('off' === $mode) {
            return;
        }

        if ('users' === $mode) {
            // Unregistering the routes yields a clean 404 and, unlike a blanket
            // authentication error, leaves every other endpoint working.
            add_filter('rest_endpoints', array($this, 'remove_user_routes'));

            return;
        }

        add_filter('rest_authentication_errors', array($this, 'require_authentication'), 99);
    }

    /**
     * Drops the user routes for anonymous callers.
     *
     * @param array $endpoints Registered endpoints.
     *
     * @return array
     */
    public function remove_user_routes($endpoints)
    {
        if (is_user_logged_in()) {
            return $endpoints;
        }

        foreach (self::USER_ROUTES as $route) {
            if (isset($endpoints[$route])) {
                unset($endpoints[$route]);
            }
        }

        return $endpoints;
    }

    /**
     * Requires a logged-in user for every non-exempt route.
     *
     * @param WP_Error|null|true $result Result of previous authentication checks.
     *
     * @return WP_Error|null|true
     */
    public function require_authentication($result)
    {
        // Another handler already authenticated the request or rejected it.
        if (! empty($result)) {
            return $result;
        }

        if (is_user_logged_in()) {
            return $result;
        }

        if ($this->is_exempt_route()) {
            return $result;
        }

        return new WP_Error(
            'gatalyn_rest_forbidden',
            __('REST API access is restricted to authenticated users on this site.', 'gatalyn-login-approval'),
            array('status' => rest_authorization_required_code())
        );
    }

    /**
     * Whether the current route stays public in 'all' mode.
     *
     * @return bool
     */
    private function is_exempt_route()
    {
        $route = '';

        if (isset($GLOBALS['wp']) && isset($GLOBALS['wp']->query_vars['rest_route'])) {
            $route = (string) $GLOBALS['wp']->query_vars['rest_route'];
        }

        return 0 === strpos($route, '/oembed/');
    }

    /**
     * Current restriction level.
     *
     * @return string One of 'off', 'users', 'all'.
     */
    private function mode()
    {
        $mode = (string) get_option('gatalyn_rest_api_mode', 'off');

        return in_array($mode, array('off', 'users', 'all'), true) ? $mode : 'off';
    }
}
