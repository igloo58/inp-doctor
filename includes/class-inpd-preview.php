<?php
declare(strict_types=1);

/**
 * Preview mode for safe fixes (admin-only).
 *
 * When enabled in settings, fixes apply only if the user has the cookie set
 * and has manage_options capability. Cookie is set via admin bar toggle.
 */
final class INPD_Preview {
	const OPT_ENABLE = 'inpd_preview_mode';
	const COOKIE     = 'inpd_preview';
	const NONCE      = 'inpd_preview_nonce';

	public function hooks(): void {
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'admin_post_inpd_preview_toggle', [ $this, 'toggle' ] );
		add_action( 'admin_bar_menu', [ $this, 'admin_bar' ], 100 );
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	public function register(): void {
		register_setting(
			'inpd',
			self::OPT_ENABLE,
			[
				'type'              => 'boolean',
				'sanitize_callback' => static function ( $v ) { return (bool) $v; },
				'default'           => true, // conservative: preview first.
				'show_in_rest'      => false,
			]
		);
	}

	/** Toggle handler sets/unsets the cookie for the current admin. */
	public function toggle(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'inp-doctor' ), 403 );
		}
		$enable = isset( $_GET['on'] ); // ?on=1 to enable, otherwise disable.

		if ( $enable ) {
			setcookie(
				self::COOKIE,
				'1',
				[
					'expires'  => time() + DAY_IN_SECONDS,
					'path'     => COOKIEPATH ?: '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		} else {
			setcookie(
				self::COOKIE,
				'',
				[
					'expires'  => time() - HOUR_IN_SECONDS,
					'path'     => COOKIEPATH ?: '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				]
			);
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/** Admin bar toggle. */
	public function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'manage_options' ) || ! self::enabled() ) {
			return;
		}
		$active = self::active();
		$url    = wp_nonce_url(
			add_query_arg(
				[ 'action' => 'inpd_preview_toggle', $active ? 'off' : 'on' => 1 ],
				admin_url( 'admin-post.php' )
			),
			self::NONCE
		);

		$bar->add_node( [
			'id'    => 'inpd-preview',
			'title' => $active ? 'INP Doctor Preview: ON' : 'INP Doctor Preview: OFF',
			'href'  => $url,
			'meta'  => [ 'class' => $active ? 'ab-item inpd-preview-on' : 'ab-item inpd-preview-off' ],
		] );
	}

	public function notice(): void {
		if ( is_admin() && self::enabled() && self::active() ) {
			echo '<div class="notice notice-info"><p>' .
			esc_html__( 'INP Doctor Preview is active for your session. Only you see fixes until you disable Preview Mode or turn it off globally.', 'inp-doctor' ) .
			'</p></div>';
		}
	}

	/** Global enable switch from settings. */
	public static function enabled(): bool {
		return (bool) get_option( self::OPT_ENABLE, true );
	}

	/** Is preview cookie active for this admin user? */
	public static function active(): bool {
		return isset( $_COOKIE[ self::COOKIE ] ) && current_user_can( 'manage_options' );
	}
}
