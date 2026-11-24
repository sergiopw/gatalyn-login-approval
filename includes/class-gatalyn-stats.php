<?php

/**
 * Daily counters for the dashboard: failed logins, lockouts, approvals, denials.
 *
 * One small option holds the last KEEP_DAYS days, keyed by site-local date.
 * Counting is best effort: two requests in the same instant can lose an increment.
 *
 * @package Gatalyn_Login_Approval
 */

if (! defined('ABSPATH')) {
	exit;
}

class Gatalyn_Stats
{
	const OPTION    = 'gatalyn_daily_stats';
	const KEEP_DAYS = 60;

	const LOG_OPTION = 'gatalyn_attempt_log';
	const LOG_MAX    = 100;

	/**
	 * Set while another logger already records the attempt.
	 *
	 * @var bool
	 */
	public static $quiet = false;

	/**
	 * Remembers one attempt: what was typed, from where, and what became of it.
	 * Keeps the newest LOG_MAX entries. The name is attacker-controlled text.
	 *
	 * @param string $username Name or email typed.
	 * @param string $result   Short code: sent, unknown, throttled, locked, wrong_password.
	 * @param string $source   Where: approval or login.
	 *
	 * @return void
	 */
	public static function log_attempt($username, $result, $source)
	{
		if (self::$quiet) {
			return;
		}

		$log   = self::attempts();
		$log[] = array(
			't'      => time(),
			'ip'     => class_exists('Gatalyn_IP') ? Gatalyn_IP::get_client_ip() : '',
			'user'   => substr(sanitize_text_field((string) $username), 0, 80),
			'result' => $result,
			'source' => $source,
		);

		update_option(self::LOG_OPTION, array_slice($log, -self::LOG_MAX), false);
	}

	/**
	 * @return array[] Oldest first.
	 */
	public static function attempts()
	{
		$log = get_option(self::LOG_OPTION, array());

		return is_array($log) ? $log : array();
	}

	/**
	 * Adds one to today's counter.
	 *
	 * @param string $key One of failed, lockout, approved, denied.
	 *
	 * @return void
	 */
	public static function record($key)
	{
		if (! in_array($key, array('failed', 'lockout', 'approved', 'denied'), true)) {
			return;
		}

		$stats = self::all();
		$today = wp_date('Y-m-d');

		if (! isset($stats[$today])) {
			$stats[$today] = array();
		}

		$stats[$today][$key] = (isset($stats[$today][$key]) ? (int) $stats[$today][$key] : 0) + 1;

		ksort($stats);
		$stats = array_slice($stats, -self::KEEP_DAYS, null, true);

		update_option(self::OPTION, $stats, false);
	}

	/**
	 * @return array date => array( key => count )
	 */
	public static function all()
	{
		$stats = get_option(self::OPTION, array());

		return is_array($stats) ? $stats : array();
	}

	/**
	 * Counts for the last $days days, oldest first, including empty days.
	 *
	 * @param int $days Number of days, today included.
	 *
	 * @return array[] Each: array( 'date' => Y-m-d, 'failed' => n, 'lockout' => n, 'approved' => n, 'denied' => n )
	 */
	public static function series($days)
	{
		$stats  = self::all();
		$days   = max(1, (int) $days);
		$result = array();
		$now    = time();

		for ($i = $days - 1; $i >= 0; $i--) {
			$date = wp_date('Y-m-d', $now - $i * DAY_IN_SECONDS);
			$row  = isset($stats[$date]) && is_array($stats[$date]) ? $stats[$date] : array();

			$result[] = array(
				'date'     => $date,
				'failed'   => isset($row['failed']) ? (int) $row['failed'] : 0,
				'lockout'  => isset($row['lockout']) ? (int) $row['lockout'] : 0,
				'approved' => isset($row['approved']) ? (int) $row['approved'] : 0,
				'denied'   => isset($row['denied']) ? (int) $row['denied'] : 0,
			);
		}

		return $result;
	}
}
