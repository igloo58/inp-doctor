<?php
declare(strict_types=1);

final class INPD_Plugin {
  const OPT_TOKEN = 'inpd_pub_token';

  public static function init(): void {
    add_action('plugins_loaded', [__CLASS__, 'boot']);
  }

  public static function boot(): void {
    require_once __DIR__ . '/class-inpd-admin.php';
    require_once __DIR__ . '/class-inpd-rest.php';
    require_once __DIR__ . '/class-inpd-rum.php';
    require_once __DIR__ . '/class-inpd-cron.php';

    (new INPD_Admin())->hooks();
    (new INPD_REST())->hooks();
    (new INPD_RUM())->hooks();
    (new INPD_Cron())->hooks();
  }

  public static function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'inpd_events';
  }

  public static function public_token(): string {
    $tok = (string) get_option(self::OPT_TOKEN, '');
    $day = gmdate('Y-m-d');
    return wp_hash($tok . '|' . $day, 'nonce');
  }
}
