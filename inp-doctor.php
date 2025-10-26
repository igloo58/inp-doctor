<?php
/**
 * Plugin Name: INP Doctor
 * Description: Measure & fix INP with safe, reversible optimizations.
 * Version: 1.0.0
 * Requires at least: 6.3
 * Requires PHP: 8.0
 * Author: INP Doctor
 * License: GPL-2.0-or-later
 *
 * @package INP_Doctor
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'INPD_LITE_VERSION' ) ) {
	define( 'INPD_LITE_VERSION', '1.0.0' );
}

define( 'INPD_FILE', __FILE__ );
define( 'INPD_VERSION', '1.0.0' );

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'inp-doctor', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

require __DIR__ . '/includes/class-inpd-plugin.php';

register_deactivation_hook( __FILE__, [ 'INPD_Plugin', 'deactivate' ] );

INPD_Plugin::init();
