<?php
if (!defined('ABSPATH')) { exit; }

if (class_exists('SD_Module_OperatorPWA', false)) { return; }

final class SD_Module_OperatorPWA {

  public static function register() : void {
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
    add_action('wp_head', [__CLASS__, 'print_manifest_link'], 1);
    add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
  }

  private static function is_operator_surface() : bool {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    return ($uri !== '' && strpos($uri, '/operator/') !== false);
  }

  private static function plugin_base_url() : string {
    return trailingslashit(dirname(plugin_dir_url(__FILE__), 2));
  }

  private static function plugin_base_path() : string {
    return trailingslashit(dirname(__DIR__, 2));
  }

  private static function operator_scope_path() : string {
    $path = (string) wp_parse_url(home_url('/operator/'), PHP_URL_PATH);
    return $path !== '' ? trailingslashit($path) : '/operator/';
  }

  private static function operator_start_path() : string {
    $path = (string) wp_parse_url(home_url('/operator/trips/'), PHP_URL_PATH);
    return $path !== '' ? trailingslashit($path) : '/operator/trips/';
  }

  public static function enqueue() : void {
    if (!self::is_operator_surface()) return;
    if (!is_user_logged_in()) return;

    $user_id    = get_current_user_id();
    $tenant_id  = (int) get_user_meta($user_id, SD_Meta::TENANT_ID, true);
    $base_url   = self::plugin_base_url();
    $base_path  = self::plugin_base_path();
    $scope_path = self::operator_scope_path();
    $start_path = self::operator_start_path();

    $pwa_js_rel   = 'assets/js/operator-pwa.js';
    $push_js_rel  = 'assets/js/operator-push.js';
    $pwa_js_path  = $base_path . $pwa_js_rel;
    $push_js_path = $base_path . $push_js_rel;

    $pwa_ver  = file_exists($pwa_js_path) ? (string) filemtime($pwa_js_path) : '1.1.1';
    $push_ver = file_exists($push_js_path) ? (string) filemtime($push_js_path) : '1.1.1';

    wp_enqueue_script(
      'sd-operator-pwa',
      $base_url . $pwa_js_rel,
      [],
      $pwa_ver,
      true
    );

    wp_enqueue_script(
      'sd-operator-push',
      $base_url . $push_js_rel,
      ['sd-operator-pwa'],
      $push_ver,
      true
    );

    wp_localize_script('sd-operator-pwa', 'SD_OPERATOR_PWA', [
      'restBase'       => esc_url_raw(rest_url('sd/v1/operator/')),
      'restNonce'      => wp_create_nonce('wp_rest'),
      'userId'         => $user_id,
      'tenantId'       => $tenant_id,
      'swUrl'          => esc_url_raw(rest_url('sd/v1/operator/pwa-sw')),
      'manifestUrl'    => esc_url_raw(rest_url('sd/v1/operator/manifest')),
      'vapidPublicKey' => class_exists('SD_Module_OperatorPushKeys', false)
        ? (string) SD_Module_OperatorPushKeys::public_key()
        : '',
      'route'          => home_url('/operator/trips/'),
      'deepLinkBase'   => home_url('/operator/trips/'),
      'scopePath'      => $scope_path,
      'startPath'      => $start_path,
      'debug'          => true,
    ]);

    wp_add_inline_script(
      'sd-operator-pwa',
      'window.__sdOperatorPwaLocalized = true; document.documentElement.setAttribute("data-sd-pwa-config","present");',
      'before'
    );
  }

  public static function print_manifest_link() : void {
    if (!self::is_operator_surface()) return;
    if (!is_user_logged_in()) return;

    echo '<link rel="manifest" href="' . esc_url(rest_url('sd/v1/operator/manifest')) . '">' . "\n";
    echo '<meta name="theme-color" content="#0b1220">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
  }

  public static function register_rest_routes() : void {
    register_rest_route('sd/v1/operator', '/manifest', [
      'methods'             => 'GET',
      'callback'            => [__CLASS__, 'serve_manifest'],
      'permission_callback' => '__return_true',
    ]);

    register_rest_route('sd/v1/operator', '/pwa-sw', [
      'methods'             => 'GET',
      'callback'            => [__CLASS__, 'serve_sw'],
      'permission_callback' => '__return_true',
    ]);
  }

  public static function serve_manifest(\WP_REST_Request $request) {
    $start_url = home_url(self::operator_start_path());
    $scope_url = home_url(self::operator_scope_path());

    $data = [
      'name'             => 'SoloDrive Operator',
      'short_name'       => 'Operator',
      'start_url'        => $start_url,
      'scope'            => $scope_url,
      'display'          => 'standalone',
      'background_color' => '#0b1220',
      'theme_color'      => '#0b1220',
      'description'      => 'Tenant operations client for SoloDrive operators.',
    ];

    return new \WP_REST_Response(
      $data,
      200,
      [
        'Content-Type'  => 'application/manifest+json; charset=utf-8',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'X-Robots-Tag'  => 'noindex, nofollow',
      ]
    );
  }

  public static function serve_sw(\WP_REST_Request $request) {
    $path       = self::plugin_base_path() . 'assets/pwa/operator-sw.js';
    $scope_path = self::operator_scope_path();

    if (!file_exists($path)) {
      return new \WP_REST_Response(
        '// missing service worker' . "\n",
        200,
        [
          'Content-Type'           => 'application/javascript; charset=utf-8',
          'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
          'Service-Worker-Allowed' => $scope_path,
          'X-Robots-Tag'           => 'noindex, nofollow',
        ]
      );
    }

    $contents = (string) file_get_contents($path);

    return new \WP_REST_Response(
      $contents,
      200,
      [
        'Content-Type'           => 'application/javascript; charset=utf-8',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
        'Service-Worker-Allowed' => $scope_path,
        'X-Robots-Tag'           => 'noindex, nofollow',
      ]
    );
  }
}