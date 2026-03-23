<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_TripRouteInputs (v1.3)
 *
 * Purpose:
 * - Reusable route input UI for manual tools
 * - Canonical quote-route input builder for QuoteEngine
 *
 * Canon:
 * - Ride is the canonical operational record
 * - Quote stores the route-input snapshot used for that quote
 * - Inputs must support:
 *     - place_id first
 *     - lat/lng fallback
 * - Deadhead origin must prefer fresh live tenant location, then base location
 */

final class SD_Module_TripRouteInputs {

  // ---------------------------------------------------------------------------
  // Quote input snapshot keys (private on quote)
  // ---------------------------------------------------------------------------
  public const Q_LIVE_TRIP_M         = '_sd_q_live_trip_m';
  public const Q_LIVE_TRIP_S         = '_sd_q_live_trip_s';
  public const Q_DEADHEAD_INITIAL_M  = '_sd_q_deadhead_initial_m';
  public const Q_DEADHEAD_INITIAL_S  = '_sd_q_deadhead_initial_s';
  public const Q_DEADHEAD_RETURN_M   = '_sd_q_deadhead_return_m';
  public const Q_DEADHEAD_RETURN_S   = '_sd_q_deadhead_return_s';

  public const P_INPUTS_STATUS       = '_sd_q_route_inputs_status';
  public const P_INPUTS_ERROR        = '_sd_q_route_inputs_error';
  public const P_INPUTS_BUILT_AT     = '_sd_q_route_inputs_built_at';

  private const LIVE_FRESH_WINDOW_S  = 120;

  public static function register() : void {
    add_shortcode('trip_route_inputs',    [__CLASS__, 'shortcode']);
    add_shortcode('sd_trip_route_inputs', [__CLASS__, 'shortcode']);
    add_action('wp_enqueue_scripts',      [__CLASS__, 'maybe_enqueue'], 20);
  }

  // ---------------------------------------------------------------------------
  // Canonical route-input builder used by QuoteEngine
  // ---------------------------------------------------------------------------

