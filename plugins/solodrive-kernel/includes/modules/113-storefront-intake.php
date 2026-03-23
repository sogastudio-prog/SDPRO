<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_StorefrontIntake
 *
 * Purpose:
 * - Render the canonical public storefront intake form
 * - Submit to one canonical lead creation path
 * - Redirect successful submissions to /trip/<token>/
 *
 * Canon:
 * - Lead is the engagement root
 * - This form creates only a lead
 * - No quote / attempt / ride creation here
 *
 * Integration:
 * - Called from SD_Module_StorefrontEntry::render_request_workflow()
 * - Submits to admin-post.php action=sd_storefront_submit_lead
 */

if (class_exists('SD_Module_StorefrontIntake', false)) { return; }

final class SD_Module_StorefrontIntake {

  private const ACTION = 'sd_storefront_submit_lead';
  private const NONCE  = 'sd_storefront_submit_lead';

  public static function register() : void {
    add_action('admin_post_nopriv_' . self::ACTION, [__CLASS__, 'handle_submit']);
    add_action('admin_post_' . self::ACTION,        [__CLASS__, 'handle_submit']);
  }

  /**
   * Render storefront intake form.
   *
   * @param int   $tenant_id
   * @param array $gate
   */
  public static function render(int $tenant_id, array $gate = []) : string {
    $tenant_id = absint($tenant_id);
    if ($tenant_id <= 0) {
      return self::notice('Storefront unavailable.', 'Tenant could not be resolved.');
    }

    if (!empty($gate) && empty($gate['can_render_request_form'])) {
      $message = isset($gate['message']) ? (string) $gate['message'] : 'This storefront is not accepting requests right now.';
      return self::notice('Storefront unavailable.', $message);
    }

    $sticky = self::sticky_from_query();
    $error  = isset($_GET['sd_sf_error']) ? sanitize_text_field(wp_unslash((string) $_GET['sd_sf_error'])) : '';

    $request_mode = $sticky['sd_request_mode'] !== '' ? $sticky['sd_request_mode'] : SD_Meta::LEAD_MODE_ASAP;

    ob_start();
    ?>
    <div class="sd-storefront-intake">
      <?php if ($error !== '') : ?>
        <div class="sd-card sd-card--error tenant-storefront-card" style="margin-bottom:16px;">
          <strong>We could not create your request.</strong>
          <div><?php echo esc_html($error); ?></div>
        </div>
      <?php endif; ?>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="sd-storefront-intake__form">
        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
        <input type="hidden" name="sd_tenant_id" value="<?php echo esc_attr((string) $tenant_id); ?>">
        <?php wp_nonce_field(self::NONCE, '_sd_nonce'); ?>

        <div class="sd-card tenant-storefront-card" style="margin-bottom:16px;">
          <h2 class="sd-h2">Request a Ride</h2>
          <div class="sd-sub">Start with pickup, dropoff, timing, and your contact details.</div>
        </div>

        <div class="sd-card tenant-storefront-card" style="margin-bottom:16px;">
          <label for="sd_pickup_address"><strong>Pickup</strong></label>
          <input
            id="sd_pickup_address"
            name="pickup_address"
            type="text"
            value="<?php echo esc_attr($sticky['pickup_address']); ?>"
            autocomplete="street-address"
            placeholder="Enter pickup location"
            required
            style="width:100%;margin-top:6px;"
          >

          <input type="hidden" id="sd_pickup_place_id" name="pickup_place_id" value="<?php echo esc_attr($sticky['pickup_place_id']); ?>">
          <input type="hidden" id="sd_pickup_lat" name="pickup_lat" value="<?php echo esc_attr($sticky['pickup_lat']); ?>">
          <input type="hidden" id="sd_pickup_lng" name="pickup_lng" value="<?php echo esc_attr($sticky['pickup_lng']); ?>">

          <div style="height:12px;"></div>

          <label for="sd_dropoff_address"><strong>Dropoff</strong></label>
          <input
            id="sd_dropoff_address"
            name="dropoff_address"
            type="text"
            value="<?php echo esc_attr($sticky['dropoff_address']); ?>"
            autocomplete="street-address"
            placeholder="Enter dropoff location"
            required
            style="width:100%;margin-top:6px;"
          >

          <input type="hidden" id="sd_dropoff_place_id" name="dropoff_place_id" value="<?php echo esc_attr($sticky['dropoff_place_id']); ?>">
          <input type="hidden" id="sd_dropoff_lat" name="dropoff_lat" value="<?php echo esc_attr($sticky['dropoff_lat']); ?>">
          <input type="hidden" id="sd_dropoff_lng" name="dropoff_lng" value="<?php echo esc_attr($sticky['dropoff_lng']); ?>">
        </div>

        <div class="sd-card tenant-storefront-card" style="margin-bottom:16px;">
          <strong>When do you need pickup?</strong>

          <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:10px;">
            <label>
              <input type="radio" name="sd_request_mode" value="<?php echo esc_attr(SD_Meta::LEAD_MODE_ASAP); ?>" <?php checked($request_mode, SD_Meta::LEAD_MODE_ASAP); ?>>
              ASAP
            </label>

            <label>
              <input type="radio" name="sd_request_mode" value="<?php echo esc_attr(SD_Meta::LEAD_MODE_RESERVE); ?>" <?php checked($request_mode, SD_Meta::LEAD_MODE_RESERVE); ?>>
              Reserve
            </label>
          </div>

          <div id="sd-reserve-fields" style="<?php echo ($request_mode === SD_Meta::LEAD_MODE_RESERVE) ? '' : 'display:none;'; ?> margin-top:14px;">
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
              <div style="flex:1 1 180px;">
                <label for="sd_reserve_date"><strong>Date</strong></label>
                <input
                  id="sd_reserve_date"
                  name="reserve_date"
                  type="date"
                  value="<?php echo esc_attr($sticky['reserve_date']); ?>"
                  style="width:100%;margin-top:6px;"
                >
              </div>

              <div style="flex:1 1 180px;">
                <label for="sd_reserve_time"><strong>Time</strong></label>
                <input
                  id="sd_reserve_time"
                  name="reserve_time"
                  type="time"
                  value="<?php echo esc_attr($sticky['reserve_time']); ?>"
                  style="width:100%;margin-top:6px;"
                >
              </div>
            </div>
          </div>
        </div>

        <div class="sd-card tenant-storefront-card" style="margin-bottom:16px;">
          <div style="display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1 1 220px;">
              <label for="sd_customer_name"><strong>Name</strong></label>
              <input
                id="sd_customer_name"
                name="customer_name"
                type="text"
                value="<?php echo esc_attr($sticky['customer_name']); ?>"
                autocomplete="name"
                placeholder="Your name"
                required
                style="width:100%;margin-top:6px;"
              >
            </div>

            <div style="flex:1 1 220px;">
              <label for="sd_customer_phone"><strong>Phone</strong></label>
              <input
                id="sd_customer_phone"
                name="customer_phone"
                type="tel"
                value="<?php echo esc_attr($sticky['customer_phone']); ?>"
                autocomplete="tel"
                placeholder="Your phone number"
                required
                style="width:100%;margin-top:6px;"
              >
            </div>
          </div>

          <div style="margin-top:12px;">
            <label for="sd_reserve_notes"><strong>Notes</strong> <span style="opacity:.7;">(optional)</span></label>
            <textarea
              id="sd_reserve_notes"
              name="reserve_notes"
              rows="3"
              placeholder="Gate code, pickup instructions, or other notes"
              style="width:100%;margin-top:6px;"
            ><?php echo esc_textarea($sticky['reserve_notes']); ?></textarea>
          </div>
        </div>

        <details class="sd-card tenant-storefront-card" style="margin-bottom:16px;">
          <summary><strong>Developer fallback</strong> <span style="opacity:.7;">(manual Place IDs for testing)</span></summary>
          <div style="margin-top:12px;display:flex;gap:12px;flex-wrap:wrap;">
            <div style="flex:1 1 280px;">
              <label for="sd_pickup_place_id_manual"><strong>Pickup Place ID</strong></label>
              <input
                id="sd_pickup_place_id_manual"
                type="text"
                value="<?php echo esc_attr($sticky['pickup_place_id']); ?>"
                placeholder="Manual fallback for testing"
                style="width:100%;margin-top:6px;"
              >
            </div>

            <div style="flex:1 1 280px;">
              <label for="sd_dropoff_place_id_manual"><strong>Dropoff Place ID</strong></label>
              <input
                id="sd_dropoff_place_id_manual"
                type="text"
                value="<?php echo esc_attr($sticky['dropoff_place_id']); ?>"
                placeholder="Manual fallback for testing"
                style="width:100%;margin-top:6px;"
              >
            </div>
          </div>
        </details>

        <div class="sd-card tenant-storefront-card">
          <button type="submit" class="sd-btn sd-btn--primary">Continue</button>
        </div>
      </form>
    </div>

    <script>
    (function() {
      var form = document.currentScript ? document.currentScript.previousElementSibling : document.querySelector('.sd-storefront-intake__form');
      if (!form) return;

      var asap = form.querySelector('input[name="sd_request_mode"][value="<?php echo esc_js(SD_Meta::LEAD_MODE_ASAP); ?>"]');
      var reserve = form.querySelector('input[name="sd_request_mode"][value="<?php echo esc_js(SD_Meta::LEAD_MODE_RESERVE); ?>"]');
      var reserveWrap = document.getElementById('sd-reserve-fields');
      var reserveDate = document.getElementById('sd_reserve_date');
      var reserveTime = document.getElementById('sd_reserve_time');

      var pickupHidden = document.getElementById('sd_pickup_place_id');
      var dropoffHidden = document.getElementById('sd_dropoff_place_id');
      var pickupManual = document.getElementById('sd_pickup_place_id_manual');
      var dropoffManual = document.getElementById('sd_dropoff_place_id_manual');

      function syncMode() {
        var isReserve = !!(reserve && reserve.checked);
        if (reserveWrap) reserveWrap.style.display = isReserve ? '' : 'none';
        if (reserveDate) reserveDate.required = isReserve;
        if (reserveTime) reserveTime.required = isReserve;
      }

      if (asap) asap.addEventListener('change', syncMode);
      if (reserve) reserve.addEventListener('change', syncMode);
      syncMode();

      form.addEventListener('submit', function() {
        if (pickupHidden && pickupManual && !pickupHidden.value.trim()) {
          pickupHidden.value = pickupManual.value.trim();
        }
        if (dropoffHidden && dropoffManual && !dropoffHidden.value.trim()) {
          dropoffHidden.value = dropoffManual.value.trim();
        }
      });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
  }

  public static function handle_submit() : void {
    $nonce = isset($_POST['_sd_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['_sd_nonce'])) : '';
    if (!wp_verify_nonce($nonce, self::NONCE)) {
      self::redirect_back('Request verification failed.', $_POST);
    }

    $tenant_id = isset($_POST['sd_tenant_id']) ? absint($_POST['sd_tenant_id']) : 0;
    if ($tenant_id <= 0) {
      self::redirect_back('Missing tenant.', $_POST);
    }

    if (class_exists('SD_StorefrontGate', false)) {
      $gate = SD_StorefrontGate::evaluate($tenant_id);
      if (empty($gate['can_render_request_form'])) {
        $message = isset($gate['message']) ? (string) $gate['message'] : 'This storefront is not accepting requests right now.';
        self::redirect_back($message, $_POST);
      }
    }

    if (!class_exists('SD_Module_LeadService', false)) {
      self::redirect_back('Lead service unavailable.', $_POST);
    }

    $result = SD_Module_LeadService::create_from_intake(wp_unslash($_POST), $tenant_id);

    if (empty($result['ok'])) {
      $message = !empty($result['error']) ? (string) $result['error'] : 'Could not create request.';
      self::redirect_back($message, $_POST);
    }

    $trip_url = isset($result['trip_url']) ? (string) $result['trip_url'] : '';
    if ($trip_url === '') {
      self::redirect_back('Trip URL missing after lead creation.', $_POST);
    }

    wp_safe_redirect($trip_url);
    exit;
  }

  private static function redirect_back(string $message, array $payload) : void {
    $back = wp_get_referer();
    if (!$back) {
      $back = home_url('/');
    }

    $args = [
      'sd_sf_error'     => rawurlencode($message),
      'pickup_address'  => self::q($payload, 'pickup_address'),
      'dropoff_address' => self::q($payload, 'dropoff_address'),
      'pickup_place_id' => self::q($payload, 'pickup_place_id'),
      'dropoff_place_id'=> self::q($payload, 'dropoff_place_id'),
      'pickup_lat'      => self::q($payload, 'pickup_lat'),
      'pickup_lng'      => self::q($payload, 'pickup_lng'),
      'dropoff_lat'     => self::q($payload, 'dropoff_lat'),
      'dropoff_lng'     => self::q($payload, 'dropoff_lng'),
      'customer_name'   => self::q($payload, 'customer_name'),
      'customer_phone'  => self::q($payload, 'customer_phone'),
      'sd_request_mode' => self::q($payload, 'sd_request_mode', self::q($payload, 'request_mode', SD_Meta::LEAD_MODE_ASAP)),
      'reserve_date'    => self::q($payload, 'reserve_date'),
      'reserve_time'    => self::q($payload, 'reserve_time'),
      'reserve_notes'   => self::q($payload, 'reserve_notes', self::q($payload, 'customer_notes')),
    ];

    $back = add_query_arg($args, $back);
    wp_safe_redirect($back);
    exit;
  }

  private static function sticky_from_query() : array {
    return [
      'pickup_address'  => self::g('pickup_address'),
      'dropoff_address' => self::g('dropoff_address'),
      'pickup_place_id' => self::g('pickup_place_id'),
      'dropoff_place_id'=> self::g('dropoff_place_id'),
      'pickup_lat'      => self::g('pickup_lat'),
      'pickup_lng'      => self::g('pickup_lng'),
      'dropoff_lat'     => self::g('dropoff_lat'),
      'dropoff_lng'     => self::g('dropoff_lng'),
      'customer_name'   => self::g('customer_name'),
      'customer_phone'  => self::g('customer_phone'),
      'sd_request_mode' => self::g('sd_request_mode', SD_Meta::LEAD_MODE_ASAP),
      'reserve_date'    => self::g('reserve_date'),
      'reserve_time'    => self::g('reserve_time'),
      'reserve_notes'   => self::g('reserve_notes'),
    ];
  }

  private static function g(string $key, string $default = '') : string {
    if (!isset($_GET[$key])) return $default;
    return sanitize_text_field(wp_unslash((string) $_GET[$key]));
  }

  private static function q(array $payload, string $key, string $default = '') : string {
    if (!isset($payload[$key])) return $default;
    return sanitize_text_field((string) $payload[$key]);
  }

  private static function notice(string $headline, string $message) : string {
    ob_start();
    ?>
    <div class="sd-storefront-intake-notice">
      <div class="sd-card tenant-storefront-card">
        <h2 class="sd-h2"><?php echo esc_html($headline); ?></h2>
        <p class="sd-sub"><?php echo esc_html($message); ?></p>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }
}

SD_Module_StorefrontIntake::register();