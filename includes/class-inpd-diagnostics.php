<?php
/**
 * Admin diagnostics pages.
 * - Scripts audit: per-request list of enqueued scripts, whether we deferred them, and why.
 *
 * @package INPDoctor
 */

declare( strict_types=1 );

final class INPD_Diagnostics {

        private const OPTION_KEY = 'inpd_diagnostics_scripts';

	/**
	 * Collected script decisions for this request.
	 *
	 * @var array<int, array{handle:string,src:string,deferred:bool,reason:string}>
	 */
	private static array $scripts = [];

	/**
	 * Register hooks.
	 */
        public function hooks(): void {
                add_action( 'admin_menu', [ $this, 'menu' ] );
                // Record final tags after our defer filter has run (priority 20).
                add_filter( 'script_loader_tag', [ $this, 'tap_script' ], 20, 3 );
                add_action( 'shutdown', [ $this, 'persist_scripts' ] );
        }

	/**
	 * Add submenu entry.
	 */
	public function menu(): void {
		add_submenu_page(
			'inpd',
			__( 'Diagnostics', 'inp-doctor' ),
			__( 'Diagnostics', 'inp-doctor' ),
			'manage_options',
			'inpd-diagnostics',
			[ $this, 'render' ]
		);
	}

	/**
	 * Capture each printed <script> and derive decision.
	 *
	 * @param string $tag    Final HTML tag.
	 * @param string $handle Script handle.
	 * @param string $src    Script src.
	 * @return string
	 */
	public function tap_script( string $tag, string $handle, string $src ): string {
		if ( is_admin() || '' === $src ) {
			return $tag;
		}

		$deferred = (bool) preg_match( '/\sdefer(\s|>)/i', $tag );
		$reason   = $deferred ? 'deferred' : 'skipped';

                if ( ! $deferred ) {
                        // Mirror reasons from our preset logic.
                        if ( preg_match( '/\s(async|defer|type\s*=\s*["\']module["\'])/i', $tag ) ) {
				$reason = 'has-async-defer-or-module';
			} elseif ( 'admin-bar' === $handle ) {
				$reason = 'admin-bar';
			} else {
				$deny = (array) apply_filters( 'inpd/scripts/denylist', [] );
				if ( in_array( $handle, $deny, true ) ) {
					$reason = 'denylist';
				} else {
					// Optional: detect inline "before" and skip reason.
					global $wp_scripts;
					if ( isset( $wp_scripts->registered[ $handle ]->extra['before'] ) && ! empty( $wp_scripts->registered[ $handle ]->extra['before'] ) ) {
						$reason = 'has-inline-before';
					}
				}
			}
		}

                self::$scripts[] = [
                        'handle'   => (string) $handle,
                        'src'      => (string) $src,
                        'deferred' => $deferred,
                        'reason'   => $reason,
                ];

		return $tag;
	}

        /**
         * Persist captured scripts for later viewing in admin.
         */
        public function persist_scripts(): void {
                if ( is_admin() || empty( self::$scripts ) ) {
                        return;
                }

                update_option(
                        self::OPTION_KEY,
                        [
                                'captured_at' => current_time( 'timestamp', true ),
                                'scripts'     => self::$scripts,
                        ],
                        false
                );
        }

        /**
         * Render admin table.
         */
        public function render(): void {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'inp-doctor' ) );
                }

                $stored     = get_option( self::OPTION_KEY, [] );
                $captured   = [];
                $captured_at = null;

                if ( is_array( $stored ) ) {
                        if ( isset( $stored['scripts'] ) && is_array( $stored['scripts'] ) ) {
                                $captured = $stored['scripts'];
                        }

                        if ( isset( $stored['captured_at'] ) ) {
                                $captured_at = (int) $stored['captured_at'];
                        }
                }

                echo '<div class="wrap">';
                echo '<h1>' . esc_html__( 'Diagnostics – Scripts', 'inp-doctor' ) . '</h1>';
                echo '<p>' . esc_html__( 'Per-request list of front-end scripts and whether INP Doctor added defer. Visit a public page first, then come back to see data.', 'inp-doctor' ) . '</p>';

                if ( $captured_at ) {
                        echo '<p>' . esc_html( sprintf( __( 'Most recent capture: %s', 'inp-doctor' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $captured_at ) ) ) . '</p>';
                }

                echo '<table class="widefat striped">';
                echo '<thead><tr>';
                echo '<th>' . esc_html__( 'Handle', 'inp-doctor' ) . '</th>';
                echo '<th>' . esc_html__( 'Deferred', 'inp-doctor' ) . '</th>';
                echo '<th>' . esc_html__( 'Reason', 'inp-doctor' ) . '</th>';
                echo '<th>' . esc_html__( 'Source', 'inp-doctor' ) . '</th>';
                echo '</tr></thead><tbody>';

                if ( empty( $captured ) ) {
                        echo '<tr><td colspan="4">' . esc_html__( 'No front-end scripts captured on this request.', 'inp-doctor' ) . '</td></tr>';
                } else {
                        foreach ( $captured as $s ) {
                                if ( ! is_array( $s ) ) {
                                        continue;
                                }

                                $handle   = isset( $s['handle'] ) ? (string) $s['handle'] : '';
                                $deferred = ! empty( $s['deferred'] );
                                $reason   = isset( $s['reason'] ) ? (string) $s['reason'] : '';
                                $src      = isset( $s['src'] ) ? (string) $s['src'] : '';

                                echo '<tr>';
                                echo '<td><code>' . esc_html( $handle ) . '</code></td>';
                                echo '<td>' . ( $deferred ? '✅' : '—' ) . '</td>';
                                echo '<td><code>' . esc_html( $reason ) . '</code></td>';
                                echo '<td style="word-break:break-all">' . esc_html( $src ) . '</td>';
                                echo '</tr>';
                        }
                }

		echo '</tbody></table>';
		echo '</div>';
	}
}
