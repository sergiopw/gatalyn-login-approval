<?php

/**
 * Token management for Login Approval.
 *
 * Tokens are stored as WordPress transients so they automatically expire.
 * Every transient write here is mirrored to a plain wp_options row (not
 * autoloaded) as a fallback, since some hosts (Hostinger in particular)
 * don't reliably persist transients; the option copies carry their own
 * 'expires'/'updated_at' timestamp and are checked/cleaned up manually.
 * The 64-character hex tokens provide 256 bits of entropy.
 */

if (! defined('ABSPATH')) {
	exit;
}

class Gatalyn_Token
{

	/**
	 * Storage key for a token or request id.
	 *
	 * The identifiers themselves are bearer secrets (an approval token approves
	 * a login, a request id collects it), so only their SHA-256 digest is used
	 * in option and transient names: a copy of the options table, or a SQL
	 * injection elsewhere on the site, then does not hand out working links.
	 * Names stay under the 172-character transient limit.
	 *
	 * @param string $prefix Key family.
	 * @param string $id     Token or request id as sent to the user.
	 *
	 * @return string
	 */
	private static function key($prefix, $id)
	{
		return $prefix . hash('sha256', (string) $id);
	}

	const APPROVAL_PREFIX = 'gatalyn_approval_';
	const REQUEST_PREFIX  = 'gatalyn_request_';
	const SESSION_PREFIX  = 'gatalyn_session_';
	const REVOKED_PREFIX  = 'gatalyn_revoked_user_';
	const LOGOUT_PREFIX   = 'gatalyn_logout_';

	/** How long the "close the session" link in a login notice keeps working. */
	const LOGOUT_TTL = 3 * DAY_IN_SECONDS;

	/**
	 * Token for the "this wasn't me, close the sessions" link of a login notice.
	 * It is a separate family from approval tokens, so it can never approve a login.
	 *
	 * @param int $user_id User who logged in.
	 *
	 * @return string 64-character hex token.
	 */
	public static function generate_logout($user_id)
	{
		$token = bin2hex(random_bytes(32));
		$data  = array(
			'user_id' => (int) $user_id,
			'expires' => time() + self::LOGOUT_TTL,
		);

		set_transient(self::key(self::LOGOUT_PREFIX, $token), $data, self::LOGOUT_TTL);
		update_option(self::key(self::LOGOUT_PREFIX, $token), $data, false);

		return $token;
	}

	/**
	 * @param string $token Token from the link.
	 *
	 * @return array|false Stored data, or false when unknown or expired.
	 */
	public static function verify_logout($token)
	{
		$token = trim((string) $token);
		if (! preg_match('/^[a-f0-9]{64}$/i', $token)) {
			return false;
		}

		$data = get_transient(self::key(self::LOGOUT_PREFIX, $token));
		if (! is_array($data)) {
			$data = get_option(self::key(self::LOGOUT_PREFIX, $token));
		}

		if (! is_array($data) || time() > (int) ($data['expires'] ?? 0)) {
			return false;
		}

		return $data;
	}

	/**
	 * @param string $token Token to use up.
	 *
	 * @return void
	 */
	public static function delete_logout($token)
	{
		$token = trim((string) $token);
		delete_transient(self::key(self::LOGOUT_PREFIX, $token));
		delete_option(self::key(self::LOGOUT_PREFIX, $token));
	}

	/**
	 * Generates an approval token for the given user and stores it as a transient.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $mode        'before' or 'after'.
	 * @param string $redirect_to Optional URL to redirect to after successful approval.
	 *
	 * @return string The 64-character hex token.
	 */
	public static function generate_approval($user_id, $mode, $redirect_to = '')
	{
		$token  = bin2hex(random_bytes(32));
		$expiry = max(1, (int) get_option('gatalyn_token_expiry', 60)) * MINUTE_IN_SECONDS;

		$data = array(
			'user_id'     => (int) $user_id,
			'mode'        => $mode,
			'created'     => time(),
			'redirect_to' => sanitize_url($redirect_to),
			'expires'     => time() + $expiry,
		);

		set_transient(self::key(self::APPROVAL_PREFIX, $token), $data, $expiry);

		// Also store in option as fallback for Hostinger transient issues
		update_option(self::key(self::APPROVAL_PREFIX, $token), $data, false);

		return $token;
	}

