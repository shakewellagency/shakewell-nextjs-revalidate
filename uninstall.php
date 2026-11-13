<?php
/**
 * Removes everything the plugin stored when it is deleted from the Plugins screen.
 *
 * @package Shakewell\NextjsRevalidate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The plugin stores nothing. Remove any retry still waiting in wp-cron.
wp_unschedule_hook( 'shakewell_nextjs_revalidate_retry' );