  public static function ensure_quote_inputs(int $ride_id, int $quote_id, array $opts = []) : void {
    if ($ride_id <= 0 || $quote_id <= 0) return;
    if (get_post_type($ride_id) !== 'sd_ride') return;
    if (get_post_type($quote_id) !== 'sd_quote') return;
    if (!class_exists('SD_Route_Service')) {
      self::write_inputs_error($quote_id, 'Route service unavailable.');
      return;
    }

    $tenant_id = isset($opts['tenant_id'])
      ? (int) $opts['tenant_id']
      : (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);

    $timeout = isset($opts['timeout']) ? max(3, (int) $opts['timeout']) : 8;
    $force   = !empty($opts['force']);

    if (!$force && self::inputs_exist($quote_id)) {
      return;
    }

    $pickup  = self::ride_pickup_point($ride_id);
    $dropoff = self::ride_dropoff_point($ride_id);

    if (!$pickup || !$dropoff) {
      self::write_inputs_error($quote_id, 'Ride pickup/dropoff missing.');
      return;
    }

    $tenant_ctx = self::tenant_location_context($tenant_id);

    $deadhead_origin = null;
    if (!empty($tenant_ctx['live_location_fresh'])) {
      $deadhead_origin = [
        'lat' => (float) $tenant_ctx['last_lat'],
        'lng' => (float) $tenant_ctx['last_lng'],
      ];
    } elseif (!empty($tenant_ctx['base_location_present'])) {
      $deadhead_origin = [
        'lat' => (float) $tenant_ctx['base_lat'],
        'lng' => (float) $tenant_ctx['base_lng'],
      ];
    }

    $deadhead_return_dest = !empty($tenant_ctx['base_location_present'])
      ? [
          'lat' => (float) $tenant_ctx['base_lat'],
          'lng' => (float) $tenant_ctx['base_lng'],
        ]
      : null;

    // Trip leg: pickup -> dropoff
    $trip_leg = SD_Route_Service::compute_leg($pickup, $dropoff, [
      'tenant_id' => $tenant_id,
      'timeout'   => $timeout,
    ]);

    if (!$trip_leg) {
      self::write_inputs_error($quote_id, 'Trip route compute failed.');
      return;
    }

    $trip_m = (int) ($trip_leg['meters'] ?? 0);
    $trip_s = (int) ($trip_leg['seconds'] ?? 0);

    // Deadhead initial: origin -> pickup
    $dh0_m = 0;
    $dh0_s = 0;
    if ($deadhead_origin) {
      $dh0_leg = SD_Route_Service::compute_leg($deadhead_origin, $pickup, [
        'tenant_id' => $tenant_id,
        'timeout'   => $timeout,
      ]);

      if ($dh0_leg) {
        $dh0_m = (int) ($dh0_leg['meters'] ?? 0);
        $dh0_s = (int) ($dh0_leg['seconds'] ?? 0);
      }
    }

    // Deadhead return: dropoff -> base
    $dhr_m = 0;
    $dhr_s = 0;
    if ($deadhead_return_dest) {
      $dhr_leg = SD_Route_Service::compute_leg($dropoff, $deadhead_return_dest, [
        'tenant_id' => $tenant_id,
        'timeout'   => $timeout,
      ]);

      if ($dhr_leg) {
        $dhr_m = (int) ($dhr_leg['meters'] ?? 0);
        $dhr_s = (int) ($dhr_leg['seconds'] ?? 0);
      }
    }

    update_post_meta($quote_id, self::Q_LIVE_TRIP_M, $trip_m);
    update_post_meta($quote_id, self::Q_LIVE_TRIP_S, $trip_s);
    update_post_meta($quote_id, self::Q_DEADHEAD_INITIAL_M, $dh0_m);
    update_post_meta($quote_id, self::Q_DEADHEAD_INITIAL_S, $dh0_s);
    update_post_meta($quote_id, self::Q_DEADHEAD_RETURN_M, $dhr_m);
    update_post_meta($quote_id, self::Q_DEADHEAD_RETURN_S, $dhr_s);

    update_post_meta($quote_id, self::P_INPUTS_STATUS, 'ok');
    update_post_meta($quote_id, self::P_INPUTS_BUILT_AT, time());
    delete_post_meta($quote_id, self::P_INPUTS_ERROR);
  }

  private static function inputs_exist(int $quote_id) : bool {
    $status = (string) get_post_meta($quote_id, self::P_INPUTS_STATUS, true);
    $trip_m = (int) get_post_meta($quote_id, self::Q_LIVE_TRIP_M, true);
    $trip_s = (int) get_post_meta($quote_id, self::Q_LIVE_TRIP_S, true);

    return ($status === 'ok' && $trip_m > 0 && $trip_s > 0);
  }

  // ---------------------------------------------------------------------------
  // UI shortcode
  // ---------------------------------------------------------------------------

  public static function shortcode($atts = []) : string {
    $atts = shortcode_atts([
      'title'         => 'Route Inputs',
      'origin_label'  => 'Pickup',
      'dest_label'    => 'Dropoff',
      'country'       => '',
      'require_login' => '0',
    ], (array) $atts, 'sd_trip_route_inputs');

    $require_login = ((string) $atts['require_login'] === '1');

    if ($require_login && !is_user_logged_in()) {
      return '<div class="sd-surface sd-surface--wide"><div class="sd-card"><strong>Login required.</strong></div></div>';
    }

    $tenant_id = class_exists('SD_Module_TenantResolver')
      ? (int) SD_Module_TenantResolver::current_tenant_id()
      : 0;

    $base = ['lat' => null, 'lng' => null, 'radius_m' => null];
    if ($tenant_id > 0 && class_exists('SD_Meta')) {
      $lat = trim((string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LAT, true));
      $lng = trim((string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LNG, true));
      $rad = (int) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_RADIUS_M, true);
      if ($rad <= 0) $rad = 40000;

      if ($lat !== '' && $lng !== '' && is_numeric($lat) && is_numeric($lng)) {
        $base = ['lat' => (float) $lat, 'lng' => (float) $lng, 'radius_m' => (int) $rad];
      }
    }

