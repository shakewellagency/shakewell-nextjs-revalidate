<?php
/**
 * Signs and sends the revalidation request.
 *
 * @package Shakewell\NextjsRevalidate
 */

namespace Shakewell\NextjsRevalidate;

defined( 'ABSPATH' ) || exit;

/**
 * Sends ONE signed POST per PHP request, with the JSON body Collector built.
 *
 * Headers (the prefix is the header_prefix setting, default "x-revalidate"):
 *   <prefix>-timestamp, <prefix>-event and <prefix>-signature.
 * The signature is "sha256=" plus the hex HMAC-SHA256 of "<environment>.<timestamp>.<event>." followed by the exact
 * body bytes, keyed with the shared secret. The secret is never sent and never logged. Use the
 * shakewell_nextjs_revalidate_signature filter to sign another way.
 *
 * At shutdown the editor's response is finished first when PHP-FPM allows (fastcgi_finish_request), so a save never waits
 * for the request and an error in it cannot break the save. If the site cannot be reached, or the answer is uncertain, one
 * retry with the SAME event id and body is scheduled with wp-cron, up to three attempts, so the front end can tell a retry
 * from a new event.
 */
final class Sender {

	/** The wp-cron hook that repeats an uncertain send. */
	const RETRY_HOOK = 'shakewell_nextjs_revalidate_retry';

	/** Attempts after the first send. */
	const MAX_ATTEMPTS = 3;

	/**
	 * Writes one line to the PHP error log. Callers pass counts, scope, status, reason and event id only, never a key or a body.
	 *
	 * @param string $message Message.
	 */
	public static function log( string $message ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational log, the plugin has no other output.
		error_log( '[shakewell-nextjs-revalidate] ' . $message );
	}

	/**
	 * The signature for a request.
	 *
	 * @param string $secret      Shared secret.
	 * @param string $environment Environment label that is part of the signed message.
	 * @param string $timestamp   Unix time as a string.
	 * @param string $event       Event id.
	 * @param string $body        Exact body.
	 */
	public static function sign( string $secret, string $environment, string $timestamp, string $event, string $body ): string {
		$signature = 'sha256=' . hash_hmac( 'sha256', "$environment.$timestamp.$event." . $body, $secret );
		return (string) apply_filters( 'shakewell_nextjs_revalidate_signature', $signature, $secret, $environment, $timestamp, $event, $body );
	}

	/**
	 * Failures worth another try: no answer, a server error or the rate limit. Other 4xx answers will not change.
	 *
	 * @param array|\WP_Error $response Response.
	 */
	public static function uncertain( $response ): bool {
		if ( is_wp_error( $response ) ) {
			return true;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return 0 === $code || 429 === $code || $code >= 500;
	}

	/**
	 * HTTP status of a response, 0 when there was none.
	 *
	 * @param array|\WP_Error $response Response.
	 */
	private static function status_of( $response ): int {
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * One signed POST. Signed fresh each time (the front end wants a recent timestamp); the event id and body stay the same on a retry.
	 *
	 * @param array  $settings Config::settings().
	 * @param string $event    Event id.
	 * @param string $body     JSON body.
	 * @param bool   $blocking Wait for the answer.
	 * @return array|\WP_Error
	 */
	private static function post( array $settings, string $event, string $body, bool $blocking ) {
		$timestamp = (string) time();
		$prefix    = trim( (string) Config::get( 'header_prefix', 'x-revalidate' ) );
		$headers   = array(
			'Content-Type'      => 'application/json',
			"$prefix-timestamp" => $timestamp,
			"$prefix-event"     => $event,
			"$prefix-signature" => self::sign( $settings['secret'], $settings['environment'], $timestamp, $event, $body ),
		);
		return wp_remote_post(
			$settings['url'],
			array(
				'blocking'    => $blocking,
				'timeout'     => $blocking ? $settings['timeout'] : min( 2, $settings['timeout'] ),
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => $body,
			)
		);
	}

	/**
	 * Sends what was collected (one request), then forgets it. When PHP-FPM allows, the editor's response is finished first,
	 * so the request can be blocking and its answer checked; without that it is fire-and-forget and a retry is scheduled
	 * because the outcome is unknown. $blocking is also for the command line, which wants the answer.
	 *
	 * @param bool $blocking Wait for the answer.
	 * @return array|\WP_Error|null The response, or null when nothing was sent.
	 */
	public static function send( bool $blocking = false ) {
		// First of all, so the editor never waits for the lookups below.
		if ( ! $blocking && function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			$blocking = true;
		}
		$job      = Collector::flush();
		$settings = null === $job ? null : Config::settings();
		if ( null === $settings ) {
			return null; // Not configured: fail closed.
		}
		$body     = $job['body'];
		$event    = bin2hex( random_bytes( 16 ) );
		$response = self::post( $settings, $event, $body, $blocking );
		$cli      = defined( 'WP_CLI' ) && WP_CLI;
		$retry    = ! $blocking || self::uncertain( $response );
		self::log(
			sprintf(
				'sent event=%s scope=%s targets=%d status=%d reason=%s%s%s',
				$event,
				$job['scope'],
				$job['count'],
				self::status_of( $response ),
				$job['reasons'],
				is_wp_error( $response ) ? ' error=' . $response->get_error_code() : '',
				$retry && ! $cli ? ' retry=scheduled' : ''
			)
		);
		if ( $retry && ! $cli ) {
			self::schedule_retry( $event, $body, 1 );
		}
		return $response;
	}

	/**
	 * Schedules the next attempt, 30 s, 2 min, then 4.5 min later.
	 *
	 * @param string $event   Event id.
	 * @param string $body    JSON body.
	 * @param int    $attempt Attempt number, from 1.
	 */
	private static function schedule_retry( string $event, string $body, int $attempt ): void {
		if ( $attempt <= self::MAX_ATTEMPTS ) {
			wp_schedule_single_event( time() + 30 * $attempt * $attempt, self::RETRY_HOOK, array( $event, $body, $attempt ) );
		}
	}

	/**
	 * The wp-cron retry: same event id and body, blocking, so the answer decides whether to try again.
	 *
	 * @param string $event   Event id.
	 * @param string $body    JSON body.
	 * @param int    $attempt Attempt number.
	 */
	public static function retry( $event, $body, $attempt ): void {
		try {
			$settings = Config::settings();
			if ( null === $settings || ! is_string( $event ) || ! is_string( $body ) ) {
				return;
			}
			$response = self::post( $settings, $event, $body, true );
			$again    = self::uncertain( $response );
			self::log( sprintf( 'retry event=%s attempt=%d status=%d %s', $event, (int) $attempt, self::status_of( $response ), $again ? 'failed' : 'ok' ) );
			if ( $again ) {
				self::schedule_retry( $event, $body, (int) $attempt + 1 );
			}
		} catch ( \Throwable $e ) {
			self::log( 'error in retry: ' . get_class( $e ) );
		}
	}
}
