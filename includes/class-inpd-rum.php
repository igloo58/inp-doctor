<?php
/** RUM shell */
declare(strict_types=1);
final class INPD_RUM {
  public function hooks(): void { add_action('wp_enqueue_scripts', [$this, 'enqueue']); }
  public function enqueue(): void { /* enqueue RUM in feature PRs */ }
}
