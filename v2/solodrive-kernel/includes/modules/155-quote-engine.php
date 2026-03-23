<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_QuoteEngine (v1.2)
 *
 * Purpose:
 * - Canonical quote draft builder (NO UI).
 * - Consumes TripRouteInputs' 6 numeric inputs on sd_quote.
 * - Produces a token-safe draft payload for tenant review.
 *
 * Doctrine:
 * - QuoteEngine does NOT own quote creation. (QuoteService does.)
 * - QuoteEngine does NOT own Maps routing. (TripRouteInputs does.)
 * - QuoteEngine does NOT present quotes to riders.
 *   Tenant must approve/adjust; only then quote transitions to PRESENTED.
 * - Pricing math is never shown publicly; /trip shows final results + confidence only.
 *
 * Output (on sd_quote):
 * - SD_Meta::P_QUOTE_DRAFT_JSON (private)
 * - SD_Meta::P_QUOTE_BUILT_AT (private)
 * - SD_Meta::P_QUOTE_BUILD_ERROR (private when failures occur)
 * - SD_Meta::QUOTE_STATUS (public) kept at PROPOSED after successful draft build,
 *   unless already advanced (APPROVED/PRESENTED/etc.)
 *
 * Output (on sd_ride):
 * - _sd_latest_quote_id (private convenience pointer)
 */

final class SD_Module_QuoteEngine {

  private static bool $did_register = false;

  // Platform policy defaults
  private const DEFAULT_PLATFORM_FEE_PERCENT       = 15.0; // operator nets 85%
  private const PLATFORM_MIN_APPLICATION_FEE_CENTS = 200;  // $2.00
  private const PLATFORM_MIN_AUTH_TOTAL_CENTS      = 250;  // $2.50

  // Tenant override meta keys (safe to use now; tenant settings UI can be wired later)
  private const META_TENANT_PLATFORM_FEE_PERCENT       = 'sd_platform_fee_percent';
  private const META_TENANT_MIN_APPLICATION_FEE_CENTS  = 'sd_platform_fee_min_cents';
  private const META_TENANT_MIN_AUTH_TOTAL_CENTS       = 'sd_quote_min_total_cents';

  // Quote financial snapshot metas
  private const META_QUOTE_AMOUNT_CENTS   = '_sd_quote_amount_cents';
  private const META_QUOTE_CURRENCY       = '_sd_quote_currency';
  private const META_QUOTE_PICKUP_ETA_MIN = '_sd_quote_pickup_eta_min';
  private const META_QUOTE_CONFIDENCE     = '_sd_quote_confidence_label';
  private const META_PLATFORM_FEE_CENTS   = '_sd_platform_fee_cents';
  private const META_OPERATOR_NET_CENTS   = '_sd_operator_net_cents';
  private const META_PLATFORM_FEE_PERCENT = '_sd_platform_fee_percent';

  // Ride → latest quote pointer (private convenience)
  private const P_RIDE_LATEST_QUOTE_ID = '_sd_latest_quote_id';

  public static function register() : void {
    if (self::$did_register) return;
    self::$did_register = true;
    // Pure library module.
  }

  /**
   * Ensure a quote exists for this ride, ensure route-inputs exist, and (re)build draft.
   * Returns quote_id (or 0).
   *
   * Options:
   * - tenant_id (int) override
   * - timeout (int) seconds for route compute (passed through)
   * - force (bool) rebuild draft even if already built recently
   */
  public static function ensure_quote_draft(int $ride_id, array $opts = []) : int {
    if ($ride_id <= 0) return 0;
    if (get_post_type($ride_id) !== 'sd_ride') return 0;

    $tenant_id = isset($opts['tenant_id'])
      ? (int) $opts['tenant_id']
      : (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);

    $timeout = isset($opts['timeout']) ? max(3, (int) $opts['timeout']) : 8;
    $force   = !empty($opts['force']);

    $quote_id = self::resolve_or_create_quote($ride_id, $tenant_id);
    if ($quote_id <= 0) return 0;

    if (class_exists('SD_Module_TripRouteInputs') && method_exists('SD_Module_TripRouteInputs', 'ensure_quote_inputs')) {
      SD_Module_TripRouteInputs::ensure_quote_inputs($ride_id, $quote_id, [
        'timeout'   => $timeout,
        'tenant_id' => $tenant_id,
      ]);
    }

    if (!$force) {
      $built_at = (int) get_post_meta($quote_id, SD_Meta::P_QUOTE_BUILT_AT, true);
      if ($built_at > 0 && (time() - $built_at) < 10) {
        return $quote_id;
      }
    }

    self::write_quote_draft($quote_id, $ride_id, $tenant_id);

    return $quote_id;
  }

