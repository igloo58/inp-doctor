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

		// MySQL 8 path using PERCENTILE_DISC.
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

		// If the query errors (e.g., host lacks PERCENTILE_DISC), fall back to rank approximation.
		$ok = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( false === $ok ) {
			// Fallback: approximate percentiles via ranked rows.
			$sql_fallback = $wpdb->prepare("
				INSERT INTO {$rollups} (d, page_path, target_selector, device_type, p50, p75, p95, cnt, worst)
				SELECT
					d,
					page_path,
					target_selector,
					device_type,
					MAX(CASE WHEN rnk >= FLOOR(0.50*(cnt-1))+1 THEN inp_ms END) AS p50,
					MAX(CASE WHEN rnk >= FLOOR(0.75*(cnt-1))+1 THEN inp_ms END) AS p75,
					MAX(CASE WHEN rnk >= FLOOR(0.95*(cnt-1))+1 THEN inp_ms END) AS p95,
					cnt,
					MAX(inp_ms) AS worst
				FROM (
					SELECT
						DATE(ts) AS d,
						LEFT(SUBSTRING_INDEX(page_url,'?',1),255) AS page_path,
						target_selector,
						device_type,
						inp_ms,
						ROW_NUMBER() OVER (PARTITION BY DATE(ts), LEFT(SUBSTRING_INDEX(page_url,'?',1),255), target_selector, device_type ORDER BY inp_ms) AS rnk,
						COUNT(*) OVER (PARTITION BY DATE(ts), LEFT(SUBSTRING_INDEX(page_url,'?',1),255), target_selector, device_type) AS cnt
					FROM {$events}
					WHERE ts >= %s AND ts < DATE_ADD(%s, INTERVAL 1 DAY)
				) AS ranked
				GROUP BY d, page_path, target_selector, device_type
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
}
