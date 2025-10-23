<?php
declare(strict_types=1);
final class INPD_Plugin {
  public static function init(): void {
    add_action('plugins_loaded', [__CLASS__, 'boot']);
    register_activation_hook(INPD_FILE, [__CLASS__, 'activate']);
  }
  public static function boot(): void { /* wire classes next */ }
  public static function activate(): void { /* dbDelta + options */ }
}