    $country = trim((string) $atts['country']);
    if ($country === '') {
      $country = (string) apply_filters('sd_intake_default_country', 'us', $tenant_id);
    }

    $id = 'sd-trip-route-inputs-' . wp_generate_password(10, false, false);

    ob_start();
    ?>
    <div class="sd-surface sd-surface--wide sd-trip-route-inputs tenant-trip-route-inputs" id="<?php echo esc_attr($id); ?>">
      <div class="sd-card tenant-trip-route-card">
        <h2 class="sd-h2"><?php echo esc_html((string) $atts['title']); ?></h2>
        <div class="sd-sub">Enter two locations and compute distance + ETA primitives (no pricing).</div>

        <div class="sd-field" style="margin-top:12px;">
          <label class="sd-label"><?php echo esc_html((string) $atts['origin_label']); ?></label>
          <input class="sd-input" type="text" name="origin_address" placeholder="Start typing…" autocomplete="off" />
          <input type="hidden" name="origin_place_id" value="" />
        </div>

        <div class="sd-field" style="margin-top:12px;">
          <label class="sd-label"><?php echo esc_html((string) $atts['dest_label']); ?></label>
          <input class="sd-input" type="text" name="dest_address" placeholder="Start typing…" autocomplete="off" />
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

      var country = <?php echo wp_json_encode($country); ?>;
      var base    = <?php echo wp_json_encode($base); ?>;

      if (window.SD_Places && SD_Places.bind) {
        SD_Places.bind({
          root: root,
          input: 'input[name="origin_address"]',
          placeId: 'input[name="origin_place_id"]',
          country: country,
          base: base
        });

        SD_Places.bind({
          root: root,
          input: 'input[name="dest_address"]',
          placeId: 'input[name="dest_place_id"]',
          country: country,
          base: base
        });
      }

      var btn = root.querySelector('[data-sd-route-go="1"]');
      var out = root.querySelector('[data-sd-route-out="1"]');

      function fmt(seconds){
        seconds = Math.max(0, seconds|0);
        var m = Math.round(seconds / 60);
        if (m < 60) return m + " min";
        var h = Math.floor(m / 60);
        var mm = m % 60;
        return h + "h " + mm + "m";
      }

      function miles(meters){ return (meters / 1609.344); }
      function setOut(msg){ if (out) out.textContent = msg; }

