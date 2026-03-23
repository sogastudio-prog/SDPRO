<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_RouteInputsUI (v1)
 *
 * Purpose:
 * - Simple reusable UI to enter origin/destination addresses and compute meters/seconds.
 * - Used for driver/ops tools and rapid validation.
 *
 * Shortcode:
 *   [sd_route_inputs]
 *
 * Notes:
 * - No pricing. Displays logistics only.
 * - Assumes SD_Module_Places exists and provides SD_Places.bind().
 */

final class SD_Module_RouteInputsUI {

  public static function register() : void {
    add_shortcode('sd_route_inputs', [__CLASS__, 'shortcode']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue']);
  }

  public static function shortcode($atts = []) : string {

    // Optional: restrict to logged-in users if you want.
    // if (!is_user_logged_in()) return '<div class="sd-card">Login required.</div>';

    $id = 'sd-route-inputs-' . wp_generate_password(8, false, false);

    // Tenant base bias (optional)
    $tenant_id = class_exists('SD_Module_TenantResolver') ? (int) SD_Module_TenantResolver::current_tenant_id() : 0;

    $base = ['lat' => null, 'lng' => null, 'radius_m' => null];
    if ($tenant_id > 0 && class_exists('SD_Meta')) {
      $lat = trim((string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LAT, true));
      $lng = trim((string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LNG, true));
      $rad = (int) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_RADIUS_M, true);
      if ($rad <= 0) $rad = 40000;

      if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)) {
        $base = ['lat' => (float)$lat, 'lng' => (float)$lng, 'radius_m' => (int)$rad];
      }
    }

    ob_start();
    ?>
    <div class="sd-surface sd-surface--wide sd-route-inputs tenant-route-inputs" id="<?php echo esc_attr($id); ?>">
      <div class="sd-card tenant-route-card">
        <h2 class="sd-h2">Route Compute</h2>
        <div class="sd-sub">Enter two addresses and compute distance + ETA primitives (no pricing).</div>

        <div class="sd-field" style="margin-top:12px;">
          <label class="sd-label">Origin</label>
          <input class="sd-input" type="text" name="origin_address" placeholder="Start typing origin…" autocomplete="off" />
          <input type="hidden" name="origin_place_id" value="" />
        </div>

        <div class="sd-field" style="margin-top:12px;">
          <label class="sd-label">Destination</label>
          <input class="sd-input" type="text" name="dest_address" placeholder="Start typing destination…" autocomplete="off" />
          <input type="hidden" name="dest_place_id" value="" />
        </div>

        <div style="margin-top:14px; display:flex; gap:10px; align-items:center;">
          <button type="button" class="sd-btn sd-btn--primary" data-sd-route-go="1">Compute</button>
          <div class="sd-sub" data-sd-route-out="1"></div>
        </div>
      </div>
    </div>

    <script>
    (function(){
      var root = document.getElementById(<?php echo wp_json_encode($id); ?>);
      if (!root) return;

      // Bind places
      if (window.SD_Places && SD_Places.bind) {
        SD_Places.bind({
          root: root,
          input: 'input[name="origin_address"]',
          placeId: 'input[name="origin_place_id"]',
          country: <?php echo wp_json_encode(apply_filters('sd_intake_default_country','us',$tenant_id)); ?>,
          base: <?php echo wp_json_encode($base); ?>
        });

        SD_Places.bind({
          root: root,
          input: 'input[name="dest_address"]',
          placeId: 'input[name="dest_place_id"]',
          country: <?php echo wp_json_encode(apply_filters('sd_intake_default_country','us',$tenant_id)); ?>,
          base: <?php echo wp_json_encode($base); ?>
        });
      }

      var btn = root.querySelector('[data-sd-route-go="1"]');
      var out = root.querySelector('[data-sd-route-out="1"]');

      function fmt(seconds){
        seconds = Math.max(0, seconds|0);
        var m = Math.round(seconds/60);
        if (m < 60) return m + " min";
        var h = Math.floor(m/60);
        var mm = m % 60;
        return h + "h " + mm + "m";
      }

      function miles(meters){
        return (meters / 1609.344);
      }

      if (btn) {
        btn.addEventListener('click', function(){

          var o = root.querySelector('input[name="origin_place_id"]');
          var d = root.querySelector('input[name="dest_place_id"]');
          var op = o ? (o.value || '') : '';
          var dp = d ? (d.value || '') : '';

          if (!op || !dp) {
            if (out) out.textContent = "Please choose both locations from the suggestions list.";
            return;
          }

          if (!window.SD_ROUTE_COMPUTE) {
            if (out) out.textContent = "Route compute not configured.";
            return;
          }

          if (out) out.textContent = "Computing…";

          var fd = new FormData();
          fd.append('action', SD_ROUTE_COMPUTE.action);
          fd.append('nonce', SD_ROUTE_COMPUTE.nonce);
          fd.append('tenant_id', String(SD_ROUTE_COMPUTE.tenantId || '0'));
          fd.append('origin_place_id', op);
          fd.append('dest_place_id', dp);

          fetch(SD_ROUTE_COMPUTE.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
          }).then(function(r){ return r.json(); })
            .then(function(j){
              if (!j || !j.success) {
                if (out) out.textContent = (j && j.data && j.data.message) ? j.data.message : "Compute failed.";
                return;
              }
              var meters = j.data.meters|0;
              var seconds = j.data.seconds|0;
              var mi = miles(meters);
              if (out) out.textContent = mi.toFixed(1) + " mi • " + fmt(seconds);
            })
            .catch(function(){
              if (out) out.textContent = "Compute failed.";
            });
        });
      }
    })();
    </script>
    <?php
    return (string) ob_get_clean();
  }

  public static function maybe_enqueue() : void {

    // Ensure Places JS is available on pages where this shortcode is used.
    // If your 006-places.php registers/enqueues globally, this is redundant but safe.
    if (class_exists('SD_Module_Places')) {
      SD_Module_Places::enqueue();
    }

    // Route compute config for frontend calls
    if (!class_exists('SD_Module_RouteCompute')) return;

    $tenant_id = class_exists('SD_Module_TenantResolver') ? (int) SD_Module_TenantResolver::current_tenant_id() : 0;

    $h = 'sd-route-inputs-ui';
    if (!wp_script_is($h, 'registered')) {
      wp_register_script($h, '', [], '1.0', true);
    }
    wp_enqueue_script($h);

    wp_add_inline_script($h, 'window.SD_ROUTE_COMPUTE=' . wp_json_encode([
      'ajaxUrl'  => admin_url('admin-ajax.php'),
      'action'   => SD_Module_RouteCompute::ajax_action(),
      'nonce'    => wp_create_nonce(SD_Module_RouteCompute::nonce_action()),
      'tenantId' => $tenant_id,
    ]) . ';', 'after');
  }
}

SD_Module_RouteInputsUI::register();