<?php
/**
 * Settings: constants first, then environment variables, then a filter.
 *
 * @package Shakewell\NextjsRevalidate
 */

namespace Shakewell\NextjsRevalidate;

defined( 'ABSPATH' ) || exit;

/**
 * Every setting is a constant or environment variable named SHAKEWELL_NEXTJS_REVALIDATE_<NAME> (upper case),
 * and the result passes through the filter shakewell_nextjs_revalidate_<name> (lower case).
 * A constant wins over an environment variable. Lists and maps may be PHP arrays in a constant, or in an
 * environment variable a comma-separated list (lists) or a JSON object (maps).
 */
final class Config {

	/** Minimum secret length. Shorter values, and so any placeholder, count as "not configured". */
	const MIN_SECRET_LENGTH = 32;

	/**
	 * Reads one setting.
	 *
	 * @param string $name    Lower-case setting name, for example "timeout".
	 * @param mixed  $fallback Default. Its type (string, int, array) decides how an environment value is read.
	 * @return mixed
	 */
	public static function get( string $name, $fallback = '' ) {
		$const = 'SHAKEWELL_NEXTJS_REVALIDATE_' . strtoupper( $name );
		$value = null;
		if ( defined( $const ) ) {
			$value = constant( $const );
		} else {
			$env = getenv( $const );
			if ( is_string( $env ) && '' !== trim( $env ) ) {
				$value = trim( $env );
			}
		}
		if ( null === $value ) {
			$value = $fallback;
		} elseif ( is_array( $fallback ) && is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' ) );
		} elseif ( is_int( $fallback ) ) {
			$value = (int) $value;
		} elseif ( is_string( $fallback ) ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : $fallback;
		}
		return apply_filters( 'shakewell_nextjs_revalidate_' . $name, $value );
	}

	/**
	 * The settings needed to send, or null when not configured (the plugin then does nothing).
	 * Needs an https endpoint and a secret of at least 32 characters.
	 *
	 * @return array{url:string,secret:string,environment:string,timeout:int}|null
	 */
	public static function settings(): ?array {
		$url    = (string) self::get( 'url' );
		$secret = (string) self::get( 'secret' );
		if ( strlen( $secret ) < self::MIN_SECRET_LENGTH || 0 !== strncmp( $url, 'https://', 8 ) || empty( wp_parse_url( $url, PHP_URL_HOST ) ) ) {
			return null;
		}
		$environment = (string) self::get( 'environment', wp_get_environment_type() );
		return array(
			'url'         => $url,
			'secret'      => $secret,
			'environment' => '' === $environment ? wp_get_environment_type() : $environment,
			'timeout'     => max( 1, (int) self::get( 'timeout', 10 ) ),
		);
	}
}
