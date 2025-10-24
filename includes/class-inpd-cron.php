<?php
/**
 * Cron tasks (retention, etc).
 *
 * @package INPDoctor
 */

declare(strict_types=1);

final class INPD_Cron {
  public function hooks(): void {
    add_action('inpd_purge_old_events', [$this, 'purge']);
  }

  /**
   * Delete raw events older than 30 days.
   */
  public function purge(): void {
    global $wpdb;
    $table = INPD_Plugin::table();
    // Use GM time to align with token rotation.
    $cutoff = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
    $wpdb->query(
      $wpdb->prepare("DELETE FROM {$table} WHERE ts < %s", $cutoff)
    );
  }
}
