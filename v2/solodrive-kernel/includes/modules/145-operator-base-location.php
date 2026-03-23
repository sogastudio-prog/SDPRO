<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorBaseLocation (v0.1)
 *
 * Purpose:
 * - Private operator route for editing tenant base location:
 *     /operator/base-location/
 *
 * Canon:
 * - Tenant is resolved from the authenticated operator user.
 * - Never trust a posted tenant id.
 * - Writes canonical tenant meta:
 *     sd_base_location_label
 *     sd_base_location_place_id
 *     sd_base_location_lat
 *     sd_base_location_lng
 *     sd_base_location_radius_m
 */

if (class_exists('SD_Module_OperatorBaseLocation', false)) { return; }

final class SD_Module_OperatorBaseLocation {

  private const QV = 'sd_operator_base_location';

  public static function register() : void {
    add_action('init', [__CLASS__, 'register_rewrites']);
    add_filter('query_vars', [__CLASS__, 'query_vars']);
    add_action('template_redirect', [__CLASS__, 'template_redirect']);

    add_action('admin_post_sd_operator_save_base_location', [__CLASS__, 'handle_save']);
  }

  public static function register_rewrites() : void {
    add_rewrite_rule('^operator/base-location/?$', 'index.php?' . self::QV . '=1', 'top');
  }

  public static function query_vars($vars) {
    $vars[] = self::QV;
    return $vars;
  }

  public static function template_redirect() : void {
    $is_route = (string) get_query_var(self::QV) === '1';

    if (!$is_route) {
      $path = self::request_path();
      $is_route = ($path === '/operator/base-location' || $path === '/operator/base-location/');
    }

    if (!$is_route) return;

    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);