      if (btn) {
        btn.addEventListener('click', function(){
          var op = (root.querySelector('input[name="origin_place_id"]') || {}).value || '';
          var dp = (root.querySelector('input[name="dest_place_id"]') || {}).value || '';

          if (!op || !dp) {
            setOut("Please choose both locations from the suggestions list.");
            return;
          }

          if (!window.SD_ROUTE_COMPUTE) {
            setOut("Route compute not configured.");
            return;
          }

          setOut("Computing…");

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
                setOut((j && j.data && j.data.message) ? j.data.message : "Compute failed.");
                return;
              }
              var meters  = j.data.meters|0;
              var seconds = j.data.seconds|0;
              setOut(miles(meters).toFixed(1) + " mi • " + fmt(seconds));
            })
            .catch(function(){
              setOut("Compute failed.");
            });
        });
      }
    })();
    </script>
    <?php
    return (string) ob_get_clean();
  }

  public static function maybe_enqueue() : void {
    if (class_exists('SD_Module_Places')) {
      SD_Module_Places::enqueue();
    }

    if (!class_exists('SD_Module_RouteCompute')) return;

    $tenant_id = class_exists('SD_Module_TenantResolver')
      ? (int) SD_Module_TenantResolver::current_tenant_id()
      : 0;

    $h = 'sd-trip-route-inputs-ui';
    if (!wp_script_is($h, 'registered')) {
      wp_register_script($h, '', [], '1.3', true);
    }
    wp_enqueue_script($h);

    wp_add_inline_script($h, 'window.SD_ROUTE_COMPUTE=' . wp_json_encode([
      'ajaxUrl'  => admin_url('admin-ajax.php'),
      'action'   => SD_Module_RouteCompute::ajax_action(),
      'nonce'    => wp_create_nonce(SD_Module_RouteCompute::nonce_action()),
      'tenantId' => $tenant_id,
    ]) . ';', 'after');
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  private static function tenant_location_context(int $tenant_id) : array {
    if ($tenant_id <= 0) {
      return [
        'live_location_fresh'   => false,
        'live_location_label'   => 'missing',
        'last_lat'              => 0.0,
        'last_lng'              => 0.0,
        'last_ts'               => 0,
        'base_location_label'   => 'missing',
        'base_lat'              => 0.0,
        'base_lng'              => 0.0,
        'base_location_present' => false,
      ];
    }

    $last_lat = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LAT, true);
    $last_lng = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LNG, true);
    $last_ts  = (int) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_TS, true);

    $base_label = (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LABEL, true);
    $base_lat   = (float) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LAT, true);
    $base_lng   = (float) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LNG, true);

    $has_last = (abs($last_lat) > 0.0001 && abs($last_lng) > 0.0001);
    $has_base = (abs($base_lat) > 0.0001 && abs($base_lng) > 0.0001);
    $fresh    = ($last_ts > 0 && (time() - $last_ts) <= self::LIVE_FRESH_WINDOW_S);

    return [
      'live_location_fresh'   => ($has_last && $fresh),
      'live_location_label'   => $fresh ? 'fresh' : ($last_ts > 0 ? 'stale' : 'missing'),
      'last_lat'              => $last_lat,
      'last_lng'              => $last_lng,
      'last_ts'               => $last_ts,
      'base_location_label'   => $base_label !== '' ? $base_label : ($has_base ? 'set' : 'missing'),
      'base_lat'              => $base_lat,
      'base_lng'              => $base_lng,
      'base_location_present' => $has_base,
    ];
  }

  private static function meta_key(string $const_name, string $fallback) : string {
    return defined('SD_Meta::' . $const_name)
      ? (string) constant('SD_Meta::' . $const_name)
      : $fallback;
  }

  private static function ride_pickup_point(int $ride_id) : ?array {
    $pickup_place_key = self::meta_key('PICKUP_PLACE_ID', 'sd_pickup_place_id');
    $pickup_lat_key   = self::meta_key('PICKUP_LAT', 'sd_pickup_lat');
    $pickup_lng_key   = self::meta_key('PICKUP_LNG', 'sd_pickup_lng');

    $pid = trim((string) get_post_meta($ride_id, $pickup_place_key, true));
    if ($pid !== '') {
      return ['place_id' => $pid];
    }

    $lat = (float) get_post_meta($ride_id, $pickup_lat_key, true);
    $lng = (float) get_post_meta($ride_id, $pickup_lng_key, true);
    if (abs($lat) > 0.0001 && abs($lng) > 0.0001) {
      return ['lat' => $lat, 'lng' => $lng];
    }

    return null;
  }

  private static function ride_dropoff_point(int $ride_id) : ?array {
    $dropoff_place_key = self::meta_key('DROPOFF_PLACE_ID', 'sd_dropoff_place_id');
    $dropoff_lat_key   = self::meta_key('DROPOFF_LAT', 'sd_dropoff_lat');
    $dropoff_lng_key   = self::meta_key('DROPOFF_LNG', 'sd_dropoff_lng');

    $pid = trim((string) get_post_meta($ride_id, $dropoff_place_key, true));
    if ($pid !== '') {
      return ['place_id' => $pid];
    }

    $lat = (float) get_post_meta($ride_id, $dropoff_lat_key, true);
    $lng = (float) get_post_meta($ride_id, $dropoff_lng_key, true);
    if (abs($lat) > 0.0001 && abs($lng) > 0.0001) {
      return ['lat' => $lat, 'lng' => $lng];
    }

    return null;
  }

  private static function write_inputs_error(int $quote_id, string $message) : void {
    update_post_meta($quote_id, self::P_INPUTS_STATUS, 'error');
    update_post_meta($quote_id, self::P_INPUTS_ERROR, sanitize_text_field($message));
    update_post_meta($quote_id, self::P_INPUTS_BUILT_AT, time());
  }
}

SD_Module_TripRouteInputs::register();