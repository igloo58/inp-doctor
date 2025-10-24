<?php
/**
 * Admin shell.
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
			[ $this, 'render_dashboard' ],
			'dashicons-performance',
			58
		);
	}

	public function render_dashboard(): void {
		echo '<div class="wrap"><h1>INP Doctor</h1><p>Dashboard coming soon.</p></div>';
	}
}
