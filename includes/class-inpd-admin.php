<?php
declare(strict_types=1);
final class INPD_Admin {
  public function hooks(): void { add_action('admin_menu', [$this, 'menu']); }
  public function menu(): void { /* placeholder admin menu */ }
}
