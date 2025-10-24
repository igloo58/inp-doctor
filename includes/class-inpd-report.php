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
	 * @param int    $days       Lookback window in days.
	 * @param int    $min_events Minimum events per selector.
	 * @param int    $limit      Max rows to return.
	 * @param int    $page       1-based page number.
	 * @param string $url_like   Optional URL "contains" filter.
	 * @return array[]
	 */
	public static function top_offenders( int $days = 7, int $min_events = 5, int $limit = 50, int $page = 1, string $url_like = '' ): array {
		global $wpdb;

		$table   = INPD_Plugin::table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$limit   = max( 1, min( 200, $limit ) );
		$page    = max( 1, $page );
		$offset  = ( $page - 1 ) * $limit;

		// Avoid truncation on busy sites.
		@$wpdb->query( 'SET SESSION group_concat_max_len = 1048576' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		$where   = 't.ts >= %s AND t.target_selector <> \'\'';
		$params  = [ $cutoff ];

		if ( '' !== $url_like ) {
			$like     = '%' . $wpdb->esc_like( $url_like ) . '%';
			$where   .= ' AND t.page_url LIKE %s';
			$params[] = $like;
		}

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
			WHERE {$where}
			GROUP BY t.target_selector
			HAVING events >= %d
			ORDER BY p75 DESC
			LIMIT %d OFFSET %d
		";

		$query = $wpdb->prepare( $sql, array_merge( $params, [ $min_events, $limit, $offset ] ) );
		return (array) $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Count total selectors for pagination (with optional URL filter).
	 *
	 * @param int    $days       Lookback window in days.
	 * @param int    $min_events Minimum events per selector.
	 * @param string $url_like   Optional URL "contains" filter.
	 * @return int
	 */
	public static function top_offenders_count( int $days = 7, int $min_events = 5, string $url_like = '' ): int {
		global $wpdb;

		$table  = INPD_Plugin::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$where   = 't.ts >= %s AND t.target_selector <> \'\'';
		$params  = [ $cutoff ];

		if ( '' !== $url_like ) {
			$like     = '%' . $wpdb->esc_like( $url_like ) . '%';
			$where   .= ' AND t.page_url LIKE %s';
			$params[] = $like;
		}

		$sql = "
			SELECT COUNT(*) FROM (
				SELECT t.target_selector, COUNT(*) AS n
				FROM {$table} t
				WHERE {$where}
				GROUP BY t.target_selector
				HAVING n >= %d
			) x
		";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( $params, [ $min_events ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Recent events for a selector (for a details view).
	 *
	 * @param string $selector CSS selector.
	 * @param int    $days     Lookback window in days.
	 * @param int    $limit    Max events to return.
	 * @param string $url_like Optional URL "contains" filter.
	 * @return array[]
	 */
	public static function selector_events( string $selector, int $days = 7, int $limit = 20, string $url_like = '' ): array {
		global $wpdb;

		$table  = INPD_Plugin::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$limit  = max( 1, min( 200, $limit ) );

		$where   = 'ts >= %s AND target_selector = %s';
		$params  = [ $cutoff, $selector ];

		if ( '' !== $url_like ) {
			$like     = '%' . $wpdb->esc_like( $url_like ) . '%';
			$where   .= ' AND page_url LIKE %s';
			$params[] = $like;
		}

		$sql   = "SELECT ts, page_url, inp_ms, long_task_ms, device_type FROM {$table} WHERE {$where} ORDER BY ts DESC LIMIT %d";
		$query = $wpdb->prepare( $sql, array_merge( $params, [ $limit ] ) );

		return (array) $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
