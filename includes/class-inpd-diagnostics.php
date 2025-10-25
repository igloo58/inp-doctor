<?php
/**
 * Admin diagnostics pages.
 * - Scripts audit: per-request list of enqueued scripts, whether we deferred them, and why.
 *
 * @package INPDoctor
 */

declare( strict_types=1 );

final class INPD_Diagnostics {

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
			if ( preg_match( '/\s(async|defer|type=["\']module["\'])/i', $tag ) ) {
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
	 * Render admin table.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'inp-doctor' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Diagnostics – Scripts', 'inp-doctor' ) . '</h1>';
		echo '<p>' . esc_html__( 'Per-request list of front-end scripts and whether INP Doctor added defer. Visit a public page first, then come back to see data.', 'inp-doctor' ) . '</p>';

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Handle', 'inp-doctor' ) . '</th>';
		echo '<th>' . esc_html__( 'Deferred', 'inp-doctor' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'inp-doctor' ) . '</th>';
		echo '<th>' . esc_html__( 'Source', 'inp-doctor' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( self::$scripts ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No front-end scripts captured on this request.', 'inp-doctor' ) . '</td></tr>';
		} else {
			foreach ( self::$scripts as $s ) {
				echo '<tr>';
				echo '<td><code>' . esc_html( $s['handle'] ) . '</code></td>';
				echo '<td>' . ( $s['deferred'] ? '✅' : '—' ) . '</td>';
				echo '<td><code>' . esc_html( $s['reason'] ) . '</code></td>';
				echo '<td style="word-break:break-all">' . esc_html( $s['src'] ) . '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody></table>';
		echo '</div>';
	}
}
