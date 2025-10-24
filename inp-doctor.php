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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INPD_FILE', __FILE__ );
define( 'INPD_VERSION', '1.0.0' );

require __DIR__ . '/includes/class-inpd-plugin.php';
INPD_Plugin::init();
