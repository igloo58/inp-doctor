<?php
/**
 * REST API endpoints.
 *
 * @package INPDoctor
 */

declare(strict_types=1);

final class INPD_REST {
  private const NAMESPACE = 'inpd/v1';

  public function hooks(): void {
    add_action('rest_api_init', [$this, 'routes']);
  }

  public function routes(): void {
    register_rest_route(
      self::NAMESPACE,
      '/event',
      [
        'methods'             => 'POST',
        'callback'            => [$this, 'intake'],
        'permission_callback' => '__return_true',
        'args'                => [
          'token'  => ['required' => false],
          'events' => ['required' => true],
        ],
      ]
    );
  }

  /**
   * POST /inpd/v1/event
   * Body: { token?: string, events: Array<...> }
   */
  public function intake(\WP_REST_Request $req): \WP_REST_Response {
    // Basic same-origin guard.
    $origin = $req->get_header('origin');
    if ($origin && wp_parse_url(home_url(), PHP_URL_HOST) !== wp_parse_url($origin, PHP_URL_HOST)) {
      return new \WP_REST_Response(['ok' => false, 'err' => 'forbidden-origin'], 403);
    }

    // Token must match our ephemeral public token.
    $token = (string) ($req->get_param('token') ?? $req->get_header('x-inpd-token') ?? '');
    if ($token !== INPD_Plugin::public_token()) {
      return new \WP_REST_Response(['ok' => false, 'err' => 'bad-token'], 403);
    }

    $events = $req->get_param('events');
    if (! is_array($events) || empty($events)) {
      return new \WP_REST_Response(['ok' => false, 'err' => 'no-events'], 400);
    }

    // Limit payload size (simple abuse protection).
    if (count($events) > 100) {
      $events = array_slice($events, 0, 100);
    }

    global $wpdb;
    $table = INPD_Plugin::table();
    $inserted = 0;

    foreach ($events as $e) {
      // Defensive parsing.
      $ts   = isset($e['t']) ? (int) $e['t'] : time();
      $url  = isset($e['u']) ? (string) $e['u'] : '/';
      $typ  = isset($e['type']) ? (string) $e['type'] : 'click';
      $sel  = isset($e['sel']) ? substr((string) $e['sel'], 0, 255) : '';
      $inp  = max(0, (int) ($e['inp'] ?? 0));
      $lt   = isset($e['lt']) ? max(0, (int) $e['lt']) : null;
      $src  = isset($e['src']) ? substr((string) $e['src'], 0, 255) : null;
      $dev  = in_array(($e['dev'] ?? 'other'), ['desktop','mobile','tablet','other'], true) ? $e['dev'] : 'other';
      $sr   = isset($e['sr']) ? (int) $e['sr'] : 100;

      $wpdb->insert(
        $table,
        [
          'ts'               => gmdate('Y-m-d H:i:s', $ts),
          'page_url'         => $url,
          'interaction_type' => $typ,
          'target_selector'  => $sel,
          'inp_ms'           => $inp,
          'long_task_ms'     => $lt,
          'script_url'       => $src,
          'ua_family_hash'   => null, // reserved (may add UA family hashing later)
          'device_type'      => $dev,
          'sample_rate'      => $sr,
        ],
        [
          '%s','%s','%s','%s','%d','%d','%s','%s','%s','%d'
        ]
      );

      if ($wpdb->rows_affected > 0) {
        $inserted++;
      }
    }

    return new \WP_REST_Response(['ok' => true, 'n' => $inserted], 200);
  }
}
