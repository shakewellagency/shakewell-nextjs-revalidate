<?php
/**
 * Plugin bootstrap.
 *
 * @package Shakewell\NextjsRevalidate
 */

namespace Shakewell\NextjsRevalidate;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress. Registers its hooks always, so a filter added later by a theme still counts. Nothing is sent until an endpoint and a secret are configured.
 */
final class Plugin {

	/**
	 * Registers hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
		Collector::boot();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'shakewell-nextjs-revalidate', array( Cli::class, 'run' ) );
		}
	}
}
