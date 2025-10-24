<?php
declare(strict_types=1);

final class INPD_Cron {
  public function hooks(): void { add_action('inpd_purge_old_events', [$this, 'purge']); }
  public function purge(): void {
    global $wpdb;
    $table = INPD_Plugin::table();
    $days  = (int) get_option('inpd_retention_days', 30);
    $wpdb->query( $wpdb->prepare('DELETE FROM ' . $table . ' WHERE ts < (UTC_TIMESTAMP() - INTERVAL %d DAY)', $days) );
  }
}
