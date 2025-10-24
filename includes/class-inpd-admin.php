<?php
/** Admin shell */
declare(strict_types=1);
final class INPD_Admin {
  public function hooks(): void {
    add_action('admin_menu', [$this, 'menu']);
  }
  public function menu(): void {
    add_menu_page('INP Doctor','INP Doctor','manage_options','inpd', function(){
      echo '<div class=\'wrap\'><h1>INP Doctor</h1><p>Dashboard coming soon.</p></div>';
    }, 'dashicons-performance', 58);
  }
}
