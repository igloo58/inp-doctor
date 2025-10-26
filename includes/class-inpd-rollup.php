<?php
declare(strict_types=1);

/**
 * Daily rollups & retention.
 *
 * Aggregates yesterday's events into p50/p75/p95 per (path, selector, device).
 * Enforces retention: raw 30d, rollups 180d.
 */
final class INPD_Rollup {

	public function hooks(): void {
		add_action( 'inpd_rollup_daily', [ $this, 'run' ] );
	}

	public function run(): void {
		global $wpdb;
		$events  = $wpdb->prefix . 'inpd_events';
		$rollups = $wpdb->prefix . 'inpd_rollups';
		$y       = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		$supports_percentile = $this->supports_percentile_disc( $wpdb );

		$ok = false;

		if ( $supports_percentile ) {
			// MySQL 8+ path using PERCENTILE_DISC.
			$sql = $wpdb->prepare("
				INSERT INTO {$rollups} (d, page_path, target_selector, device_type, p50, p75, p95, cnt, worst)
				SELECT
					DATE(ts) AS d,
					LEFT(SUBSTRING_INDEX(page_url,'?',1),255) AS page_path,
					target_selector,
					device_type,
					PERCENTILE_DISC(0.50) WITHIN GROUP (ORDER BY inp_ms) AS p50,
					PERCENTILE_DISC(0.75) WITHIN GROUP (ORDER BY inp_ms) AS p75,
					PERCENTILE_DISC(0.95) WITHIN GROUP (ORDER BY inp_ms) AS p95,
					COUNT(*) AS cnt,
					MAX(inp_ms) AS worst
				FROM {$events}
				WHERE ts >= %s AND ts < DATE_ADD(%s, INTERVAL 1 DAY)
				GROUP BY d, page_path, target_selector, device_type
				ON DUPLICATE KEY UPDATE
					p50=VALUES(p50), p75=VALUES(p75), p95=VALUES(p95),
					cnt=VALUES(cnt), worst=VALUES(worst)
			", $y, $y );

			$ok = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		if ( false === $ok ) {
			// Fallback: approximate percentiles using GROUP_CONCAT so MySQL 5.7 can participate.
			@$wpdb->query( 'SET SESSION group_concat_max_len = 1048576' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

			$sql_fallback = $wpdb->prepare("
				INSERT INTO {$rollups} (d, page_path, target_selector, device_type, p50, p75, p95, cnt, worst)
				SELECT
					d,
					page_path,
					target_selector,
					device_type,
					CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(vals, ',', FLOOR(0.50 * (cnt - 1)) + 1), ',', -1) AS UNSIGNED) AS p50,
					CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(vals, ',', FLOOR(0.75 * (cnt - 1)) + 1), ',', -1) AS UNSIGNED) AS p75,
					CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(vals, ',', FLOOR(0.95 * (cnt - 1)) + 1), ',', -1) AS UNSIGNED) AS p95,
					cnt,
					worst
				FROM (
					SELECT
						DATE(ts) AS d,
						LEFT(SUBSTRING_INDEX(page_url,'?',1),255) AS page_path,
						target_selector,
						device_type,
						GROUP_CONCAT(inp_ms ORDER BY inp_ms SEPARATOR ',') AS vals,
						COUNT(*) AS cnt,
						MAX(inp_ms) AS worst
					FROM {$events}
					WHERE ts >= %s AND ts < DATE_ADD(%s, INTERVAL 1 DAY)
					GROUP BY DATE(ts), LEFT(SUBSTRING_INDEX(page_url,'?',1),255), target_selector, device_type
				) AS aggregated
				ON DUPLICATE KEY UPDATE
					p50=VALUES(p50), p75=VALUES(p75), p95=VALUES(p95),
					cnt=VALUES(cnt), worst=VALUES(worst)
			", $y, $y );

			$wpdb->query( $sql_fallback ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// Retention: raw > 30d, rollups > 180d.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$events}  WHERE ts < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", 30 ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$rollups} WHERE  d < DATE_SUB(CURDATE(),       INTERVAL %d DAY)", 180 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private function supports_percentile_disc( \wpdb $wpdb ): bool {
		$server_info = '';

		if ( isset( $wpdb->db_server_info ) ) {
			$server_info = (string) $wpdb->db_server_info;
		}

		$is_mariadb = false !== stripos( $server_info, 'mariadb' );

		if ( $is_mariadb ) {
			return false;
		}

		$version_raw = (string) $wpdb->db_version();
		$version     = preg_replace( '/[^0-9.].*/', '', $version_raw );

		if ( '' === $version ) {
			return false;
		}

		return version_compare( $version, '8.0', '>=' );
	}
}
