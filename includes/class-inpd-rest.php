<?php
/**
 * REST shell.
 *
 * @package INPDoctor
 */

declare(strict_types=1);

final class INPD_REST {
  public function hooks(): void {
    add_action('rest_api_init', [$this, 'routes']);
  }

  public function routes(): void {
    // Endpoints added in next feature PR.
  }
}
