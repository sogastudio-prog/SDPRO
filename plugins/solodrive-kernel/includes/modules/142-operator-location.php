<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorLocation (v0.3)
 *
 * Purpose:
 * - Reusable operator location/status module for tenant users
 * - Stores operator live location on user meta
 * - Mirrors live last-known location onto the tenant record
 * - Owns the online/offline toggle used by /operator/ and /operator/trips/
 */

if (class_exists('SD_Module_OperatorLocation', false)) { return; }

final class SD_Module_OperatorLocation {

  public const U_STATUS        = 'sd_operator_status';
  public const U_STATUS_TS     = 'sd_operator_status_updated_at';
  public const U_LAST_LAT      = 'sd_operator_last_lat';
  public const U_LAST_LNG      = 'sd_operator_last_lng';
  public const U_LAST_TS       = 'sd_operator_last_ts';
  public const U_LAST_ACCURACY = 'sd_operator_last_accuracy_m';

  public const LIVE_FRESH_SECONDS = 120;
  public const PING_THROTTLE_MS   = 5000;

  public static function register() : void {
    add_action('wp_ajax_sd_operator_ping', [__CLASS__, 'ajax_operator_ping']);
    add_action('wp_ajax_sd_operator_toggle_status', [__CLASS__, 'ajax_toggle_status']);
  }

  public static function get_context(int $user_id = 0) : array {
    $user_id = $user_id > 0 ? $user_id : (is_user_logged_in() ? get_current_user_id() : 0);

    if ($user_id <= 0) {
      return [
        'user_id'               => 0,
        'status'                => 'offline',
        'status_label'          => 'OFFLINE',
        'last_lat'              => 0.0,
        'last_lng'              => 0.0,
        'last_ts'               => 0,
        'last_accuracy_m'       => 0.0,
        'live_location_fresh'   => false,
        'live_location_label'   => 'missing',
        'base_location_label'   => 'missing',
        'tenant_id'             => 0,
        'storefront_state'      => 'closed',
        'storefront_state_label'=> 'closed',
      ];
    }

    $status = strtolower((string) get_user_meta($user_id, self::U_STATUS, true));
    $lat    = (float) get_user_meta($user_id, self::U_LAST_LAT, true);
    $lng    = (float) get_user_meta($user_id, self::U_LAST_LNG, true);
    $ts     = (int) get_user_meta($user_id, self::U_LAST_TS, true);
    $acc    = (float) get_user_meta($user_id, self::U_LAST_ACCURACY, true);

    if ($status !== 'online' && $status !== 'offline') {
      $status = 'offline';
    }

    $has_coords = (abs($lat) > 0.0001 && abs($lng) > 0.0001);
    $fresh      = ($ts > 0 && (time() - $ts) <= self::LIVE_FRESH_SECONDS);
    $label      = $fresh ? 'fresh' : ($ts > 0 ? 'stale' : 'missing');

    $tenant_id = self::current_user_tenant_id($user_id);

    $base_label       = 'missing';
    $storefront_state = 'closed';

    if ($tenant_id > 0) {
      $base_lat   = (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LAT, true);
      $base_lng   = (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LNG, true);
      $base_title = (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LABEL, true);

      if ($base_lat !== '' && $base_lng !== '') {
        $base_label = ($base_title !== '') ? $base_title : 'set';
      }

      $storefront_state = strtolower((string) get_post_meta($tenant_id, SD_Meta::STOREFRONT_STATE, true));
      if (!in_array($storefront_state, ['open', 'busy', 'closed', 'default'], true)) {
        $storefront_state = 'closed';
      }
    }

    return [
      'user_id'                => $user_id,
      'status'                 => $status,
      'status_label'           => strtoupper($status),
      'last_lat'               => $lat,
      'last_lng'               => $lng,
      'last_ts'                => $ts,
      'last_accuracy_m'        => $acc,
      'live_location_fresh'    => ($has_coords && $fresh),
      'live_location_label'    => $label,
      'base_location_label'    => $base_label,
      'tenant_id'              => $tenant_id,
      'storefront_state'       => $storefront_state,
      'storefront_state_label' => $storefront_state,
    ];
  }

