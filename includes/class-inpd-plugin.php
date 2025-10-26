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
	const DB_VERSION = '3';

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
		require_once __DIR__ . '/class-inpd-rollup.php';

		$report = new INPD_Report();

		( new INPD_Admin( $report ) )->hooks();
		( new INPD_REST() )->hooks();
		( new INPD_RUM() )->hooks();
		( new INPD_Cron() )->hooks();
		$spec   = new INPD_Speculation();
		$spec->hooks();
		$fixes = new INPD_Fixes();
		$fixes->hooks();
		$diag = new INPD_Diagnostics();
		$diag->hooks();
		$rollups = new INPD_Rollup();
		$rollups->hooks();
	}

	/** Install DB + schedule daily purge + seed token */
	public static function activate(): void {
		self::install_schema();

		if ( ! get_option( self::OPT_TOKEN ) ) {
			update_option( self::OPT_TOKEN, wp_generate_password( 24, false ), false );
		}

		if ( ! wp_next_scheduled( 'inpd_purge_old_events' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'inpd_purge_old_events' );
		}

		// Nightly rollup at ~02:00 UTC.
		if ( ! wp_next_scheduled( 'inpd_rollup_daily' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 02:00 UTC' ), 'daily', 'inpd_rollup_daily' );
		}
	}

	/** Install or upgrade database schema */
	private static function install_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$events  = $wpdb->prefix . 'inpd_events';
		$rollups = $wpdb->prefix . 'inpd_rollups';

		$sql_events = "CREATE TABLE {$events} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		ts DATETIME NOT NULL,
		page_url TEXT NOT NULL,
		interaction_type VARCHAR(32) NOT NULL,
		target_selector VARCHAR(255) NOT NULL,
		inp_ms SMALLINT UNSIGNED NOT NULL,
		long_task_ms SMALLINT UNSIGNED NULL,
		script_url VARCHAR(255) NULL,
		ua_family_hash BINARY(16) NULL,
		device_type VARCHAR(10) NOT NULL DEFAULT 'other',
		sample_rate TINYINT UNSIGNED DEFAULT 100,
		PRIMARY KEY (id),
		KEY ts (ts),
		KEY inp_ms (inp_ms),
		KEY interaction_type (interaction_type),
		KEY device_type (device_type)
		) {$charset};";

		$sql_rollups = "CREATE TABLE {$rollups} (
		d DATE NOT NULL,
		page_path VARCHAR(255) NOT NULL,
		target_selector VARCHAR(255) NOT NULL,
		device_type VARCHAR(10) NOT NULL DEFAULT 'other',
		p50 SMALLINT UNSIGNED NOT NULL,
		p75 SMALLINT UNSIGNED NOT NULL,
		p95 SMALLINT UNSIGNED NOT NULL,
		cnt INT UNSIGNED NOT NULL,
		worst SMALLINT UNSIGNED NOT NULL,
		PRIMARY KEY (d, page_path, target_selector, device_type),
		KEY d (d),
		KEY page_path (page_path),
		KEY target_selector (target_selector)
		) {$charset};";

		dbDelta( $sql_events );
		dbDelta( $sql_rollups );

		// Migrate old ENUM -> VARCHAR if it exists.
		$col = $wpdb->get_row( "SHOW COLUMNS FROM {$events} LIKE 'device_type'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $col && isset( $col->Type ) && stripos( (string) $col->Type, 'enum' ) !== false ) {
			$wpdb->query( "ALTER TABLE {$events} MODIFY device_type VARCHAR(10) NOT NULL DEFAULT 'other'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		update_option( self::OPT_VERSION, self::DB_VERSION, false );
	}

	/** Run installer when schema version changes */
	public static function maybe_upgrade(): void {
		$version = (string) get_option( self::OPT_VERSION, '' );

		if ( version_compare( (string) self::DB_VERSION, $version, '>' ) ) {
			self::install_schema();
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

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'inpd_rollup_daily' );
	}
}
