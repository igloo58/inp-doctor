<?php
/**
 * Core bootstrap for INP Doctor.
 *
 * @package INPDoctor
 */

declare(strict_types=1);

final class INPD_Plugin {
  const OPT_TOKEN   = 'inpd_pub_token';
  const OPT_VERSION = 'inpd_db_version';
  const DB_VERSION  = '1';

  public static function init(): void {
    add_action('plugins_loaded', [__CLASS__, 'boot']);
    register_activation_hook(INPD_FILE, [__CLASS__, 'activate']);
  }

  /** Wire all components */
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

  /** Activation: DB/cron will be filled in the next feature PR */
  public static function activate(): void {
    // No-op for this hotfix; keeps activation hook wired.
  }

  /** DB table name helper */
  public static function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'inpd_events';
  }

  /** Ephemeral public token (rotated daily) */
  public static function public_token(): string {
    $tok = (string) get_option(self::OPT_TOKEN, '');
    $day = gmdate('Y-m-d');
    return wp_hash($tok . '|' . $day, 'nonce');
  }
}
