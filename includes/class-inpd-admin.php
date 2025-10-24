<?php
/**
 * Admin UI.
 *
 * @package INPDoctor
 */

declare( strict_types=1 );

final class INPD_Admin {
	public function hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ] );
	}

	public function menu(): void {
		add_menu_page(
			'INP Doctor',
			'INP Doctor',
			'manage_options',
			'inpd',
			[ $this, 'render_offenders' ],
			'dashicons-performance',
			58
		);
	}

	/** Simple sanitizer for small text. */
	private static function esc_short( string $s ): string {
		$s = wp_strip_all_tags( $s );
		if ( strlen( $s ) > 120 ) {
			$s = substr( $s, 0, 117 ) . '...';
		}
		return esc_html( $s );
	}

	public function render_offenders(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'inpd' ) );
		}

		$days       = isset( $_GET['days'] ) ? max( 1, min( 28, absint( $_GET['days'] ) ) ) : 7;   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$min_events = isset( $_GET['min'] ) ? max( 1, min( 100, absint( $_GET['min'] ) ) ) : 5;    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$limit      = isset( $_GET['limit'] ) ? max( 5, min( 100, absint( $_GET['limit'] ) ) ) : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page       = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;           // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url_like   = isset( $_GET['url'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['url'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sel_param  = isset( $_GET['sel'] ) ? (string) wp_unslash( $_GET['sel'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap">';
		echo '<h1>INP Doctor</h1>';

		// Selector details view.
		if ( '' !== $sel_param ) {
			$events = INPD_Report::selector_events( $sel_param, $days, 20, $url_like );

			echo '<h2>Recent events for <code>' . self::esc_short( $sel_param ) . '</code></h2>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>Time (UTC)</th><th>URL</th><th>INP</th><th>Long task</th><th>Device</th>';
			echo '</tr></thead><tbody>';

			if ( empty( $events ) ) {
				echo '<tr><td colspan="5">No events found.</td></tr>';
			} else {
				foreach ( $events as $e ) {
					$ts  = esc_html( gmdate( 'Y-m-d H:i:s', strtotime( (string) $e['ts'] ) ) );
					$url = (string) ( $e['page_url'] ?? '' );
					echo '<tr>';
					echo '<td>' . $ts . '</td>';
					echo '<td><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . self::esc_short( $url ) . '</a></td>';
					echo '<td>' . esc_html( (string) (int) $e['inp_ms'] ) . '</td>';
					echo '<td>' . esc_html( (string) (int) ( $e['long_task_ms'] ?? 0 ) ) . '</td>';
					echo '<td>' . esc_html( (string) ( $e['device_type'] ?? '' ) ) . '</td>';
					echo '</tr>';
				}
			}

			echo '</tbody></table>';

			$back_url = add_query_arg(
				[
					'page'  => 'inpd',
					'days'  => $days,
					'min'   => $min_events,
					'limit' => $limit,
					'url'   => $url_like,
				],
				admin_url( 'admin.php' )
			);
			echo '<p><a class="button" href="' . esc_url( $back_url ) . '">Back</a></p>';
			echo '</div>';
			return;
		}

		$total = INPD_Report::top_offenders_count( $days, $min_events, $url_like );
		$rows  = INPD_Report::top_offenders( $days, $min_events, $limit, $page, $url_like );
		$pages = max( 1, (int) ceil( $total / $limit ) );

		echo '<h2 class="title">Top Offenders (p75 INP)</h2>';

		// Filters.
		$base = admin_url( 'admin.php?page=inpd' );
		echo '<form method="get" action="' . esc_url( $base ) . '" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="inpd" />';
		echo 'Lookback: ';
		echo '<select name="days">';
		foreach ( [ 1, 3, 7, 14, 28 ] as $d ) {
			printf( '<option value="%d"%s>%dd</option>', $d, selected( $days, $d, false ), $d );
		}
		echo '</select> &nbsp; Min events: ';
		echo '<input type="number" min="1" max="100" name="min" value="' . esc_attr( (string) $min_events ) . '" style="width:80px" /> &nbsp; ';
		echo 'Rows: ';
		echo '<input type="number" min="5" max="100" name="limit" value="' . esc_attr( (string) $limit ) . '" style="width:80px" /> &nbsp; ';
		echo 'URL contains: ';
		echo '<input type="text" name="url" value="' . esc_attr( $url_like ) . '" style="width:200px" /> &nbsp; ';
		submit_button( 'Apply', 'secondary', '', false );
		echo '</form>';

		// Table.
		echo '<table class="widefat striped" style="margin-top:10px">';
		echo '<thead><tr>';
		echo '<th style="width:48%">Selector</th>';
		echo '<th>p75 (ms)</th>';
		echo '<th>Avg (ms)</th>';
		echo '<th>Worst (ms)</th>';
		echo '<th>Events</th>';
		echo '<th>Example URL</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="6">No data yet. Give it some traffic.</td></tr>';
		} else {
			foreach ( $rows as $r ) {
				echo '<tr>';
				$sel_link = add_query_arg(
					[
						'page'  => 'inpd',
						'days'  => $days,
						'min'   => $min_events,
						'limit' => $limit,
						'url'   => $url_like,
						'sel'   => (string) $r['selector'],
					],
					admin_url( 'admin.php' )
				);
				echo '<td><a href="' . esc_url( $sel_link ) . '"><code>' . self::esc_short( (string) $r['selector'] ) . '</code></a></td>';
				echo '<td>' . esc_html( (string) (int) $r['p75'] ) . '</td>';
				echo '<td>' . esc_html( (string) (int) $r['avg_inp'] ) . '</td>';
				echo '<td>' . esc_html( (string) (int) $r['worst_inp'] ) . '</td>';
				echo '<td>' . esc_html( (string) (int) $r['events'] ) . '</td>';
				$ex = isset( $r['example_url'] ) ? (string) $r['example_url'] : '';
				echo '<td><a href="' . esc_url( $ex ) . '" target="_blank" rel="noopener noreferrer">' . self::esc_short( $ex ) . '</a></td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';

		// Pagination.
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $p = 1; $p <= $pages; $p++ ) {
				$url = add_query_arg(
					[
						'page'  => 'inpd',
						'days'  => $days,
						'min'   => $min_events,
						'limit' => $limit,
						'paged' => $p,
						'url'   => $url_like,
					],
					admin_url( 'admin.php' )
				);
				$cls = ( $p === $page ) ? ' class="button button-primary button-small"' : ' class="button button-small"';
				echo '<a' . $cls . ' href="' . esc_url( $url ) . '">' . esc_html( (string) $p ) . '</a> ';
			}
			echo '</div></div>';
		}

		echo '</div>';
	}
}
