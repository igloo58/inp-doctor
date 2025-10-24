<?php
/**
 * RUM enqueue (minimal).
 *
 * @package INPDoctor
 */

declare(strict_types=1);

final class INPD_RUM {
  public function hooks(): void {
    add_action('wp_enqueue_scripts', [$this, 'enqueue']);
  }

  public function enqueue(): void {
    $handle   = 'inpd-rum';
    $src      = plugins_url('../assets/rum/inpd-rum.umd.js', __FILE__);
    $deps     = [];
    $in_footer = true;

    $args = [];
    // WP 6.3+ supports script loading strategy.
    if (function_exists('wp_register_script')) {
      $args['strategy'] = 'defer';
    }

    wp_register_script($handle, $src, $deps, INPD_VERSION, $in_footer);
    if (! empty($args)) {
      // Back-compat: some WP versions ignore $args; harmless.
      wp_script_add_data($handle, 'strategy', 'defer');
    }

    wp_enqueue_script($handle);

    $cfg = [
      'endpoint' => esc_url_raw( rest_url('inpd/v1/event') ),
      'token'    => INPD_Plugin::public_token(),
      'sample'   => 100,
    ];
    wp_add_inline_script($handle, 'window.INPD_CFG=' . wp_json_encode($cfg) . ';', 'before');
  }
}
