<?php
/** Cron shell */
declare(strict_types=1);
final class INPD_Cron {
  public function hooks(): void { add_action('inpd_purge_old_events', [$this, 'purge']); }
  public function purge(): void { /* purge in feature PRs */ }
}
