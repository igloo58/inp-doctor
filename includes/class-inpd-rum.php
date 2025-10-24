<?php
declare(strict_types=1);

final class INPD_RUM {
  public function hooks(): void { add_action('wp_enqueue_scripts', [$this, 'enqueue']); }
  public function enqueue(): void {
    if (is_admin() || wp_doing_ajax()) return;
    wp_register_script(
      'inpd-rum',
      plugins_url('../assets/rum/inpd-rum.umd.js', __FILE__),
      [],
      INPD_VERSION,
      [ 'strategy' => 'defer' ]
    );
    $cfg = [
      'endpoint' => rest_url('inpd/v1/event'),
      'token'    => INPD_Plugin::public_token(),
      'sample'   => (int) get_option('inpd_sample_rate', 100),
    ];
    wp_add_inline_script('inpd-rum', 'window.INPD_CFG=' . wp_json_encode($cfg) . ';', 'before');
    wp_enqueue_script('inpd-rum');
  }
}
