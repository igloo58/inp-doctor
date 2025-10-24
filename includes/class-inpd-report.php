<?php
/**
 * Reporting helpers (Top Offenders, etc.).
 *
 * @package INPDoctor
 */

declare( strict_types=1 );

final class INPD_Report {
	/**
	 * Top Offenders by target selector.
	 *
	 * Computes p75 using a GROUP_CONCAT percentile approach (works on MySQL/MariaDB).
	 * Falls back to averages if needed. We also cap list length via group_concat_max_len.
	 *
	 * @param int $days       Lookback window in days (default 7).
	 * @param int $min_events Minimum number of events to include a selector (default 5).
	 * @param int $limit      Max rows to return (default 50).
	 * @param int $page       1-based page number (default 1).
	 * @return array[]        Rows: selector, p75, avg_inp, worst_inp, events, example_url.
	 */
	public static function top_offenders( int $days = 7, int $min_events = 5, int $limit = 50, int $page = 1 ): array {
		global $wpdb;

		$table   = INPD_Plugin::table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$limit   = max( 1, min( 200, $limit ) );
		$page    = max( 1, $page );
		$offset  = ( $page - 1 ) * $limit;

		// Avoid truncation on active sites (safe no-op if host disallows).
		@$wpdb->query( 'SET SESSION group_concat_max_len = 1048576' );

		// Build query (percentile via ordered GROUP_CONCAT + SUBSTRING_INDEX).
		// Note: target_selector can be empty; exclude empty for usefulness.
		$sql = "
			SELECT
				t.target_selector     AS selector,
				CAST(
					SUBSTRING_INDEX(
						SUBSTRING_INDEX(GROUP_CONCAT(t.inp_ms ORDER BY t.inp_ms SEPARATOR ','), ',', CEILING(0.75 * COUNT(*))),
						',', -1
					) AS UNSIGNED
				)                     AS p75,
				ROUND(AVG(t.inp_ms))  AS avg_inp,
				MAX(t.inp_ms)         AS worst_inp,
				COUNT(*)              AS events,
				MIN(t.page_url)       AS example_url
			FROM {$table} t
			WHERE t.ts >= %s
			  AND t.target_selector <> ''
			GROUP BY t.target_selector
			HAVING events >= %d
			ORDER BY p75 DESC
			LIMIT %d OFFSET %d
		";

		// Prepare only the cutoff/min; limit/offset are ints we capped above.
		$query = $wpdb->prepare( $sql, $cutoff, $min_events, $limit, $offset );
		$rows  = (array) $wpdb->get_results( $query, ARRAY_A );

		return $rows;
	}

	/**
	 * Count total selectors meeting the min_events threshold for pagination.
	 *
	 * @param int $days       Lookback window in days.
	 * @param int $min_events Minimum events per selector.
	 * @return int
	 */
	public static function top_offenders_count( int $days = 7, int $min_events = 5 ): int {
		global $wpdb;

		$table  = INPD_Plugin::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$sql = "
			SELECT COUNT(*) FROM (
				SELECT t.target_selector, COUNT(*) AS n
				FROM {$table} t
				WHERE t.ts >= %s
				  AND t.target_selector <> ''
				GROUP BY t.target_selector
				HAVING n >= %d
			) x
		";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $cutoff, $min_events ) );
	}
}
