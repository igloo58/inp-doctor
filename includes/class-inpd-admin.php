<?php
declare(strict_types=1);

final class INPD_Admin {
  public function hooks(): void {
    add_action('admin_menu', [$this, 'menu']);
  }
  public function menu(): void {
    add_menu_page(
      'INP Doctor', 'INP Doctor', 'manage_options', 'inpd',
      [$this, 'render_dashboard'], 'dashicons-performance', 58
    );
    add_submenu_page('inpd', 'Top Offenders', 'Top Offenders', 'manage_options', 'inpd-offenders', [$this,'render_offenders']);
    add_submenu_page('inpd', 'Settings', 'Settings', 'manage_options', 'inpd-settings', [$this,'render_settings']);
  }
  public function render_dashboard(): void { echo '<div class="wrap"><h1>INP Doctor — Dashboard</h1><p>RUM coming online…</p></div>'; }
  public function render_offenders(): void { echo '<div class="wrap"><h1>Top Offenders</h1><p>Populate from rollups.</p></div>'; }
  public function render_settings(): void { echo '<div class="wrap"><h1>Settings</h1><p>Sampling, retention, notices.</p></div>'; }
}
