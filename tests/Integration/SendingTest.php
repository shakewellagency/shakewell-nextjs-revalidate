<?php
/**
 * Configuration, signing and the request itself.
 *
 * @package Shakewell\NextjsRevalidate
 */

namespace Shakewell\NextjsRevalidate\Tests;

use Shakewell\NextjsRevalidate\Collector;
use Shakewell\NextjsRevalidate\Config;
use Shakewell\NextjsRevalidate\Sender;

require_once __DIR__ . '/RevalidateTestCase.php';

/**
 * Fail-closed configuration, the signature, the headers and the log.
 */
final class SendingTest extends RevalidateTestCase {

	public function test_hooks_are_registered_on_plugins_loaded(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded', array( \Shakewell\NextjsRevalidate\Plugin::class, 'boot' ) ) );
		$this->assertNotFalse( has_action( 'transition_post_status', array( Collector::class, 'on_transition' ) ) );
	}

	public function test_nothing_is_sent_without_an_endpoint(): void {
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_URL' );
		$this->make( 'book', 'a' );
		Collector::queue_manual( array( '/books/a/' ), false );
		$this->assertNull( $this->flush() );
		$this->assertNull( Config::settings() );
	}

	public function test_nothing_is_sent_without_a_secret(): void {
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_SECRET' );
		Collector::queue_manual( array( '/books/a/' ), false );
		$this->assertNull( $this->flush() );
	}

	public function test_a_placeholder_or_short_secret_counts_as_not_configured(): void {
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_SECRET=REPLACE_ME' );
		Collector::queue_manual( array( '/books/a/' ), false );
		$this->assertNull( $this->flush() );
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_SECRET=' . str_repeat( 'a', 31 ) );
		$this->assertNull( Config::settings() );
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_SECRET=' . str_repeat( 'a', 32 ) );
		$this->assertNotNull( Config::settings() );
	}

	public function test_a_plain_http_endpoint_counts_as_not_configured(): void {
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_URL=http://frontend.example/api/revalidate' );
		$this->assertNull( Config::settings() );
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_URL=https://' );
		$this->assertNull( Config::settings() );
	}

	public function test_a_constant_wins_over_an_environment_variable(): void {
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_PROBE=from-env' );
		$this->assertSame( 'from-env', Config::get( 'probe' ) );
		define( 'SHAKEWELL_NEXTJS_REVALIDATE_PROBE', 'from-constant' );
		$this->assertSame( 'from-constant', Config::get( 'probe' ) );
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_PROBE' );
	}

	public function test_lists_and_maps_come_from_the_environment(): void {
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_POST_TYPES=book, event ,' );
		$this->assertSame( array( 'book', 'event' ), Config::get( 'post_types', array() ) );
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_POST_TYPES' );
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_RELATED={"book":[{"post_type":"event","meta_key":"book"}]}' );
		$this->assertSame( array( 'book' => array( array( 'post_type' => 'event', 'meta_key' => 'book' ) ) ), Config::get( 'related', array() ) ); // phpcs:ignore WordPress.Arrays.MultipleStatementAlignment
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_RELATED' );
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_TIMEOUT=7' );
		$this->assertSame( 7, Config::get( 'timeout', 10 ) );
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_TIMEOUT' );
	}

	public function test_a_filter_has_the_last_word(): void {
		add_filter( 'shakewell_nextjs_revalidate_url', static fn() => 'https://other.example/hook' );
		$this->assertSame( 'https://other.example/hook', Config::settings()['url'] );
	}

	public function test_the_environment_label_defaults_to_the_wordpress_environment(): void {
		$this->assertSame( wp_get_environment_type(), Config::settings()['environment'] );
		add_filter( 'shakewell_nextjs_revalidate_environment', static fn() => 'uat' );
		$this->assertSame( 'uat', Config::settings()['environment'] );
	}

	public function test_the_signature_matches_the_documented_algorithm(): void {
		// Fixed vector, also produced by Node: createHmac sha256 over "<environment>.<timestamp>.<event>." plus the body.
		$body = '{"scope":"page","paths":["/books/a/"]}';
		$this->assertSame(
			'sha256=fc9176fc8a184457b6734d0ca3623810cbfad925320911f9618fd9de0eb2d286',
			Sender::sign( self::SECRET, 'uat', '1793800000', 'abcdef0123456789abcdef', $body )
		);
	}

