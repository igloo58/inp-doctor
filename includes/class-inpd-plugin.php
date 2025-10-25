<?php
/**
 * Core bootstrap for INP Doctor.
 *
 * @package INPDoctor
 */

declare( strict_types=1 );

final class INPD_Plugin {
	const OPT_TOKEN = 'inpd_pub_token';
	const OPT_VERSION = 'inpd_db_version';
	const DB_VERSION = '2';

	/** Entry point */
	public static function init(): void {
		add_action( 'plugins_loaded', [ __CLASS__, 'boot' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_upgrade' ] );
		register_activation_hook( INPD_FILE, [ __CLASS__, 'activate' ] );
	}

	/** Wire components */
	public static function boot(): void {
		require_once __DIR__ . '/class-inpd-admin.php';
		require_once __DIR__ . '/class-inpd-report.php';
		require_once __DIR__ . '/class-inpd-rest.php';
		require_once __DIR__ . '/class-inpd-rum.php';
		require_once __DIR__ . '/class-inpd-cron.php';
		require_once __DIR__ . '/class-inpd-speculation.php';
		require_once __DIR__ . '/class-inpd-fixes.php';
		require_once __DIR__ . '/class-inpd-diagnostics.php';

		( new INPD_Admin() )->hooks();
		( new INPD_REST() )->hooks();
		( new INPD_RUM() )->hooks();
		( new INPD_Cron() )->hooks();
		$spec   = new INPD_Speculation();
		$spec->hooks();
		$fixes = new INPD_Fixes();
		$fixes->hooks();
		$diag = new INPD_Diagnostics();
		$diag->hooks();
	}

	/** Install DB + schedule daily purge + seed token */
	public static function activate(): void {
		self::install();

		if ( ! get_option( self::OPT_TOKEN ) ) {
			update_option( self::OPT_TOKEN, wp_generate_password( 24, false ), false );
		}

		if ( ! wp_next_scheduled( 'inpd_purge_old_events' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'inpd_purge_old_events' );
		}
	}

	/** Install or upgrade database schema */
	private static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// Raw RUM events table (30d retention).
		$sql = <<<SQL
CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ts DATETIME NOT NULL,
  page_url TEXT NOT NULL,
  interaction_type VARCHAR(32) NOT NULL,
  target_selector VARCHAR(255) NOT NULL,
  inp_ms SMALLINT UNSIGNED NOT NULL,
  long_task_ms SMALLINT UNSIGNED NULL,
  script_url VARCHAR(255) NULL,
  ua_family_hash BINARY(16) NULL,
  device_type ENUM('desktop','mobile','tablet','other') DEFAULT 'other',
  sample_rate TINYINT UNSIGNED DEFAULT 100,
  PRIMARY KEY (id),
  KEY ts (ts),
  KEY inp_ms (inp_ms),
  KEY interaction_type (interaction_type),
  KEY device_type (device_type)
) {$charset};
SQL;

		dbDelta( $sql );
		update_option( self::OPT_VERSION, self::DB_VERSION, false );
	}

	/** Run installer when schema version changes */
	public static function maybe_upgrade(): void {
		$version = (string) get_option( self::OPT_VERSION, '' );

		if ( self::DB_VERSION !== $version ) {
			self::install();
		}
	}

	/** DB table helper */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'inpd_events';
	}

	/** Ephemeral daily token for frontend beacons */
	public static function public_token(): string {
		$tok = (string) get_option( self::OPT_TOKEN, '' );
		$day = gmdate( 'Y-m-d' );

		return wp_hash( $tok . '|' . $day, 'nonce' );
	}
}
