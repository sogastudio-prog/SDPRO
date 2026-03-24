<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_ReservationSurface {

  public static function register() : void {
    add_shortcode('sd_reservation_surface', [__CLASS__, 'shortcode']);
    add_action('template_redirect', [__CLASS__, 'maybe_handle_post']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_assets']);
  }

  public static function shortcode($atts = []) : string {
    $atts = shortcode_atts([
      'tenant_id' => 0,
    ], $atts, 'sd_reservation_surface');

    $tenant_id = (int) $atts['tenant_id'];
    if ($tenant_id <= 0 && class_exists('SD_Module_TenantResolver')) {
      $tenant_id = (int) SD_Module_TenantResolver::current_tenant_id();
    }

    if ($tenant_id <= 0) {
      return '<div class="sd-card"><p>Missing tenant context.</p></div>';
    }

    $action = esc_url(self::post_url());
    $nonce  = wp_create_nonce('sd_reservation_submit');

    ob_start();
    ?>
    <div class="sd-card sd-reservation-card">
      <h3>Reserve a Ride</h3>
      <form method="post" action="<?php echo $action; ?>" class="sd-reservation-form">
        <input type="hidden" name="sd_action" value="reservation_submit">
        <input type="hidden" name="sd_tenant_id" value="<?php echo (int) $tenant_id; ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">

        <p>
          <label>Pickup<br>
            <input type="text" name="pickup_address" id="sd_res_pickup_address" required autocomplete="off">
          </label>
          <input type="hidden" name="pickup_place_id" id="sd_res_pickup_place_id">
        </p>

        <p>
          <label>Dropoff<br>
            <input type="text" name="dropoff_address" id="sd_res_dropoff_address" required autocomplete="off">
          </label>
          <input type="hidden" name="dropoff_place_id" id="sd_res_dropoff_place_id">
        </p>

        <p>
          <label>Pickup date<br>
            <input type="date" name="reserve_date" required>
          </label>
        </p>

        <p>
          <label>Pickup time<br>
            <input type="time" name="reserve_time" required>
          </label>
        </p>

        <p>
          <label>Phone<br>
            <input type="tel" name="customer_phone" required>
          </label>
        </p>

        <p>
          <label>Name (optional)<br>
            <input type="text" name="customer_name">
          </label>
        </p>

        <p>
          <label>Notes (optional)<br>
            <textarea name="reserve_notes" rows="4"></textarea>
          </label>
        </p>

        <p>
          <button type="submit">Reserve ride</button>
        </p>
      </form>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function maybe_handle_post() : void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!isset($_POST['sd_action']) || (string) $_POST['sd_action'] !== 'reservation_submit') return;

    check_admin_referer('sd_reservation_submit');

    $payload = [
      'tenant_id'        => absint($_POST['sd_tenant_id'] ?? 0),
      'pickup_address'   => sanitize_text_field(wp_unslash($_POST['pickup_address'] ?? '')),
      'pickup_place_id'  => sanitize_text_field(wp_unslash($_POST['pickup_place_id'] ?? '')),
      'dropoff_address'  => sanitize_text_field(wp_unslash($_POST['dropoff_address'] ?? '')),
      'dropoff_place_id' => sanitize_text_field(wp_unslash($_POST['dropoff_place_id'] ?? '')),
      'reserve_date'     => sanitize_text_field(wp_unslash($_POST['reserve_date'] ?? '')),
      'reserve_time'     => sanitize_text_field(wp_unslash($_POST['reserve_time'] ?? '')),
      'customer_phone'   => sanitize_text_field(wp_unslash($_POST['customer_phone'] ?? '')),
      'customer_name'    => sanitize_text_field(wp_unslash($_POST['customer_name'] ?? '')),
      'reserve_notes'    => sanitize_textarea_field(wp_unslash($_POST['reserve_notes'] ?? '')),
    ];

    $result = SD_ReservationRideCreator::create($payload);

    if (empty($result['ok'])) {
      $target = add_query_arg('reserve', 'error', self::current_url());
      wp_safe_redirect($target);
      exit;
    }

    $confirm_url = home_url('/reservation/' . rawurlencode((string) $result['token']) . '/');
    wp_safe_redirect($confirm_url);
    exit;
  }

  public static function maybe_enqueue_assets() : void {
    if (is_admin()) return;

    global $post;
    if (!$post || !($post instanceof WP_Post)) return;

    if (!has_shortcode((string) $post->post_content, 'sd_reservation_surface')) return;

    $file = __FILE__;
    $js_abs = dirname(dirname(dirname(__DIR__))) . '/assets/js/reservation-surface.js';
    $js_url = plugins_url('assets/js/reservation-surface.js', dirname(dirname(dirname(__DIR__))) . '/solodrive-kernel.php');
    $ver    = file_exists($js_abs) ? (string) filemtime($js_abs) : '1.0.0';

    wp_enqueue_script('sd-reservation-surface', $js_url, [], $ver, true);

    $tenant_id = class_exists('SD_Module_TenantResolver') ? (int) SD_Module_TenantResolver::current_tenant_id() : 0;
    wp_localize_script('sd-reservation-surface', 'SD_RESERVATION_SURFACE', [
      'tenantId' => $tenant_id,
    ]);
  }

  private static function post_url() : string {
    return self::current_url();
  }

  private static function current_url() : string {
    $scheme = is_ssl() ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $uri    = $_SERVER['REQUEST_URI'] ?? '/';
    return esc_url_raw($scheme . '://' . $host . $uri);
  }
}

