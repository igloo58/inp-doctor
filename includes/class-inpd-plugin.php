<?php
declare(strict_types=1);

final class INPD_Plugin {
  public static function init(): void {
    add_action('plugins_loaded', [__CLASS__, 'boot']);
  }
  public static function boot(): void {
    // Sprint-0 bootstrap; detailed classes will be added incrementally.
  }
}