  public static function render_status_toggle_button(array $args = []) : string {
    if (!is_user_logged_in()) return '';

    $ctx          = self::get_context(get_current_user_id());
    $current      = (string) ($ctx['status'] ?? 'offline');
    $label        = strtoupper($current === 'online' ? 'ONLINE' : 'OFFLINE');
    $next         = ($current === 'online') ? 'offline' : 'online';
    $nonce        = wp_create_nonce('sd_operator_location');
    $btn_id       = isset($args['id']) && $args['id'] !== ''
      ? sanitize_html_class((string) $args['id'])
      : ('sd-operator-status-btn-' . wp_generate_password(6, false, false));
    $status_class = 'sd-op-pill';
    if ($current === 'online') {
      $status_class .= ' is-online';
    }

    ob_start();
    ?>
    <button
      type="button"
      id="<?php echo esc_attr($btn_id); ?>"
      class="<?php echo esc_attr($status_class); ?>"
      data-current="<?php echo esc_attr($current); ?>"
      data-next="<?php echo esc_attr($next); ?>"
      data-nonce="<?php echo esc_attr($nonce); ?>"
    ><?php echo esc_html($label); ?></button>

    <script>
    (function(){
      var btn = document.getElementById(<?php echo wp_json_encode($btn_id); ?>);
      if (!btn) return;

      var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;

      function setVisual(status){
        status = (status === 'online') ? 'online' : 'offline';
        btn.setAttribute('data-current', status);
        btn.setAttribute('data-next', status === 'online' ? 'offline' : 'online');
        btn.textContent = status === 'online' ? 'ONLINE' : 'OFFLINE';

        if (status === 'online') {
          btn.classList.add('is-online');
        } else {
          btn.classList.remove('is-online');
        }
      }

      btn.addEventListener('click', function(){
        var next  = btn.getAttribute('data-next') || 'offline';
        var nonce = btn.getAttribute('data-nonce') || '';
        var oldText = btn.textContent;

        btn.disabled = true;
        btn.textContent = '...';

        var fd = new FormData();
        fd.append('action', 'sd_operator_toggle_status');
        fd.append('nonce', nonce);
        fd.append('next', next);

        fetch(ajaxUrl, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        })
        .then(function(r){ return r.json(); })
        .then(function(res){
          btn.disabled = false;

          if (!res || !res.success) {
            var msg = (res && res.data && res.data.message) ? res.data.message : 'Status update failed';
            btn.textContent = oldText;
            try { alert(msg); } catch (e) {}
            return;
          }

          var newStatus = (res.data && res.data.status) ? String(res.data.status) : next;
          setVisual(newStatus);

          window.dispatchEvent(new CustomEvent("sd:operator-status-changed", {
            detail: { status: newStatus, response: res.data || {} }
          }));

          if (newStatus === "online") {
            window.dispatchEvent(new CustomEvent("sd:operator-online", {
              detail: res.data || {}
            }));
          }

          window.setTimeout(function(){
            window.location.reload();
          }, 600);
        })
        .catch(function(){
          btn.disabled = false;
          btn.textContent = oldText;
          try { alert('Status update failed'); } catch (e) {}
        });
      });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_update_location_button(array $args = []) : string {
    if (!is_user_logged_in()) return '';

    $label   = isset($args['label']) ? (string) $args['label'] : 'Update location';
    $btn_id  = isset($args['id']) && $args['id'] !== ''
      ? sanitize_html_class((string) $args['id'])
      : ('sd-operator-location-btn-' . wp_generate_password(6, false, false));
    $text_id = $btn_id . '-label';
    $nonce   = wp_create_nonce('sd_operator_location');

    ob_start();
    ?>
    <button
      type="button"
      class="sd-op-btn"
      id="<?php echo esc_attr($btn_id); ?>"
      data-nonce="<?php echo esc_attr($nonce); ?>"
      data-label-id="<?php echo esc_attr($text_id); ?>"
      data-default-label="<?php echo esc_attr($label); ?>"
    >
      <span id="<?php echo esc_attr($text_id); ?>"><?php echo esc_html($label); ?></span>
    </button>

    <script>
    (function(){
      var btn = document.getElementById(<?php echo wp_json_encode($btn_id); ?>);
      if (!btn) return;

      var labelEl = document.getElementById(btn.getAttribute('data-label-id'));
      var defaultLabel = btn.getAttribute('data-default-label') || 'Update location';
      var nonce = btn.getAttribute('data-nonce') || '';
      var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
      var throttleMs = <?php echo (int) self::PING_THROTTLE_MS; ?>;
      var lastPingAt = 0;
      var watchId = null;
      var stopTimer = null;

      function setLabel(txt) {
        if (labelEl) labelEl.textContent = String(txt || defaultLabel);
      }

      function resetLabelLater(ms) {
        if (stopTimer) {
          clearTimeout(stopTimer);
          stopTimer = null;
        }
        stopTimer = window.setTimeout(function(){
          setLabel(defaultLabel);
        }, ms);
      }

      function stopWatch() {
        if (watchId !== null && navigator.geolocation) {
          try { navigator.geolocation.clearWatch(watchId); } catch (e) {}
        }
        watchId = null;
      }

      function postPing(lat, lng, acc) {
        var now = Date.now();
        if ((now - lastPingAt) < throttleMs) return;
        lastPingAt = now;

        var fd = new FormData();
        fd.append('action', 'sd_operator_ping');
        fd.append('nonce', nonce);
        fd.append('lat', String(lat));
        fd.append('lng', String(lng));
        fd.append('acc', String(acc || ''));

        fetch(ajaxUrl, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        })
        .then(function(r){ return r.json(); })
        .then(function(res){
          if (res && res.success) {
            setLabel('Location updated');
            window.dispatchEvent(new CustomEvent("sd:operator-location-updated", {
              detail: res.data || {}
            }));
            window.location.reload();
          } else {
            setLabel('Location failed');
            resetLabelLater(2500);
          }
        })
        .catch(function(){
          setLabel('Location failed');
          resetLabelLater(2500);
        });
      }

      function beginWatch() {
        if (!navigator.geolocation) {
          setLabel('GPS unavailable');
          resetLabelLater(2500);
          return;
        }

        setLabel('Waiting for GPS...');
        stopWatch();

        watchId = navigator.geolocation.watchPosition(
          function(pos){
            var c = pos && pos.coords ? pos.coords : null;
            if (!c) return;

            setLabel('Updating...');
            postPing(c.latitude, c.longitude, c.accuracy);

            window.setTimeout(function(){
              stopWatch();
            }, 1200);
          },
          function(err){
            if (err && err.code === 1) {
              setLabel('Location denied');
            } else if (err && err.code === 3) {
              setLabel('Location timeout');
            } else {
              setLabel('Location error');
            }

            resetLabelLater(2500);
            stopWatch();
          },
          {
            enableHighAccuracy: true,
            maximumAge: 10000,
            timeout: 15000
          }
        );
      }

      btn.addEventListener('click', beginWatch);
    })();
    </script>
    <?php
    return (string) ob_get_clean();
  }

  public static function ajax_operator_ping() : void {
    if (!is_user_logged_in()) {
      wp_send_json_error(['message' => 'Not logged in'], 401);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field((string) wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'sd_operator_location')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    $user_id = (int) get_current_user_id();
    if ($user_id <= 0) {
      wp_send_json_error(['message' => 'Bad user'], 400);
    }

    $lat = isset($_POST['lat']) ? (float) wp_unslash($_POST['lat']) : 0.0;
    $lng = isset($_POST['lng']) ? (float) wp_unslash($_POST['lng']) : 0.0;
    $acc = isset($_POST['acc']) ? (float) wp_unslash($_POST['acc']) : 0.0;

    if (abs($lat) < 0.0001 || abs($lng) < 0.0001) {
      wp_send_json_error(['message' => 'Bad coordinates'], 400);
    }

    update_user_meta($user_id, self::U_LAST_LAT, $lat);
    update_user_meta($user_id, self::U_LAST_LNG, $lng);
    update_user_meta($user_id, self::U_LAST_TS, time());
    if ($acc > 0) {
      update_user_meta($user_id, self::U_LAST_ACCURACY, $acc);
    }
    update_user_meta($user_id, self::U_STATUS_TS, current_time('mysql'));

    $tenant_id = self::current_user_tenant_id($user_id);
    if ($tenant_id > 0) {
      update_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LAT, $lat);
      update_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LNG, $lng);
      update_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_TS, time());

      if ($acc > 0) {
        update_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_ACCURACY_M, $acc);
      }
    }

    wp_send_json_success([
      'user_id'   => $user_id,
      'tenant_id' => $tenant_id,
      'ts'        => (int) get_user_meta($user_id, self::U_LAST_TS, true),
    ]);
  }

  public static function ajax_toggle_status() : void {
    if (!is_user_logged_in()) {
      wp_send_json_error(['message' => 'Not logged in'], 401);
    }

    $nonce = isset($_POST['nonce']) ? sanitize_text_field((string) wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'sd_operator_location')) {
      wp_send_json_error(['message' => 'Bad nonce'], 403);
    }

    $user_id = (int) get_current_user_id();
    $next    = isset($_POST['next']) ? sanitize_text_field((string) wp_unslash($_POST['next'])) : '';
    $next    = strtolower($next);

    if ($next !== 'online' && $next !== 'offline') {
      wp_send_json_error(['message' => 'Bad state'], 400);
    }

    $tenant_id = self::current_user_tenant_id($user_id);
    if ($tenant_id <= 0) {
      wp_send_json_error(['message' => 'Tenant not resolved'], 409);
    }

    if ($next === 'online') {
      $ctx = self::get_context($user_id);
      if (empty($ctx['live_location_fresh'])) {
        wp_send_json_error([
          'message' => 'Fresh location required before going online',
          'reason'  => 'location_required',
        ], 409);
      }
    }

    update_user_meta($user_id, self::U_STATUS, $next);
    update_user_meta($user_id, self::U_STATUS_TS, current_time('mysql'));

    $storefront_state = ($next === 'online') ? 'open' : 'closed';
    update_post_meta($tenant_id, SD_Meta::STOREFRONT_STATE, $storefront_state);

    wp_send_json_success([
      'user_id'          => $user_id,
      'tenant_id'        => $tenant_id,
      'status'           => $next,
      'storefront_state' => $storefront_state,
    ]);
  }

  private static function current_user_tenant_id(int $user_id) : int {
    $tenant_id = 0;

    if ($user_id === get_current_user_id() && class_exists('SD_TenantAccess') && method_exists('SD_TenantAccess', 'current_user_tenant_id')) {
      $tenant_id = (int) SD_TenantAccess::current_user_tenant_id();
    }

    if ($tenant_id <= 0) {
      $tenant_id = (int) get_user_meta($user_id, SD_Meta::TENANT_ID, true);
    }

    return $tenant_id > 0 ? $tenant_id : 0;
  }
}