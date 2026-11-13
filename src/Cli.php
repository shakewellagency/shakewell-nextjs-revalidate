<?php
/**
 * WP-CLI command.
 *
 * @package Shakewell\NextjsRevalidate
 */

namespace Shakewell\NextjsRevalidate;

defined( 'ABSPATH' ) || exit;

/**
 * Manual and emergency use.
 */
final class Cli {

	/**
	 * Refreshes pages on the Next.js site.
	 *
	 * ## OPTIONS
	 *
	 * [<path>...]
	 * : Page paths, for example /books/example/
	 *
	 * [--all]
	 * : Refresh every page.
	 *
	 * @when after_wp_load
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Flags.
	 */
	public static function run( $args, $assoc ): void {
		$all = ! empty( $assoc['all'] );
		if ( ! empty( $args ) === $all ) {
			\WP_CLI::error( 'Give page paths or --all, not both and not neither.' );
		}
		foreach ( $args as $path ) {
			if ( ! Collector::valid_path( $path ) ) {
				\WP_CLI::error( "Not a page path (start with /, no query or spaces): $path" );
			}
		}
		Collector::queue_manual( $args, $all );
		$response = Sender::send( true );
		if ( null === $response ) {
			\WP_CLI::error( 'Not configured: set the url and secret settings (see the readme).' );
		}
		if ( is_wp_error( $response ) ) {
			\WP_CLI::error( 'Request failed: ' . $response->get_error_code() );
		}
		$status = wp_remote_retrieve_response_code( $response );
		200 === $status ? \WP_CLI::success( 'Refreshed.' ) : \WP_CLI::error( "The site answered $status: " . wp_remote_retrieve_body( $response ) );
	}
}
