<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_StorefrontIntake
 *
 * Purpose:
 * - Render canonical public storefront intake form
 * - Submit via AJAX/JSON
 * - Create lead through SD_Module_LeadService
 * - Redirect successful submissions to /trip/<token>/
 *
 * Canon:
 * - Lead is the engagement root
 * - This form creates only a lead
 * - No quote / attempt / ride creation here
 * - Public submit returns explicit redirect_url from server
 */

if (class_exists('SD_Module_StorefrontIntake', false)) { return; }

final class SD_Module_StorefrontIntake {

  private const ACTION = 'sd_storefront_submit_lead';

  public static function register() : void {
    add_action('wp_ajax_nopriv_' . self::ACTION, [__CLASS__, 'handle_submit']);
    add_action('wp_ajax_' . self::ACTION,        [__CLASS__, 'handle_submit']);
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

    $request_mode = SD_Meta::LEAD_MODE_ASAP;
    $ajax_url = admin_url('admin-ajax.php');

    ob_start();
    ?>
    <div class="sd-storefront-intake">
      <div id="sd-storefront-intake-error" class="sd-card sd-card--error tenant-storefront-card" style="margin-bottom:16px;display:none;">
        <strong>We could not create your request.</strong>
        <div id="sd-storefront-intake-error-message"></div>
      </div>

      <form id="sd-storefront-intake-form" class="sd-storefront-intake__form" novalidate>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
        <input type="hidden" name="sd_tenant_id" value="<?php echo esc_attr((string) $tenant_id); ?>">

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
            value=""
            autocomplete="street-address"
            placeholder="Enter pickup location"
            required
            style="width:100%;margin-top:6px;"
          >

          <input type="hidden" id="sd_pickup_place_id" name="pickup_place_id" value="">
          <input type="hidden" id="sd_pickup_lat" name="pickup_lat" value="">
          <input type="hidden" id="sd_pickup_lng" name="pickup_lng" value="">

          <div style="height:12px;"></div>

          <label for="sd_dropoff_address"><strong>Dropoff</strong></label>
          <input
            id="sd_dropoff_address"
            name="dropoff_address"
            type="text"
            value=""
            autocomplete="street-address"
            placeholder="Enter dropoff location"
            required
            style="width:100%;margin-top:6px;"
          >

          <input type="hidden" id="sd_dropoff_place_id" name="dropoff_place_id" value="">
          <input type="hidden" id="sd_dropoff_lat" name="dropoff_lat" value="">
          <input type="hidden" id="sd_dropoff_lng" name="dropoff_lng" value="">
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
                  value=""
                  style="width:100%;margin-top:6px;"
                >
              </div>

              <div style="flex:1 1 180px;">
                <label for="sd_reserve_time"><strong>Time</strong></label>
                <input
                  id="sd_reserve_time"
                  name="reserve_time"
                  type="time"
                  value=""
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
                value=""
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
                value=""
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
            ></textarea>
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
                value=""
                placeholder="Manual fallback for testing"
                style="width:100%;margin-top:6px;"
              >
            </div>

            <div style="flex:1 1 280px;">
              <label for="sd_dropoff_place_id_manual"><strong>Dropoff Place ID</strong></label>
              <input
                id="sd_dropoff_place_id_manual"
                type="text"
                value=""
                placeholder="Manual fallback for testing"
                style="width:100%;margin-top:6px;"
              >
            </div>
          </div>
        </details>

        <div class="sd-card tenant-storefront-card">
          <button type="submit" id="sd-storefront-submit-btn" class="sd-btn sd-btn--primary">Continue</button>
        </div>
      </form>
    </div>

    <script>
    (function() {
      var form = document.getElementById('sd-storefront-intake-form');
      if (!form) return;

      var ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
      var errorWrap = document.getElementById('sd-storefront-intake-error');
      var errorMsg = document.getElementById('sd-storefront-intake-error-message');
      var submitBtn = document.getElementById('sd-storefront-submit-btn');

      var asap = form.querySelector('input[name="sd_request_mode"][value="<?php echo esc_js(SD_Meta::LEAD_MODE_ASAP); ?>"]');
      var reserve = form.querySelector('input[name="sd_request_mode"][value="<?php echo esc_js(SD_Meta::LEAD_MODE_RESERVE); ?>"]');
      var reserveWrap = document.getElementById('sd-reserve-fields');
      var reserveDate = document.getElementById('sd_reserve_date');
      var reserveTime = document.getElementById('sd_reserve_time');

      var pickupHidden = document.getElementById('sd_pickup_place_id');
      var dropoffHidden = document.getElementById('sd_dropoff_place_id');
      var pickupManual = document.getElementById('sd_pickup_place_id_manual');
      var dropoffManual = document.getElementById('sd_dropoff_place_id_manual');

      function showError(message) {
        if (!errorWrap || !errorMsg) return;
        errorMsg.textContent = message || 'Could not create request.';
        errorWrap.style.display = '';
      }

      function clearError() {
        if (!errorWrap || !errorMsg) return;
        errorMsg.textContent = '';
        errorWrap.style.display = 'none';
      }

      function syncMode() {
        var isReserve = !!(reserve && reserve.checked);
        if (reserveWrap) reserveWrap.style.display = isReserve ? '' : 'none';
        if (reserveDate) reserveDate.required = isReserve;
        if (reserveTime) reserveTime.required = isReserve;
      }

      function syncManualPlaceIds() {
        if (pickupHidden && pickupManual && !pickupHidden.value.trim()) {
          pickupHidden.value = pickupManual.value.trim();
        }
        if (dropoffHidden && dropoffManual && !dropoffHidden.value.trim()) {
          dropoffHidden.value = dropoffManual.value.trim();
        }
      }

      async function handleSubmit(event) {
        event.preventDefault();
        clearError();
        syncManualPlaceIds();
        syncMode();

        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.textContent = 'Submitting...';
        }

        try {
          var formData = new FormData(form);

          var response = await fetch(ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
          });

          var json = await response.json();

          if (!json || !json.success || !json.data || !json.data.redirect_url) {
            var message = (json && json.data && json.data.error) ? json.data.error : 'Could not create request.';
            showError(message);
            return;
          }

          window.location.assign(json.data.redirect_url);
        } catch (err) {
          showError('Network or server error. Please try again.');
        } finally {
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Continue';
          }
        }
      }