	/**
	 * Generates approve and deny tokens for a single login request.
	 *
	 * @param int    $user_id     WordPress user ID.
	 * @param string $mode        'before' or 'after'.
	 * @param string $redirect_to Optional URL after approval.
	 * @param string $identifier  Username/email provided by the user.
	 *
	 * @return array{approve:string,deny:string,request_id:string}
	 */
	public static function generate_approval_pair($user_id, $mode, $redirect_to = '', $identifier = '')
	{
		$request_id = bin2hex(random_bytes(16));
		$expiry     = max(1, (int) get_option('gatalyn_token_expiry', 60)) * MINUTE_IN_SECONDS;

		$approve_token = bin2hex(random_bytes(32));
		$deny_token    = bin2hex(random_bytes(32));

		$common = array(
			'user_id'     => (int) $user_id,
			'mode'        => $mode,
			'created'     => time(),
			'redirect_to' => sanitize_url($redirect_to),
			'identifier'  => sanitize_text_field($identifier),
			'request_id'  => $request_id,
			'expires'     => time() + $expiry,
		);

		$approve_data             = $common;
		$approve_data['decision'] = 'approve';

		$deny_data             = $common;
		$deny_data['decision'] = 'deny';

		set_transient(self::key(self::APPROVAL_PREFIX, $approve_token), $approve_data, $expiry);
		set_transient(self::key(self::APPROVAL_PREFIX, $deny_token), $deny_data, $expiry);

		// Also store in option as fallback for Hostinger transient issues
		update_option(self::key(self::APPROVAL_PREFIX, $approve_token), $approve_data, false);
		update_option(self::key(self::APPROVAL_PREFIX, $deny_token), $deny_data, false);

		return array(
			'approve' => $approve_token,
			'deny'    => $deny_token,
			'request_id' => $request_id,
		);
	}

	/**
	 * Request ids are 32 hex characters; anything else is not one of ours.
	 *
	 * @param string $request_id Value from the request.
	 *
	 * @return string The id, or '' when it is not well formed.
	 */
	public static function clean_request_id($request_id)
	{
		$request_id = trim((string) $request_id);

		return preg_match('/^[a-f0-9]{32}$/i', $request_id) ? strtolower($request_id) : '';
	}

	/**
	 * Creates or resets the shared status record for a login approval request.
	 * This allows the original browser window to detect approval performed on
	 * another device.
	 *
	 * @param string $request_id   Request identifier.
	 * @param string $mode         'before' or 'after'.
	 * @param int    $user_id      WordPress user ID.
	 * @param string $user_login   WordPress username.
	 * @param string $redirect_to  Optional redirect URL.
	 */
	public static function initialize_request_status($request_id, $mode, $user_id, $user_login, $redirect_to = '')
	{
		$expiry = max(1, (int) get_option('gatalyn_token_expiry', 60)) * MINUTE_IN_SECONDS;

		$data = array(
			'status'      => 'pending',
			'mode'        => $mode,
			'user_id'     => (int) $user_id,
			'user_login'  => sanitize_user($user_login),
			'redirect_to' => sanitize_url($redirect_to),
			'updated_at'  => time(),
		);

		set_transient(
			self::key(self::REQUEST_PREFIX, $request_id),
			$data,
			max($expiry, 30 * MINUTE_IN_SECONDS)
		);

		// Also store in option as fallback for Hostinger transient issues
		update_option(
			self::key(self::REQUEST_PREFIX, $request_id),
			$data,
			false
		);
	}

	/**
	 * Updates the shared status record for a login approval request.
	 *
	 * @param string $request_id Request identifier.
	 * @param string $status     Status string, e.g. pending, approved, denied.
	 * @param array  $extra_data Additional data to merge into the request state.
	 */
	public static function update_request_status($request_id, $status, $extra_data = array())
	{
		$request_id = self::clean_request_id($request_id);
		if ('' === $request_id) {
			return;
		}

		$existing = self::get_request_status($request_id);
		if (! is_array($existing)) {
			$existing = array();
		}

		$existing['status']     = sanitize_key($status);
		$existing['updated_at'] = time();
		$existing               = array_merge($existing, $extra_data);

		set_transient(
			self::key(self::REQUEST_PREFIX, $request_id),
			$existing,
			30 * MINUTE_IN_SECONDS
		);

		// Also store in option as fallback for Hostinger transient issues
		update_option(
			self::key(self::REQUEST_PREFIX, $request_id),
			$existing,
			false
		);
	}

