<?php
/**
 * Plugin Name:       Shakewell Cache Revalidation for Next.js
 * Plugin URI:        https://github.com/shakewellagency/shakewell-nextjs-revalidate
 * Description:       Tells a headless Next.js front end which pages to revalidate when content changes. Signed, sent once per request, and off until configured.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-nextjs-revalidate
 *
 * @package Shakewell\NextjsRevalidate
 */

namespace Shakewell\NextjsRevalidate;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';
const FILE    = __FILE__;

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Sender.php';
require_once __DIR__ . '/src/Collector.php';
require_once __DIR__ . '/src/Cli.php';
require_once __DIR__ . '/src/Plugin.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