      if (asap) asap.addEventListener('change', syncMode);
      if (reserve) reserve.addEventListener('change', syncMode);
      form.addEventListener('submit', handleSubmit);

      syncMode();
    })();
    </script>
    <?php
    return (string) ob_get_clean();
  }

  public static function handle_submit() : void {
    $tenant_id = isset($_POST['sd_tenant_id']) ? absint($_POST['sd_tenant_id']) : 0;
    if ($tenant_id <= 0) {
      wp_send_json_error([
        'error' => 'Missing tenant.',
      ], 400);
    }

    if (class_exists('SD_StorefrontGate', false)) {
      $gate = SD_StorefrontGate::evaluate($tenant_id);
      if (empty($gate['can_render_request_form'])) {
        $message = isset($gate['message']) ? (string) $gate['message'] : 'This storefront is not accepting requests right now.';
        wp_send_json_error([
          'error' => $message,
        ], 403);
      }
    }

    if (!class_exists('SD_Module_LeadService', false)) {
      wp_send_json_error([
        'error' => 'Lead service unavailable.',
      ], 500);
    }

    $result = SD_Module_LeadService::create_from_intake(wp_unslash($_POST), $tenant_id);

    if (empty($result['ok'])) {
      $message = !empty($result['error']) ? (string) $result['error'] : 'Could not create request.';
      wp_send_json_error([
        'error' => $message,
      ], 422);
    }

    $trip_url = isset($result['trip_url']) ? (string) $result['trip_url'] : '';
    if ($trip_url === '') {
      wp_send_json_error([
        'error' => 'Trip URL missing after lead creation.',
      ], 500);
    }

    wp_send_json_success([
      'lead_id'      => (int) $result['lead_id'],
      'token'        => (string) $result['token'],
      'redirect_url' => $trip_url,
    ]);
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