	/**
	 * Returns the shared status record for a login approval request.
	 *
	 * @param string $request_id Request identifier.
	 *
	 * @return array|false
	 */
	public static function get_request_status($request_id)
	{
		$request_id = self::clean_request_id($request_id);
		if ('' === $request_id) {
			return false;
		}

		// Try transient first (faster)
		$status = get_transient(self::key(self::REQUEST_PREFIX, $request_id));
		if (is_array($status)) {
			return $status;
		}

		// Fallback to option for Hostinger transient issues
		$status = get_option(self::key(self::REQUEST_PREFIX, $request_id));
		if (is_array($status)) {
			// Check if expired (30 minutes)
			$updated_at = (int) ($status['updated_at'] ?? 0);
			if ($updated_at > 0 && (time() - $updated_at) > (30 * MINUTE_IN_SECONDS)) {
				return false;
			}
			return $status;
		}

		return false;
	}

	/**
	 * Deletes the shared status record for a login approval request.
	 *
	 * @param string $request_id Request identifier.
	 */
	public static function delete_request_status($request_id)
	{
		$request_id = self::clean_request_id($request_id);
		if ('' === $request_id) {
			return;
		}

		delete_transient(self::key(self::REQUEST_PREFIX, $request_id));
		delete_option(self::key(self::REQUEST_PREFIX, $request_id));
	}

	/**
	 * Verifies an approval token and returns its stored data.
	 *
	 * @param string $token The token to verify.
	 *
	 * @return array|false Token data array or false if invalid or expired.
	 */
	public static function verify_approval($token)
	{
		if (empty($token)) {
			return false;
		}

		$token = trim((string) $token);
		if (! preg_match('/^[a-f0-9]{64}$/i', $token)) {
			return false;
		}

		// Try transient first (faster)
		$data = get_transient(self::key(self::APPROVAL_PREFIX, $token));
		if (is_array($data)) {
			return $data;
		}

		// Fallback to option for Hostinger transient issues
		$data = get_option(self::key(self::APPROVAL_PREFIX, $token));
		if (is_array($data)) {
			$expires = (int) ($data['expires'] ?? 0);
			if ($expires > 0 && time() > $expires) {
				delete_option(self::key(self::APPROVAL_PREFIX, $token));
				return false;
			}
			return $data;
		}

		return false;
	}

	/**
	 * Deletes an approval token, marking it as used (single-use enforcement).
	 *
	 * @param string $token The token to delete.
	 */
	public static function delete_approval($token)
	{
		$token = trim((string) $token);
		delete_transient(self::key(self::APPROVAL_PREFIX, $token));
		delete_option(self::key(self::APPROVAL_PREFIX, $token));
	}

	/**
	 * Generates a short-lived session token used in "before" mode.
	 * After the user approves via email, this token allows them to
	 * proceed to the credential-entry step of the login form.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $user_login WordPress username.
	 *
	 * @return string The 64-character hex session token.
	 */
	public static function generate_session($user_id, $user_login)
	{
		$token = bin2hex(random_bytes(32));

		$data = array(
			'user_id'    => (int) $user_id,
			'user_login' => sanitize_user($user_login),
		);

		set_transient(self::key(self::SESSION_PREFIX, $token), $data, 30 * MINUTE_IN_SECONDS);

		return $token;
	}

	/**
	 * Verifies a session token and returns its data.
	 *
	 * @param string $token The session token to verify.
	 *
	 * @return array|false Session data or false if invalid or expired.
	 */
	public static function verify_session($token)
	{
		if (empty($token)) {
			return false;
		}

		return get_transient(self::key(self::SESSION_PREFIX, trim((string) $token)));
	}

	/**
	 * Deletes a session token.
	 *
	 * @param string $token The session token to delete.
	 */
	public static function delete_session($token)
	{
		delete_transient(self::key(self::SESSION_PREFIX, trim((string) $token)));
	}

