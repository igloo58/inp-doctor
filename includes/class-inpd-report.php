<?php
/**
 * Reporting helpers (Top Offenders, etc.).
 *
 * @package INPDoctor
 */

declare( strict_types=1 );

final class INPD_Report {
	/**
	 * Cached rollups availability state.
	 *
	 * @var bool|null
	 */
	private $rollups_available = null;

	/**
	 * Determine if the rollups table exists.
	 */
	public function rollups_available(): bool {
		global $wpdb;

		if ( null !== $this->rollups_available ) {
			return $this->rollups_available;
		}

		$table_like              = $wpdb->esc_like( $wpdb->prefix ) . 'inpd_rollups';
		$this->rollups_available = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$table_like}'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $this->rollups_available;
	}

	/**
	 * Fetch aggregated "Top Offenders" data using rollups when available.
	 *
	 * @param array $args {
		 *     Arguments controlling the query.
		 *
		 *     @type int    $days           Lookback window in days.
		 *     @type string $url_like       Optional URL "contains" filter.
		 *     @type int    $min_events     Minimum events per selector.
		 *     @type int    $limit          Max rows to return.
		 *     @type int    $offset         Result offset.
		 *     @type bool   $prefer_rollups Whether to prefer rollups when available.
		 * }
		 * @return array[]
		 */
		public function get_top_offenders( array $args ): array {
			$days          = max( 1, (int) ( $args['days'] ?? 7 ) );
			$url_like      = (string) ( $args['url_like'] ?? '' );
			$min_events    = max( 1, (int) ( $args['min_events'] ?? 5 ) );
			$limit         = min( 2000, max( 1, (int) ( $args['limit'] ?? 50 ) ) );
			$offset        = max( 0, (int) ( $args['offset'] ?? 0 ) );
			$prefer_rollup = ! empty( $args['prefer_rollups'] );

			$use_rollups = $prefer_rollup && $this->rollups_available() && $days >= 1;

			return $use_rollups
			? $this->get_top_offenders_from_rollups( $days, $url_like, $min_events, $limit, $offset )
			: $this->get_top_offenders_from_raw( $days, $url_like, $min_events, $limit, $offset );
		}

		/**
		 * Count selectors that qualify for "Top Offenders".
		 *
		 * @param array $args Same args as get_top_offenders().
		 * @return int
		 */
		public function get_top_offenders_count( array $args ): int {
			$days          = max( 1, (int) ( $args['days'] ?? 7 ) );
			$url_like      = (string) ( $args['url_like'] ?? '' );
			$min_events    = max( 1, (int) ( $args['min_events'] ?? 5 ) );
			$prefer_rollup = ! empty( $args['prefer_rollups'] );

			$use_rollups = $prefer_rollup && $this->rollups_available() && $days >= 1;

			return $use_rollups
			? $this->get_top_offenders_count_from_rollups( $days, $url_like, $min_events )
			: $this->get_top_offenders_count_from_raw( $days, $url_like, $min_events );
		}

		/**
		 * Recent events for a selector (for a details view).
		 *
		 * @param string $selector CSS selector.
		 * @param int    $days     Lookback window in days.
		 * @param int    $limit    Max events to return.
		 * @param string $url_like Optional URL "contains" filter.
		 * @param int    $offset   Result offset for pagination.
		 * @return array[]
		 */
		public function selector_events( string $selector, int $days = 7, int $limit = 20, string $url_like = '', int $offset = 0 ): array {
			global $wpdb;

			$table  = INPD_Plugin::table();
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			$limit  = min( 2000, max( 1, $limit ) );
			$offset = max( 0, $offset );

			$where  = 'ts >= %s AND target_selector = %s';
			$params = [ $cutoff, $selector ];

			if ( '' !== $url_like ) {
				$like     = '%' . $wpdb->esc_like( $url_like ) . '%';
				$where   .= ' AND page_url LIKE %s';
				$params[] = $like;
			}

			$sql   = "SELECT ts, page_url, target_selector, inp_ms, long_task_ms, device_type FROM {$table} WHERE {$where} ORDER BY ts DESC LIMIT %d OFFSET %d";
			$query = $wpdb->prepare( $sql, array_merge( $params, [ $limit, $offset ] ) );

			return (array) $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		/**
		 * Raw-table backed Top Offenders query.
		 */
		private function get_top_offenders_from_raw( int $days, string $url_like, int $min_events, int $limit, int $offset ): array {
			global $wpdb;

			$table  = INPD_Plugin::table();
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			// Avoid truncation on busy sites.
			@$wpdb->query( 'SET SESSION group_concat_max_len = 1048576' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

$where  = 't.ts >= %s AND t.target_selector <> \'\'';
			$params = [ $cutoff ];

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
			$rows  = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			return is_array( $rows ) ? $rows : [];
		}

		/**
		 * Raw-table backed Top Offenders count.
		 */
		private function get_top_offenders_count_from_raw( int $days, string $url_like, int $min_events ): int {
			global $wpdb;

			$table  = INPD_Plugin::table();
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

$where  = 't.ts >= %s AND t.target_selector <> \'\'';
			$params = [ $cutoff ];

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
		 * Rollup-backed Top Offenders query.
		 */
		private function get_top_offenders_from_rollups( int $days, string $url_like, int $min_events, int $limit, int $offset ): array {
			global $wpdb;

			$rollups = $wpdb->prefix . 'inpd_rollups';
			$from    = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
			$to      = gmdate( 'Y-m-d', time() );

			$like = '%' . $wpdb->esc_like( $url_like ) . '%';

			$sql = $wpdb->prepare(
			"
			SELECT
			target_selector                       AS selector,
			MAX(p75)                                AS p75,
			ROUND(SUM(p50 * cnt) / NULLIF(SUM(cnt),0)) AS avg_inp,
			MAX(worst)                              AS worst_inp,
			SUM(cnt)                                AS events,
			MIN(CONCAT('/', TRIM(LEADING '/' FROM page_path))) AS example_url
			FROM {$rollups}
			WHERE d >= %s AND d < %s
			AND page_path LIKE %s
			GROUP BY target_selector
			HAVING events >= %d
			ORDER BY p75 DESC
			LIMIT %d OFFSET %d
			",
			$from,
			$to,
			$like,
			$min_events,
			$limit,
			$offset
			);

			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			return is_array( $rows ) ? $rows : [];
		}

		/**
		 * Rollup-backed Top Offenders count.
		 */
		private function get_top_offenders_count_from_rollups( int $days, string $url_like, int $min_events ): int {
			global $wpdb;

			$rollups = $wpdb->prefix . 'inpd_rollups';
			$from    = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );
			$to      = gmdate( 'Y-m-d', time() );
			$like    = '%' . $wpdb->esc_like( $url_like ) . '%';

			$sql = $wpdb->prepare(
			"
			SELECT COUNT(*) FROM (
			SELECT target_selector, SUM(cnt) AS total_cnt
			FROM {$rollups}
			WHERE d >= %s AND d < %s
			AND page_path LIKE %s
			GROUP BY target_selector
			HAVING total_cnt >= %d
			) AS aggregated
			",
			$from,
			$to,
			$like,
			$min_events
			);

			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
