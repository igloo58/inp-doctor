<?php
/**
 * RUM enqueue shell.
 *
 * @package INPDoctor
 */

declare(strict_types=1);

final class INPD_RUM {
  public function hooks(): void {
    add_action('wp_enqueue_scripts', [$this, 'enqueue']);
  }

  public function enqueue(): void {
    // Enqueue minimal stub in next feature PR.
  }
}