    self::render_page();
    exit;
  }

  private static function render_page() : void {
    status_header(200);

    if (!is_user_logged_in()) {
      self::render_login_screen('/operator/base-location/');
      return;
    }

    $tenant_id = self::current_user_tenant_id();
    if ($tenant_id <= 0) {
      self::render_shell(
        'Base Location',
        self::styles() . '<div class="sd-op-wrap"><div class="sd-op-card"><h2>SoloDrive</h2><p>Your user is not assigned to a tenant yet.</p></div></div>'
      );
      return;
    }

    if (!self::current_user_can_operator_surface()) {
      self::render_shell(
        'Base Location',
        self::styles() . '<div class="sd-op-wrap"><div class="sd-op-card"><h2>SoloDrive</h2><p>Not authorized for operator access.</p></div></div>'
      );
      return;
    }

    if (class_exists('SD_Module_Places')) {
      SD_Module_Places::enqueue();
    }

    $tenant_name = (string) get_the_title($tenant_id);
    $label       = (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LABEL, true);
    $place_id    = defined('SD_Meta::BASE_LOCATION_PLACE_ID')
      ? (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_PLACE_ID, true)
      : '';
    $lat         = (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LAT, true);
    $lng         = (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LNG, true);
    $rad         = (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_RADIUS_M, true);
    if ($rad === '' || (int) $rad <= 0) $rad = '40000';

    $saved = isset($_GET['updated']) && (string) $_GET['updated'] === '1';

    $html  = self::styles();
    $html .= '<div class="sd-op-wrap sd-op-wrap--narrow">';
    $html .= '  <div class="sd-op-topbar">';
    $html .= '    <div>';
    $html .= '      <div class="sd-op-eyebrow">Operator</div>';
    $html .= '      <h1 class="sd-op-h1">Base Location</h1>';
    $html .= '      <div class="sd-op-sub">' . esc_html($tenant_name) . '</div>';
    $html .= '    </div>';
    $html .= '    <div class="sd-op-actions">';
    $html .= '      <a class="sd-op-btn" href="' . esc_url(home_url('/operator/')) . '">Back</a>';
    $html .= '      <a class="sd-op-btn sd-op-btn-primary" href="' . esc_url(home_url('/operator/trips/')) . '">Drive Mode</a>';
    $html .= '    </div>';
    $html .= '  </div>';

    if ($saved) {
      $html .= '<div class="sd-op-notice">Base location saved.</div>';
    }

    $html .= '  <div class="sd-op-card">';
    $html .= '    <div class="sd-op-card-head">';
    $html .= '      <h2>Operational base</h2>';
    $html .= '      <div class="sd-op-sub">Used when live operator location is unavailable.</div>';
    $html .= '    </div>';

    $html .= '    <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    $html .=        wp_nonce_field('sd_operator_base_location_save', '_wpnonce', true, false);
    $html .= '      <input type="hidden" name="action" value="sd_operator_save_base_location">';

    $html .= '      <div class="sd-op-field">';
    $html .= '        <label for="sd_base_location_label">Base address</label>';
    $html .= '        <input type="text" id="sd_base_location_label" name="sd_base_location_label" value="' . esc_attr($label) . '" placeholder="Start typing the base address..." autocomplete="off">';
    $html .= '        <input type="hidden" id="sd_base_location_place_id" name="sd_base_location_place_id" value="' . esc_attr($place_id) . '">';
    $html .= '        <div class="sd-op-help">Choose the address from the suggestions list so place id and coordinates are saved together.</div>';
    $html .= '      </div>';

    $html .= '      <div class="sd-op-grid-2">';
    $html .= '        <div class="sd-op-field">';
    $html .= '          <label for="sd_base_location_lat">Latitude</label>';
    $html .= '          <input type="text" id="sd_base_location_lat" name="sd_base_location_lat" value="' . esc_attr($lat) . '">';
    $html .= '        </div>';
    $html .= '        <div class="sd-op-field">';
    $html .= '          <label for="sd_base_location_lng">Longitude</label>';
    $html .= '          <input type="text" id="sd_base_location_lng" name="sd_base_location_lng" value="' . esc_attr($lng) . '">';
    $html .= '        </div>';
    $html .= '      </div>';

    $html .= '      <div class="sd-op-field">';
    $html .= '        <label for="sd_base_location_radius_m">Base radius (m)</label>';
    $html .= '        <input type="number" id="sd_base_location_radius_m" name="sd_base_location_radius_m" value="' . esc_attr($rad) . '" min="1000" step="100">';
    $html .= '      </div>';

    $html .= '      <div class="sd-op-actions">';
    $html .= '        <button class="sd-op-btn sd-op-btn-primary" type="submit">Save base location</button>';
    $html .= '      </div>';
    $html .= '    </form>';
    $html .= '  </div>';

    $html .= '</div>';
    $html .= self::places_bind_js();

    self::render_shell('Base Location', $html);
  }

  public static function handle_save() : void {
    if (!is_user_logged_in()) {
      wp_safe_redirect(home_url('/operator/base-location/'));
      exit;
    }

    $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_POST['_wpnonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'sd_operator_base_location_save')) {
      wp_safe_redirect(home_url('/operator/base-location/'));
      exit;
    }

    $tenant_id = self::current_user_tenant_id();
    if ($tenant_id <= 0 || !self::current_user_can_operator_surface()) {
      wp_safe_redirect(home_url('/operator/base-location/'));
      exit;
    }

    $label = isset($_POST['sd_base_location_label']) ? sanitize_text_field((string) wp_unslash($_POST['sd_base_location_label'])) : '';
    $pid   = isset($_POST['sd_base_location_place_id']) ? sanitize_text_field((string) wp_unslash($_POST['sd_base_location_place_id'])) : '';

    $lat_raw = isset($_POST['sd_base_location_lat']) ? trim((string) wp_unslash($_POST['sd_base_location_lat'])) : '';
    $lng_raw = isset($_POST['sd_base_location_lng']) ? trim((string) wp_unslash($_POST['sd_base_location_lng'])) : '';

    $lat = is_numeric($lat_raw) ? (string) ((float) $lat_raw) : '';
    $lng = is_numeric($lng_raw) ? (string) ((float) $lng_raw) : '';

    $rad = isset($_POST['sd_base_location_radius_m']) ? absint(wp_unslash($_POST['sd_base_location_radius_m'])) : 0;
    if ($rad <= 0) $rad = 40000;

    update_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LABEL, $label);
    if (defined('SD_Meta::BASE_LOCATION_PLACE_ID')) {
      update_post_meta($tenant_id, SD_Meta::BASE_LOCATION_PLACE_ID, $pid);
    }
    update_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LAT, $lat);
    update_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LNG, $lng);
    update_post_meta($tenant_id, SD_Meta::BASE_LOCATION_RADIUS_M, (string) $rad);

    wp_safe_redirect(add_query_arg(['updated' => '1'], home_url('/operator/base-location/')));
    exit;
  }

  private static function render_login_screen(string $redirect_path) : void {
    status_header(200);

    $html  = self::styles();
    $html .= '<div class="sd-op-login-wrap">';
    $html .= '  <div class="sd-op-login-card">';
    $html .= '    <div class="sd-op-eyebrow">SoloDrive</div>';
    $html .= '    <h1 class="sd-op-h1">Operator Login</h1>';
    $html .= '    <div class="sd-op-sub">Sign in to access your tenant workspace.</div>';
    $html .= '    <div class="sd-op-login-form">';

    ob_start();
    wp_login_form([
      'echo'           => true,
      'remember'       => true,
      'redirect'       => home_url($redirect_path),
      'label_username' => 'Email or Username',
      'label_password' => 'Password',
      'label_log_in'   => 'Sign In',
    ]);
    $html .= ob_get_clean();

    $html .= '    </div>';
    $html .= '  </div>';
    $html .= '</div>';

    self::render_shell('Operator Login', $html);
  }

  private static function render_shell(string $title, string $body_html) : void {
    echo '<!doctype html><html><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . esc_html($title) . '</title>';
    wp_head();
    echo '</head><body>';
    echo $body_html;
    wp_footer();
    echo '</body></html>';
  }

  private static function current_user_tenant_id() : int {
    if (class_exists('SD_TenantAccess') && method_exists('SD_TenantAccess', 'current_user_tenant_id')) {
      return (int) SD_TenantAccess::current_user_tenant_id();
    }
    return is_user_logged_in() ? (int) get_user_meta(get_current_user_id(), 'sd_tenant_id', true) : 0;
  }

  private static function current_user_can_operator_surface() : bool {
    if (current_user_can('manage_options')) return true;

    if (class_exists('SD_Module_RolesCaps')) {
      return current_user_can(SD_Module_RolesCaps::CAP_MANAGE_TENANT)
        || current_user_can(SD_Module_RolesCaps::CAP_DISPATCH)
        || current_user_can(SD_Module_RolesCaps::CAP_DRIVER);
    }

    return is_user_logged_in();
  }

  private static function request_path() : string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (!is_string($uri) || $uri === '') return '';
    $path = wp_parse_url($uri, PHP_URL_PATH);
    return is_string($path) ? $path : '';
  }

  private static function places_bind_js() : string {
    $country = (string) apply_filters('sd_intake_default_country', 'us', 0);

    return '<script>
    (function(){
      function wire(){
        if (!window.SD_Places || !window.SD_Places.bind) return false;

        SD_Places.bind({
          root: document,
          input: "#sd_base_location_label",
          placeId: "#sd_base_location_place_id",
          lat: "#sd_base_location_lat",
          lng: "#sd_base_location_lng",
          country: ' . wp_json_encode($country) . '
        });

        return true;
      }

      if (wire()) return;

      var tries = 0;
      var t = setInterval(function(){
        tries++;
        if (wire() || tries > 60) clearInterval(t);
      }, 250);
    })();
    </script>';
  }

  private static function styles() : string {
    return <<<HTML
<style>
  :root{
    --sd-bg:#f6f7fb;
    --sd-card:#ffffff;
    --sd-text:#0f172a;
    --sd-sub:#475569;
    --sd-line:#e2e8f0;
    --sd-accent:#111827;
    --sd-ok:#166534;
    --sd-ok-bg:#f0fdf4;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--sd-bg);color:var(--sd-text);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
  .sd-op-wrap{max-width:1080px;margin:0 auto;padding:20px}
  .sd-op-wrap--narrow{max-width:760px}
  .sd-op-topbar{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}
  .sd-op-eyebrow{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--sd-sub)}
  .sd-op-h1{margin:4px 0 6px;font-size:28px;line-height:1.05}
  .sd-op-sub{color:var(--sd-sub);font-size:14px}
  .sd-op-card{background:var(--sd-card);border:1px solid var(--sd-line);border-radius:18px;padding:16px}
  .sd-op-card-head{margin-bottom:12px}
  .sd-op-card-head h2{margin:0 0 4px;font-size:20px}
  .sd-op-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .sd-op-btn{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:42px;padding:0 14px;border-radius:999px;border:1px solid var(--sd-line);
    background:#fff;color:var(--sd-text);text-decoration:none;font-weight:700
  }
  .sd-op-btn-primary{background:var(--sd-accent);border-color:var(--sd-accent);color:#fff}
  .sd-op-field{margin-bottom:16px}
  .sd-op-field label{display:block;font-size:14px;font-weight:700;margin-bottom:6px}
  .sd-op-field input[type="text"],
  .sd-op-field input[type="number"]{
    width:100%;min-height:44px;padding:10px 12px;border-radius:12px;border:1px solid var(--sd-line);background:#fff
  }
  .sd-op-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .sd-op-help{color:var(--sd-sub);font-size:13px;margin-top:6px}
  .sd-op-notice{
    margin-bottom:12px;padding:12px 14px;border-radius:14px;
    background:var(--sd-ok-bg);border:1px solid #bbf7d0;color:var(--sd-ok);font-weight:700
  }
  .sd-op-login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  .sd-op-login-card{width:min(100%,420px);background:#fff;border:1px solid var(--sd-line);border-radius:22px;padding:24px}
  .sd-op-login-form form{display:flex;flex-direction:column;gap:12px;margin-top:16px}
  .sd-op-login-form label{display:block;font-size:14px;font-weight:700;margin-bottom:6px}
  .sd-op-login-form input[type="text"],
  .sd-op-login-form input[type="password"]{
    width:100%;min-height:44px;padding:10px 12px;border-radius:12px;border:1px solid var(--sd-line)
  }
  .sd-op-login-form input[type="submit"]{
    min-height:44px;border-radius:999px;border:0;background:var(--sd-accent);color:#fff;font-weight:800;padding:0 16px
  }
  .pac-container{z-index:999999!important;}
  @media (max-width: 720px){
    .sd-op-topbar{flex-direction:column}
    .sd-op-grid-2{grid-template-columns:1fr}
    .sd-op-h1{font-size:24px}
  }
</style>
HTML;
  }
}

SD_Module_OperatorBaseLocation::register();