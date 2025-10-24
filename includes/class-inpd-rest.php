<?php
declare(strict_types=1);

final class INPD_REST {
  const NS = 'inpd/v1';

  public function hooks(): void { add_action('rest_api_init', [$this, 'routes']); }

  public function routes(): void {
    register_rest_route(self::NS, '/event', [
      'methods'  => \WP_REST_Server::CREATABLE,
      'permission_callback' => '__return_true',
      'args' => [],
      'callback' => [$this, 'receive']
    ]);
  }

  public function receive(\WP_REST_Request $req) {
    $origin = $req->get_header('origin') ?: $req->get_header('referer');
    if ($origin && parse_url(home_url(), PHP_URL_HOST) !== parse_url($origin, PHP_URL_HOST)) {
      return new \WP_Error('inpd_origin', 'Bad origin', ['status'=>403]);
    }
    $cfgToken = INPD_Plugin::public_token();
    $body = $req->get_json_params();
    if (!is_array($body)) return rest_ensure_response(['ok'=>false]);

    $token = $body['token'] ?? '';
    if (!hash_equals($cfgToken, (string)$token)) {
      return new \WP_Error('inpd_token', 'Bad token', ['status'=>403]);
    }

    $rows = $body['events'] ?? [];
    if (!is_array($rows)) $rows = [$body];

    global $wpdb;
    $table = INPD_Plugin::table();
    $ok = true;
    foreach ($rows as $r) {
      $ok = $ok && (bool) $wpdb->insert($table, [
        'ts' => gmdate('Y-m-d H:i:s', (int)($r['t'] ?? time())),
        'page_url' => substr((string)($r['u'] ?? ''), 0, 65535),
        'interaction_type' => substr((string)($r['type'] ?? ''), 0, 32),
        'target_selector' => substr((string)($r['sel'] ?? ''), 0, 255),
        'inp_ms' => max(0, (int)($r['inp'] ?? 0)),
        'long_task_ms' => isset($r['lt']) ? max(0, (int)$r['lt']) : null,
        'script_url' => substr((string)($r['src'] ?? ''), 0, 255),
        'ua_family_hash' => $this->h16((string)($r['ua'] ?? '')),
        'device_type' => in_array(($r['dev'] ?? 'other'), ['desktop','mobile','tablet','other'], true) ? $r['dev'] : 'other',
        'sample_rate' => max(1, min(100, (int)($r['sr'] ?? 100))),
      ], [
        '%s','%s','%s','%s','%d','%d','%s','%s','%s','%d'
      ]);
    }
    return rest_ensure_response(['ok'=>$ok]);
  }

  private function h16(string $v): ?string {
    if ($v === '') return null;
    $bin = md5($v, true);
    return $bin;
  }
}