	public function test_the_signature_can_be_replaced_with_a_filter(): void {
		add_filter( 'shakewell_nextjs_revalidate_signature', static fn( $sig, $secret, $env ) => "custom-$env", 10, 3 );
		$this->assertSame( 'custom-uat', Sender::sign( self::SECRET, 'uat', '1', 'e', '{}' ) );
	}

	public function test_one_signed_request_with_the_headers_the_front_end_checks(): void {
		add_filter( 'shakewell_nextjs_revalidate_environment', static fn() => 'uat' );
		$id = $this->make( 'book', 'a', 'draft' );
		wp_publish_post( $id );
		$body = $this->flush();
		$this->assertNotNull( $body );
		[ $url, $args ] = $this->requests[0];
		$h              = $args['headers'];
		$this->assertSame( 'https://frontend.example/api/revalidate', $url );
		$this->assertFalse( $args['blocking'], 'without FPM the request does not wait' );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 2, $args['timeout'] );
		$this->assertSame( 'application/json', $h['Content-Type'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $h['x-revalidate-event'] );
		$this->assertMatchesRegularExpression( '/^\d{10}$/', $h['x-revalidate-timestamp'] );
		$this->assertLessThan( 5, abs( time() - (int) $h['x-revalidate-timestamp'] ) );
		$this->assertSame( Sender::sign( self::SECRET, 'uat', $h['x-revalidate-timestamp'], $h['x-revalidate-event'], $args['body'] ), $h['x-revalidate-signature'] );
		$this->assertArrayNotHasKey( 'x-revalidate-secret', $h );
	}

	public function test_a_waiting_request_uses_the_timeout_setting(): void {
		add_filter( 'shakewell_nextjs_revalidate_timeout', static fn() => 4 );
		Collector::queue_manual( array( '/books/a/' ), false );
		$this->requests = array();
		Sender::send( true );
		$this->assertTrue( $this->requests[0][1]['blocking'] );
		$this->assertSame( 4, $this->requests[0][1]['timeout'] );
		$this->assertSame( array(), $this->scheduled, 'an answered request needs no retry' );
	}

	public function test_the_header_prefix_and_source_are_settings(): void {
		add_filter( 'shakewell_nextjs_revalidate_header_prefix', static fn() => 'x-site' );
		add_filter( 'shakewell_nextjs_revalidate_source', static fn() => 'cms' );
		Collector::queue_manual( array( '/books/a/' ), false );
		$body = $this->flush();
		$this->assertSame( 'cms', $body['source'] );
		$this->assertArrayHasKey( 'x-site-signature', $this->requests[0][1]['headers'] );
		$this->assertArrayNotHasKey( 'x-revalidate-signature', $this->requests[0][1]['headers'] );
	}

	public function test_the_log_has_counts_and_status_but_never_the_secret_or_body(): void {
		$this->reply = array( 'response' => array( 'code' => 400 ) );
		$id          = $this->make( 'book', 'a', 'draft' );
		wp_publish_post( $id );
		$this->flush();
		$log = (string) file_get_contents( $this->log_file );
		$this->assertMatchesRegularExpression( '/\[shakewell-nextjs-revalidate\] sent event=[a-f0-9]{32} scope=page targets=3 status=400 reason=book/', $log );
		$this->assertStringNotContainsString( self::SECRET, $log );
		$this->assertStringNotContainsString( '/books/a/', $log );
	}

	public function test_a_failure_inside_a_hook_never_breaks_the_save(): void {
		add_filter(
			'shakewell_nextjs_revalidate_listings',
			static function () {
				throw new \RuntimeException( 'boom' );
			}
		);
		$id = self::factory()->post->create( array( 'post_type' => 'book' ) );
		$this->assertGreaterThan( 0, $id );
		$this->assertStringContainsString( 'error in hook: RuntimeException', (string) file_get_contents( $this->log_file ) );
	}

	public function test_page_paths_are_validated(): void {
		$this->assertTrue( Collector::valid_path( '/books/a-b/' ) );
		foreach ( array( '//x', '/a?b', '/a b', 'x', '/a#b', '/a\\b', '/' . str_repeat( 'a', 200 ) ) as $bad ) {
			$this->assertFalse( Collector::valid_path( $bad ), $bad );
		}
	}
}