  // ---------------------------------------------------------------------------
  // Quote resolution / creation
  // ---------------------------------------------------------------------------

  private static function resolve_or_create_quote(int $ride_id, int $tenant_id) : int {
    $quote_id = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
      update_post_meta($ride_id, self::P_RIDE_LATEST_QUOTE_ID, (string) $quote_id);
      return $quote_id;
    }

    $quote_id = (int) get_post_meta($ride_id, self::P_RIDE_LATEST_QUOTE_ID, true);
    if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, (string) $quote_id);
      return $quote_id;
    }

    $quote_id = self::find_quote_id_for_ride($ride_id);
    if ($quote_id > 0) {
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, (string) $quote_id);
      update_post_meta($ride_id, self::P_RIDE_LATEST_QUOTE_ID, (string) $quote_id);
      return $quote_id;
    }

    if (!class_exists('SD_Module_QuoteService') || !method_exists('SD_Module_QuoteService', 'create_for_ride')) {
      if (class_exists('SD_Util')) {
        SD_Util::log('quote_engine_missing_quote_service', [
          'ride_id'   => $ride_id,
          'tenant_id' => $tenant_id,
        ]);
      }
      return 0;
    }

    $quote_id = (int) SD_Module_QuoteService::create_for_ride(
      $ride_id,
      [],
      'quote_engine'
    );

    if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, (string) $quote_id);
      update_post_meta($ride_id, self::P_RIDE_LATEST_QUOTE_ID, (string) $quote_id);
      return $quote_id;
    }

    return 0;
  }

  private static function find_quote_id_for_ride(int $ride_id) : int {
    $q = new \WP_Query([
      'no_found_rows'  => true,
      'post_type'      => 'sd_quote',
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'orderby'        => 'ID',
      'order'          => 'ASC',
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::RIDE_ID,
        'value'   => (string) $ride_id,
        'compare' => '=',
      ]],
    ]);

    return !empty($q->posts[0]) ? (int) $q->posts[0] : 0;
  }

  // ---------------------------------------------------------------------------
  // Draft builder
  // ---------------------------------------------------------------------------

  private static function tenant_location_context(int $tenant_id) : array {
    $last_lat = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LAT, true);
    $last_lng = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LNG, true);
    $last_ts  = (int) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_TS, true);
    $last_acc = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_ACCURACY_M, true);

    $base_label = (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LABEL, true);
    $base_lat   = (float) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LAT, true);
    $base_lng   = (float) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LNG, true);

    $has_last = (abs($last_lat) > 0.0001 && abs($last_lng) > 0.0001);
    $has_base = (abs($base_lat) > 0.0001 && abs($base_lng) > 0.0001);
    $fresh = ($last_ts > 0 && (time() - $last_ts) <= 120);

    return [
      'tenant_id'             => $tenant_id,
      'live_location_fresh'   => ($has_last && $fresh),
      'live_location_label'   => $fresh ? 'fresh' : ($last_ts > 0 ? 'stale' : 'missing'),
      'last_lat'              => $last_lat,
      'last_lng'              => $last_lng,
      'last_ts'               => $last_ts,
      'last_accuracy_m'       => $last_acc,
      'base_location_label'   => $base_label !== '' ? $base_label : ($has_base ? 'set' : 'missing'),
      'base_lat'              => $base_lat,
      'base_lng'              => $base_lng,
      'base_location_present' => $has_base,
    ];
  }

  private static function read_trip_route_input(int $quote_id, string $const_name) : int {
    if (
      !class_exists('SD_Module_TripRouteInputs') ||
      !defined('SD_Module_TripRouteInputs::' . $const_name)
    ) {
      return 0;
    }

    $meta_key = constant('SD_Module_TripRouteInputs::' . $const_name);
    if (!is_string($meta_key) || $meta_key === '') {
      return 0;
    }

    return (int) get_post_meta($quote_id, $meta_key, true);
  }

  private static function write_quote_draft(int $quote_id, int $ride_id, int $tenant_id) : void {
    if (defined('SD_Meta::P_QUOTE_JOB_STATE')) {
      update_post_meta($quote_id, SD_Meta::P_QUOTE_JOB_STATE, 'running');
    }

    if (!class_exists('SD_Module_TripRouteInputs')) {
      self::write_error($quote_id, 'TripRouteInputs module missing.');
      return;
    }

    $live_m = self::read_trip_route_input($quote_id, 'Q_LIVE_TRIP_M');
    $live_s = self::read_trip_route_input($quote_id, 'Q_LIVE_TRIP_S');

    $dh0_m  = self::read_trip_route_input($quote_id, 'Q_DEADHEAD_INITIAL_M');
    $dh0_s  = self::read_trip_route_input($quote_id, 'Q_DEADHEAD_INITIAL_S');

    $dhr_m  = self::read_trip_route_input($quote_id, 'Q_DEADHEAD_RETURN_M');
    $dhr_s  = self::read_trip_route_input($quote_id, 'Q_DEADHEAD_RETURN_S');

    $trip_miles = $live_m > 0 ? round($live_m / 1609.344, 1) : 0.0;
    $trip_mins  = $live_s > 0 ? (int) max(1, round($live_s / 60)) : 0;

    $miles_to_pickup = $dh0_m > 0 ? round($dh0_m / 1609.344, 1) : 0.0;
    $pickup_eta_min  = $dh0_s > 0 ? (int) max(1, round($dh0_s / 60)) : 0;

    $return_miles = $dhr_m > 0 ? round($dhr_m / 1609.344, 1) : 0.0;
    $return_mins  = $dhr_s > 0 ? (int) max(1, round($dhr_s / 60)) : 0;

    $rates = self::tenant_rates_stub($tenant_id, $ride_id, $quote_id);

    $base  = (int) ($rates['base_fare_cents']  ?? 0);
    $pmile = (int) ($rates['per_mile_cents']   ?? 0);
    $pmin  = (int) ($rates['per_minute_cents'] ?? 0);
    $min   = (int) ($rates['min_fare_cents']   ?? 0);

    $currency = strtolower((string) ($rates['currency'] ?? 'usd'));
    if ($currency === '') {
      $currency = 'usd';
    }

    $confidence_label = 'Incomplete estimate';

    if ($live_m <= 0 && $dh0_m <= 0) {
      $confidence_label = 'Missing routing';
    } elseif ($live_m <= 0) {
      $confidence_label = 'Missing trip route';
    } elseif ($dh0_m <= 0) {
      $base_lat = (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LAT, true);
      $base_lng = (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LNG, true);
      $base_set = ($base_lat !== '' && $base_lng !== '');

      $op_ctx = self::tenant_location_context($tenant_id);
      $live_fresh = !empty($op_ctx['live_location_fresh']);

      if ($live_fresh || $base_set) {
        $confidence_label = 'Missing deadhead';
      } else {
        $confidence_label = 'Missing base location';
      }
    } else {
      $op_ctx = self::tenant_location_context($tenant_id);
      $live_fresh = !empty($op_ctx['live_location_fresh']);

      if ($live_fresh) {
        $confidence_label = 'Live operator location';
      } else {
        $confidence_label = 'Base location';
      }
    }

    $amount_cents = 0;

    if ($trip_miles > 0 || $trip_mins > 0) {
      $amount_cents = $base;
      if ($trip_miles > 0) $amount_cents += (int) round($trip_miles * $pmile);
      if ($trip_mins > 0)  $amount_cents += (int) ($trip_mins * $pmin);
      if ($amount_cents < $min) $amount_cents = $min;

      $amount_cents = (int) (round($amount_cents / 25) * 25);
    }

    $fee_policy = self::tenant_fee_policy($tenant_id);

    $bump_applied = 0;
    $bump_msg = '';

    if ($amount_cents > 0 && $amount_cents < $fee_policy['min_total_cents']) {
      $amount_cents = (int) $fee_policy['min_total_cents'];
      $bump_applied = 1;
      $bump_msg = 'Minimum service charge applied.';
    }

    $fee_calc = self::calculate_platform_fee($amount_cents, $fee_policy);
    $platform_fee_cents   = (int) $fee_calc['platform_fee_cents'];
    $operator_net_cents   = (int) $fee_calc['operator_net_cents'];
    $platform_fee_percent = (float) $fee_calc['platform_fee_percent'];

    $total_minutes_for_efficiency = max(1, $pickup_eta_min + $trip_mins);
    $total_miles_for_efficiency   = max(0.1, $miles_to_pickup + $trip_miles);

    $tot_per_60 = ($amount_cents > 0)
      ? round((($amount_cents / 100) / $total_minutes_for_efficiency) * 60, 2)
      : 0.0;

    $tot_per_mile = ($amount_cents > 0)
      ? round(($amount_cents / 100) / $total_miles_for_efficiency, 2)
      : 0.0;

    $op_ctx = self::tenant_location_context($tenant_id);

    $draft = [
      'version'     => 3,
      'computed_at' => time(),

      'inputs' => [
        'deadhead_initial' => ['meters' => $dh0_m, 'seconds' => $dh0_s],
        'live_trip'        => ['meters' => $live_m, 'seconds' => $live_s],
        'deadhead_return'  => ['meters' => $dhr_m, 'seconds' => $dhr_s],
      ],

      'derived' => [
        'live_trip_miles'   => round($trip_miles, 2),
        'live_trip_minutes' => $trip_mins,
      ],

      'quote' => [
        'total_cents'          => (int) $amount_cents,
        'currency'             => $currency,
        'pickup_eta_min'       => $pickup_eta_min,
        'confidence_label'     => $confidence_label,
        'platform_fee_cents'   => $platform_fee_cents,
        'operator_net_cents'   => $operator_net_cents,
        'platform_fee_percent' => $platform_fee_percent,
        'min_bump_applied'     => (int) $bump_applied,
        'min_bump_message'     => $bump_msg,
      ],

      'ops' => [
        'miles_to_pickup' => $miles_to_pickup,
        'trip_miles'      => $trip_miles,
        'trip_mins'       => $trip_mins,
        'tot_per_60'      => $tot_per_60,
        'tot_per_mile'    => $tot_per_mile,
      ],

      'telemetry' => [
        'source'              => !empty($op_ctx['live_location_fresh'])
          ? 'tenant_last_known'
          : (!empty($op_ctx['base_location_present']) ? 'tenant_base_location' : 'unknown'),
        'last_known_lat'      => (float) ($op_ctx['last_lat'] ?? 0.0),
        'last_known_lng'      => (float) ($op_ctx['last_lng'] ?? 0.0),
        'last_known_ts'       => (int) ($op_ctx['last_ts'] ?? 0),
        'freshness'           => (string) ($op_ctx['live_location_label'] ?? 'missing'),
        'base_location_label' => (string) ($op_ctx['base_location_label'] ?? 'missing'),
      ],

      'routing' => [
        'pickup_route_meters'  => $dh0_m,
        'pickup_route_seconds' => $dh0_s,
        'trip_route_meters'    => $live_m,
        'trip_route_seconds'   => $live_s,
        'return_route_meters'  => $dhr_m,
        'return_route_seconds' => $dhr_s,
      ],
    ];

    update_post_meta($quote_id, SD_Meta::P_QUOTE_DRAFT_JSON, wp_json_encode($draft));
    update_post_meta($quote_id, SD_Meta::P_QUOTE_BUILT_AT, time());
    delete_post_meta($quote_id, SD_Meta::P_QUOTE_BUILD_ERROR);

    update_post_meta($quote_id, self::META_QUOTE_AMOUNT_CENTS, $amount_cents);
    update_post_meta($quote_id, self::META_QUOTE_CURRENCY, $currency);
    update_post_meta($quote_id, self::META_QUOTE_PICKUP_ETA_MIN, $pickup_eta_min);
    update_post_meta($quote_id, self::META_QUOTE_CONFIDENCE, $confidence_label);
    update_post_meta($quote_id, self::META_PLATFORM_FEE_CENTS, $platform_fee_cents);
    update_post_meta($quote_id, self::META_OPERATOR_NET_CENTS, $operator_net_cents);
    update_post_meta($quote_id, self::META_PLATFORM_FEE_PERCENT, $platform_fee_percent);

    $cur = (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true);
    if ($cur === '' || $cur === 'PROPOSED') {
      self::set_quote_status($quote_id, 'PROPOSED');
    }

    if (defined('SD_Meta::P_QUOTE_JOB_STATE')) {
      update_post_meta($quote_id, SD_Meta::P_QUOTE_JOB_STATE, 'ok');
    }
  }

  private static function tenant_fee_policy(int $tenant_id) : array {
    $percent = (float) get_post_meta($tenant_id, self::META_TENANT_PLATFORM_FEE_PERCENT, true);
    if ($percent <= 0) {
      $percent = self::DEFAULT_PLATFORM_FEE_PERCENT;
    }

    $min_fee_cents = (int) get_post_meta($tenant_id, self::META_TENANT_MIN_APPLICATION_FEE_CENTS, true);
    if ($min_fee_cents <= 0) {
      $min_fee_cents = self::PLATFORM_MIN_APPLICATION_FEE_CENTS;
    }

    $min_total_cents = (int) get_post_meta($tenant_id, self::META_TENANT_MIN_AUTH_TOTAL_CENTS, true);
    if ($min_total_cents <= 0) {
      $min_total_cents = self::PLATFORM_MIN_AUTH_TOTAL_CENTS;
    }

    return [
      'platform_fee_percent' => $percent,
      'min_fee_cents'        => $min_fee_cents,
      'min_total_cents'      => $min_total_cents,
    ];
  }

  private static function calculate_platform_fee(int $total_cents, array $policy) : array {
    $percent       = (float) ($policy['platform_fee_percent'] ?? self::DEFAULT_PLATFORM_FEE_PERCENT);
    $min_fee_cents = (int) ($policy['min_fee_cents'] ?? self::PLATFORM_MIN_APPLICATION_FEE_CENTS);

    if ($total_cents <= 0) {
      return [
        'platform_fee_percent' => $percent,
        'platform_fee_cents'   => 0,
        'operator_net_cents'   => 0,
      ];
    }

    $raw_fee_cents = (int) round($total_cents * ($percent / 100));
    $platform_fee_cents = max($raw_fee_cents, $min_fee_cents);

    if ($platform_fee_cents > $total_cents) {
      $platform_fee_cents = $total_cents;
    }

    $operator_net_cents = max(0, $total_cents - $platform_fee_cents);

    return [
      'platform_fee_percent' => $percent,
      'platform_fee_cents'   => $platform_fee_cents,
      'operator_net_cents'   => $operator_net_cents,
    ];
  }

  private static function write_error(int $quote_id, string $msg) : void {
    update_post_meta($quote_id, SD_Meta::P_QUOTE_BUILD_ERROR, sanitize_text_field($msg));
    update_post_meta($quote_id, SD_Meta::P_QUOTE_BUILT_AT, time());
    if (defined('SD_Meta::P_QUOTE_JOB_STATE')) {
      update_post_meta($quote_id, SD_Meta::P_QUOTE_JOB_STATE, 'error');
    }
  }

  private static function set_quote_status(int $quote_id, string $status) : void {
    $status = trim($status);
    if ($status === '') return;

    $cur = (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true);
    if ($cur !== $status) {
      update_post_meta($quote_id, SD_Meta::QUOTE_STATUS, $status);
      update_post_meta($quote_id, SD_Meta::P_QUOTE_STATUS_UPDATED_AT, time());
    }
  }

  /**
   * Stub for tenant-scoped storefront rates.
   * Replace with your Storefront Settings module once wired.
   */
  private static function tenant_rates_stub(int $tenant_id, int $ride_id, int $quote_id) : array {
    return [
      'base_fare_cents'  => 300,
      'per_mile_cents'   => 120,
      'per_minute_cents' => 45,
      'min_fare_cents'   => 900,
      'currency'         => 'usd',
      'pickup_eta_min'   => 10,
      'confidence_label' => 'Estimate',
    ];
  }
}