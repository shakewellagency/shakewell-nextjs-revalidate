<?php
/**
 * Failed and uncertain sends.
 *
 * @package Shakewell\NextjsRevalidate
 */

namespace Shakewell\NextjsRevalidate\Tests;

use Shakewell\NextjsRevalidate\Collector;
use Shakewell\NextjsRevalidate\Sender;

require_once __DIR__ . '/RevalidateTestCase.php';

/**
 * A retry repeats the same event id and body, at most three times, and only when the outcome is uncertain.
 */
final class RetryTest extends RevalidateTestCase {

	/**
	 * Sends one change without waiting, as without PHP-FPM, and returns the event id and body that were sent.
	 *
	 * @return array{0:string,1:string}
	 */
	private function first_send(): array {
		Collector::queue_manual( array( '/books/a/' ), false );
		$this->flush();
		return array( $this->requests[0][1]['headers']['x-revalidate-event'], $this->requests[0][1]['body'] );
	}

	public function test_a_send_that_did_not_wait_schedules_a_retry_with_the_same_event_and_body(): void {
		[ $event, $body ] = $this->first_send();
		$this->assertSame( array( array( Sender::RETRY_HOOK, array( $event, $body, 1 ) ) ), $this->scheduled );
		$this->assertStringContainsString( 'retry=scheduled', (string) file_get_contents( $this->log_file ) );
	}

	public function test_the_retry_reuses_the_event_id_and_body_and_waits(): void {
		[ $event, $body ] = $this->first_send();
		$this->scheduled  = array();
		$this->requests   = array();
		$this->reply      = array( 'response' => array( 'code' => 503 ) );
		Sender::retry( $event, $body, 1 );
		$sent = $this->requests[0][1];
		$this->assertSame( $event, $sent['headers']['x-revalidate-event'] );
		$this->assertSame( $body, $sent['body'] );
		$this->assertTrue( $sent['blocking'] );
		$this->assertCount( 1, $this->scheduled, 'a server error is tried again' );
		$this->assertSame( 2, $this->scheduled[0][1][2] );
		$this->assertStringContainsString( "retry event=$event attempt=1 status=503 failed", (string) file_get_contents( $this->log_file ) );
	}

	public function test_a_retry_is_signed_again_for_the_new_time(): void {
		[ $event, $body ] = $this->first_send();
		$this->requests   = array();
		Sender::retry( $event, $body, 1 );
		$h = $this->requests[0][1]['headers'];
		$this->assertSame( Sender::sign( self::SECRET, wp_get_environment_type(), $h['x-revalidate-timestamp'], $event, $body ), $h['x-revalidate-signature'] );
	}

	public function test_there_is_no_attempt_beyond_the_third(): void {
		[ $event, $body ] = $this->first_send();
		$this->scheduled  = array();
		$this->reply      = array( 'response' => array( 'code' => 500 ) );
		Sender::retry( $event, $body, 3 );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_an_answer_that_will_not_change_is_not_retried(): void {
		[ $event, $body ] = $this->first_send();
		foreach ( array( 200, 400, 401, 403 ) as $code ) {
			$this->scheduled = array();
			$this->reply     = array( 'response' => array( 'code' => $code ) );
			Sender::retry( $event, $body, 1 );
			$this->assertSame( array(), $this->scheduled, "status $code" );
		}
	}

	public function test_the_rate_limit_and_network_errors_are_retried(): void {
		[ $event, $body ] = $this->first_send();
		$this->scheduled  = array();
		$this->reply      = array( 'response' => array( 'code' => 429 ) );
		Sender::retry( $event, $body, 1 );
		$this->assertCount( 1, $this->scheduled );
		$this->scheduled = array();
		$this->reply     = new \WP_Error( 'http_request_failed', 'down' );
		Sender::retry( $event, $body, 1 );
		$this->assertCount( 1, $this->scheduled );
	}

	public function test_a_retry_does_nothing_when_the_plugin_is_no_longer_configured(): void {
		[ $event, $body ] = $this->first_send();
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_SECRET' );
		$this->requests = array();
		Sender::retry( $event, $body, 1 );
		$this->assertSame( array(), $this->requests );
	}

	public function test_a_request_that_failed_while_waiting_is_logged_and_retried(): void {
		$this->reply = new \WP_Error( 'http_request_failed', 'down' );
		Collector::queue_manual( array( '/books/a/' ), false );
		Sender::send( true );
		$this->assertCount( 1, $this->scheduled );
		$this->assertStringContainsString( 'error=http_request_failed', (string) file_get_contents( $this->log_file ) );
	}
}
