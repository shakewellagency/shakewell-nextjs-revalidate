<?php
/**
 * Shared set-up: a configured endpoint, neutral content types, and a recorder instead of real HTTP.
 *
 * @package Shakewell\NextjsRevalidate
 */

namespace Shakewell\NextjsRevalidate\Tests;

use Shakewell\NextjsRevalidate\Collector;
use Shakewell\NextjsRevalidate\Sender;
use WP_UnitTestCase;

/**
 * Base class. Tests act through real WordPress functions and read the request that would have been sent.
 */
abstract class RevalidateTestCase extends WP_UnitTestCase {

	/** A 48-character test secret, not a real one. */
	const SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef';

	/**
	 * Requests that would have been sent: array of array( url, args ).
	 *
	 * @var array<int,array>
	 */
	protected array $requests = array();

	/**
	 * Events passed to wp-cron: array of array( hook, args ).
	 *
	 * @var array<int,array>
	 */
	protected array $scheduled = array();

	/**
	 * What the fake endpoint answers.
	 *
	 * @var array|\WP_Error
	 */
	protected $reply;

	/** Where the PHP error log goes during a test. */
	protected string $log_file = '';

	/** Previous error_log ini value. */
	private string $old_log = '';

	/** Test-defined revalidate filters. */
	private const FILTERS = array(
		'listings',
		'post_types',
		'shared_types',
		'options',
		'option_effects',
		'ignored_option_pages',
		'ignored_taxonomies',
		'menu_types',
		'membership_meta',
		'related',
		'skip_user_agent',
		'max_targets',
		'source',
		'header_prefix',
		'environment',
		'timeout',
		'signature',
		'url',
		'secret',
	);

	public function set_up(): void {
		parent::set_up();
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_URL=https://frontend.example/api/revalidate' );
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_SECRET=' . self::SECRET );
		$this->requests  = array();
		$this->scheduled = array();
		$this->reply     = array(
			'response' => array( 'code' => 200 ),
			'body'     => '',
		);
		$this->log_file  = (string) tempnam( sys_get_temp_dir(), 'reval' );
		$this->old_log   = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = array( $url, $args );
				return $this->reply;
			},
			10,
			3
		);
		add_filter(
			'pre_schedule_event',
			function ( $pre, $event ) {
				$this->scheduled[] = array( $event->hook, $event->args );
				return true;
			},
			10,
			2
		);

		// Pretty permalinks first: post types only get rewrite rules when a structure is set.
		$this->set_permalink_structure( '/%postname%/' );
		register_post_type(
			'book',
			array(
				'public'  => true,
				'rewrite' => array( 'slug' => 'books' ),
			)
		);
		register_post_type(
			'event',
			array(
				'public'  => true,
				'rewrite' => array( 'slug' => 'events' ),
			)
		);
		register_post_type( 'secret_note', array( 'public' => false ) );
		register_taxonomy( 'genre', 'book', array( 'public' => false ) );
		register_taxonomy( 'topic', 'book', array( 'public' => true ) );
		flush_rewrite_rules();

		add_filter(
			'shakewell_nextjs_revalidate_listings',
			static fn() => array(
				'book'  => array( '/books/' ),
				'event' => array( '/whats-on/', '/calendar/' ),
			)
		);
		Collector::reset();
	}

	public function tear_down(): void {
		foreach ( self::FILTERS as $name ) {
			remove_all_filters( 'shakewell_nextjs_revalidate_' . $name );
		}
		Collector::reset();
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_URL' );
		putenv( 'SHAKEWELL_NEXTJS_REVALIDATE_SECRET' );
		unset( $_GET['page'], $_SERVER['HTTP_USER_AGENT'] );
		ini_set( 'error_log', $this->old_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		if ( is_file( $this->log_file ) ) {
			unlink( $this->log_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		parent::tear_down();
	}

	/**
	 * Creates a published or other post, then forgets what the creation collected.
	 *
	 * @param string $type   Post type.
	 * @param string $slug   Slug.
	 * @param string $status Status.
	 */
	protected function make( string $type, string $slug, string $status = 'publish' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => $type,
				'post_name'   => $slug,
				'post_title'  => ucfirst( $slug ),
				'post_status' => $status,
			)
		);
		Collector::reset();
		return $id;
	}

	/**
	 * Sends what was collected, as the shutdown hook does, and returns the decoded body of the one request, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	protected function flush(): ?array {
		$this->requests = array();
		Sender::send();
		$this->assertLessThanOrEqual( 1, count( $this->requests ), 'at most one request per flush' );
		return $this->requests ? json_decode( $this->requests[0][1]['body'], true ) : null;
	}

	/**
	 * The default source label, added to every expected body.
	 *
	 * @param array<string,mixed> $body Body without the source.
	 * @return array<string,mixed>
	 */
	protected function with_source( array $body ): array {
		return $body + array( 'source' => 'shakewell-nextjs-revalidate' );
	}
}