	/**
	 * Marks a user as revoked until a fresh approval request is made.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function mark_user_revoked($user_id)
	{
		$expiry = max(1, (int) get_option('gatalyn_token_expiry', 60)) * MINUTE_IN_SECONDS;
		set_transient(self::REVOKED_PREFIX . (int) $user_id, 1, $expiry);

		// Also store in option as fallback
		update_option(
			'gatalyn_revoked_' . (int) $user_id,
			array(
				'revoked_at' => time(),
				'expiry'     => $expiry,
			),
			false
		);
	}

	/**
	 * Clears a user's revoked marker.
	 * CRITICAL: For Hostinger compatibility, this aggressively clears
	 * the marker in BOTH transients and options, multiple times if needed.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function clear_user_revoked($user_id)
	{
		$user_id = (int) $user_id;

		// Delete from transient (attempt multiple times for Hostinger)
		delete_transient(self::REVOKED_PREFIX . $user_id);

		// Delete from option (primary storage)
		delete_option('gatalyn_revoked_' . $user_id);

		// Verify it's actually deleted
		if (false !== get_transient(self::REVOKED_PREFIX . $user_id)) {
			delete_transient(self::REVOKED_PREFIX . $user_id);
		}

		if (false !== get_option('gatalyn_revoked_' . $user_id)) {
			delete_option('gatalyn_revoked_' . $user_id);
		}
	}

	/**
	 * Checks whether a user is currently marked as revoked.
	 *
	 * @param int $user_id WordPress user ID.
	 *
	 * @return bool
	 */
	public static function is_user_revoked($user_id)
	{
		$user_id = (int) $user_id;

		// Check transient first
		if (false !== get_transient(self::REVOKED_PREFIX . $user_id)) {
			return true;
		}

		// Check option as fallback for Hostinger
		$revoked = get_option('gatalyn_revoked_' . $user_id);
		if (is_array($revoked)) {
			$revoked_at = (int) ($revoked['revoked_at'] ?? 0);
			$expiry     = (int) ($revoked['expiry'] ?? 0);

			// If revoked_at is invalid or missing, clean it up
			if ($revoked_at <= 0) {
				delete_option('gatalyn_revoked_' . $user_id);
				return false;
			}

			// If expiry is invalid or missing, assume it expired
			if ($expiry <= 0) {
				delete_option('gatalyn_revoked_' . $user_id);
				return false;
			}

			// Check if still within expiration window
			if ((time() - $revoked_at) < $expiry) {
				return true;
			}

			// Expired, clean up
			delete_option('gatalyn_revoked_' . $user_id);
		}

		return false;
	}

	/**
	 * Garbage collection cron job callback.
	 * Cleans up expired fallback options created for Hostinger compatibility
	 * to prevent wp_options database bloat.
	 */
	public static function run_garbage_collection()
	{
		global $wpdb;

		$prefixes = array(
			self::APPROVAL_PREFIX,
			self::REQUEST_PREFIX,
			self::LOGOUT_PREFIX,
			'gatalyn_revoked_',
			// Login lockout state written by Gatalyn_Store. Its option copy
			// carries an 'expires' key, so the loop below already handles it.
			Gatalyn_Limit_Login::KEY_PREFIX,
		);

		foreach ($prefixes as $prefix) {
			$options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like($prefix) . '%'
				)
			);

			foreach ($options as $row) {
				$data = maybe_unserialize($row->option_value);
				$is_expired = false;

				if (is_array($data)) {
					if (isset($data['expires'])) {
						if (time() > (int) $data['expires']) {
							$is_expired = true;
						}
					} elseif (isset($data['updated_at'])) {
						// Requests limit to 30 minutes max
						if (time() - (int) $data['updated_at'] > 30 * MINUTE_IN_SECONDS) {
							$is_expired = true;
						}
					} elseif (isset($data['revoked_at']) && isset($data['expiry'])) {
						// Revoked users timeout
						if (time() - (int) $data['revoked_at'] > (int) $data['expiry']) {
							$is_expired = true;
						}
					}
				}

				if ($is_expired) {
					delete_option($row->option_name);
				}
			}
		}
	}
}
