<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorExecutionIntel
 *
 * Purpose:
 * - Write a derived execution-intel snapshot onto the ride.
 * - Support premium public /trip UX without altering canonical lifecycle.
 *
 * Canon:
 * - Derived UX layer only.
 * - Does NOT replace ride state.
 * - Does NOT perform lifecycle transitions.
 * - v1a is owner/operator only.
 *
 * Writes (private ride meta):
 * - _sd_exec_phase
 * - _sd_exec_phase_ts
 * - _sd_exec_distance_to_pickup_m
 * - _sd_exec_eta_to_pickup_min
 * - _sd_exec_distance_to_dropoff_m
 * - _sd_exec_eta_to_dropoff_min
 * - _sd_exec_operator_lat
 * - _sd_exec_operator_lng
 * - _sd_exec_operator_ts
 * - _sd_exec_operator_accuracy_m
 * - _sd_exec_pickup_lat
 * - _sd_exec_pickup_lng
 * - _sd_exec_dropoff_lat
 * - _sd_exec_dropoff_lng
 */

if (class_exists('SD_Module_OperatorExecutionIntel', false)) { return; }

final class SD_Module_OperatorExecutionIntel {

  private const M_PHASE                 = '_sd_exec_phase';
  private const M_PHASE_TS              = '_sd_exec_phase_ts';
  private const M_DISTANCE_TO_PICKUP_M  = '_sd_exec_distance_to_pickup_m';
  private const M_ETA_TO_PICKUP_MIN     = '_sd_exec_eta_to_pickup_min';
  private const M_DISTANCE_TO_DROPOFF_M = '_sd_exec_distance_to_dropoff_m';
  private const M_ETA_TO_DROPOFF_MIN    = '_sd_exec_eta_to_dropoff_min';
  private const M_OPERATOR_LAT          = '_sd_exec_operator_lat';
  private const M_OPERATOR_LNG          = '_sd_exec_operator_lng';
  private const M_OPERATOR_TS           = '_sd_exec_operator_ts';
  private const M_OPERATOR_ACCURACY_M   = '_sd_exec_operator_accuracy_m';
  private const M_PICKUP_LAT            = '_sd_exec_pickup_lat';
  private const M_PICKUP_LNG            = '_sd_exec_pickup_lng';
  private const M_DROPOFF_LAT           = '_sd_exec_dropoff_lat';
  private const M_DROPOFF_LNG           = '_sd_exec_dropoff_lng';

  private const PHASE_UNKNOWN              = 'PHASE_UNKNOWN';
  private const PHASE_QUEUED               = 'PHASE_QUEUED';
  private const PHASE_DEADHEAD_INITIAL     = 'PHASE_DEADHEAD_INITIAL';
  private const PHASE_DEADHEAD_NEAR        = 'PHASE_DEADHEAD_NEAR';
  private const PHASE_DEADHEAD_VERY_NEAR   = 'PHASE_DEADHEAD_VERY_NEAR';
  private const PHASE_WAITING              = 'PHASE_WAITING';
  private const PHASE_INPROGRESS           = 'PHASE_INPROGRESS';
  private const PHASE_DESTINATION_ARRIVED  = 'PHASE_DESTINATION_ARRIVED';
  private const PHASE_COMPLETE             = 'PHASE_COMPLETE';
  private const PHASE_CANCELLED            = 'PHASE_CANCELLED';

  public static function register() : void {
    add_action('updated_post_meta', [__CLASS__, 'maybe_recompute_from_meta'], 10, 4);
    add_action('added_post_meta',   [__CLASS__, 'maybe_recompute_from_meta'], 10, 4);

    add_action('updated_post_meta', [__CLASS__, 'maybe_recompute_from_tenant_meta'], 10, 4);
    add_action('added_post_meta',   [__CLASS__, 'maybe_recompute_from_tenant_meta'], 10, 4);
  }

  public static function maybe_recompute_from_meta($meta_id, $post_id, $meta_key, $meta_value) : void {
    if (get_post_type((int) $post_id) !== SD_CPT_Ride::CPT) {
      return;
    }

    if (!in_array((string) $meta_key, [
      SD_Meta::RIDE_STATE,
      SD_Meta::PICKUP_LAT,
      SD_Meta::PICKUP_LNG,
      SD_Meta::DROPOFF_LAT,
      SD_Meta::DROPOFF_LNG,
      SD_Meta::PICKUP_TEXT,
      SD_Meta::DROPOFF_TEXT,
      SD_Meta::TENANT_ID,
      SD_Meta::QUOTE_ID,
    ], true)) {
      return;
    }

    self::recompute_for_ride((int) $post_id);
  }

  public static function maybe_recompute_from_tenant_meta($meta_id, $post_id, $meta_key, $meta_value) : void {
    if (get_post_type((int) $post_id) !== 'sd_tenant') {
      return;
    }

    if (!in_array((string) $meta_key, [
      SD_Meta::TENANT_LAST_LOCATION_LAT,
      SD_Meta::TENANT_LAST_LOCATION_LNG,
      SD_Meta::TENANT_LAST_LOCATION_TS,
      SD_Meta::TENANT_LAST_LOCATION_ACCURACY_M,
      SD_Meta::BASE_LOCATION_LAT,
      SD_Meta::BASE_LOCATION_LNG,
    ], true)) {
      return;
    }

    $tenant_id = (int) $post_id;
    if ($tenant_id <= 0) return;

    $ride_ids = get_posts([
      'post_type'      => SD_CPT_Ride::CPT,
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 25,
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => SD_Meta::TENANT_ID,
          'value'   => $tenant_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    foreach ($ride_ids as $ride_id) {
      self::recompute_for_ride((int) $ride_id);
    }
  }

  public static function recompute_for_ride(int $ride_id) : void {
    if ($ride_id <= 0 || get_post_type($ride_id) !== SD_CPT_Ride::CPT) {
      return;
    }

    $tenant_id  = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    $ride_state = (string) get_post_meta($ride_id, SD_Meta::RIDE_STATE, true);

    $pickup_lat  = (float) get_post_meta($ride_id, SD_Meta::PICKUP_LAT, true);
    $pickup_lng  = (float) get_post_meta($ride_id, SD_Meta::PICKUP_LNG, true);
    $dropoff_lat = (float) get_post_meta($ride_id, SD_Meta::DROPOFF_LAT, true);
    $dropoff_lng = (float) get_post_meta($ride_id, SD_Meta::DROPOFF_LNG, true);

    $quote_id    = self::resolve_quote_id_for_ride($ride_id);
    $quote_draft = self::read_quote_draft($quote_id);

    $operator = class_exists('SD_Module_OperatorLocationResolver')
      ? SD_Module_OperatorLocationResolver::resolve_for_tenant($tenant_id)
      : ['ok' => false];

    $distance_to_pickup_m  = 0;
    $eta_to_pickup_min     = 0;
    $distance_to_dropoff_m = 0;
    $eta_to_dropoff_min    = 0;

    // -----------------------------------------------------------------------
    // Pickup metrics
    // -----------------------------------------------------------------------

    if (!empty($operator['ok']) && self::valid_latlng($pickup_lat, $pickup_lng)) {
      $distance_to_pickup_m = (int) round(self::haversine_m(
        (float) $operator['lat'],
        (float) $operator['lng'],
        $pickup_lat,
        $pickup_lng
      ));

      $eta_to_pickup_min = max(1, (int) round($distance_to_pickup_m / 8.94 / 60));
    }

    // Fallback to quote draft routing if pickup coords are missing or unresolved.
    if ($distance_to_pickup_m <= 0) {
      $distance_to_pickup_m = (int) ($quote_draft['routing']['pickup_route_meters'] ?? 0);
    }

    if ($eta_to_pickup_min <= 0) {
      $seconds = (int) ($quote_draft['routing']['pickup_route_seconds'] ?? 0);
      if ($seconds > 0) {
        $eta_to_pickup_min = max(1, (int) ceil($seconds / 60));
      }
    }

    if ($eta_to_pickup_min <= 0) {
      $eta_to_pickup_min = (int) ($quote_draft['quote']['pickup_eta_min'] ?? 0);
    }

    if ($ride_state === 'RIDE_WAITING') {
      $distance_to_pickup_m = 0;
      $eta_to_pickup_min    = 0;
    }

    // -----------------------------------------------------------------------
    // Dropoff metrics
    // -----------------------------------------------------------------------

    if ($ride_state === 'RIDE_INPROGRESS') {
      if (!empty($operator['ok']) && self::valid_latlng($dropoff_lat, $dropoff_lng)) {
        $distance_to_dropoff_m = (int) round(self::haversine_m(
          (float) $operator['lat'],
          (float) $operator['lng'],
          $dropoff_lat,
          $dropoff_lng
        ));

        $eta_to_dropoff_min = max(1, (int) round($distance_to_dropoff_m / 8.94 / 60));
      }

      if ($distance_to_dropoff_m <= 0) {
        $distance_to_dropoff_m = (int) ($quote_draft['routing']['trip_route_meters'] ?? 0);
      }

      if ($eta_to_dropoff_min <= 0) {
        $seconds = (int) ($quote_draft['routing']['trip_route_seconds'] ?? 0);
        if ($seconds > 0) {
          $eta_to_dropoff_min = max(1, (int) ceil($seconds / 60));
        }
      }
    }

    if (in_array($ride_state, ['RIDE_ARRIVED', 'RIDE_COMPLETE', 'RIDE_CANCELLED'], true)) {
      $distance_to_dropoff_m = 0;
      $eta_to_dropoff_min    = 0;
    }

    $phase = self::derive_phase($ride_state, $distance_to_pickup_m);

    // Preserve phase_ts unless the phase actually changed.
    $prior_phase = (string) get_post_meta($ride_id, self::M_PHASE, true);
    $phase_ts    = (int) get_post_meta($ride_id, self::M_PHASE_TS, true);

    if ($phase !== $prior_phase || $phase_ts <= 0) {
      $phase_ts = time();
    }

    update_post_meta($ride_id, self::M_PHASE, $phase);
    update_post_meta($ride_id, self::M_PHASE_TS, $phase_ts);
    update_post_meta($ride_id, self::M_DISTANCE_TO_PICKUP_M, $distance_to_pickup_m);
    update_post_meta($ride_id, self::M_ETA_TO_PICKUP_MIN, $eta_to_pickup_min);
    update_post_meta($ride_id, self::M_DISTANCE_TO_DROPOFF_M, $distance_to_dropoff_m);
    update_post_meta($ride_id, self::M_ETA_TO_DROPOFF_MIN, $eta_to_dropoff_min);
    update_post_meta($ride_id, self::M_PICKUP_LAT, $pickup_lat);
    update_post_meta($ride_id, self::M_PICKUP_LNG, $pickup_lng);
    update_post_meta($ride_id, self::M_DROPOFF_LAT, $dropoff_lat);
    update_post_meta($ride_id, self::M_DROPOFF_LNG, $dropoff_lng);

    if (!empty($operator['ok'])) {
      update_post_meta($ride_id, self::M_OPERATOR_LAT, (float) $operator['lat']);
      update_post_meta($ride_id, self::M_OPERATOR_LNG, (float) $operator['lng']);
      update_post_meta($ride_id, self::M_OPERATOR_TS, (int) ($operator['ts'] ?? 0));
      update_post_meta($ride_id, self::M_OPERATOR_ACCURACY_M, (float) ($operator['accuracy_m'] ?? 0.0));
    }

    if (class_exists('SD_Util')) {
      SD_Util::log('operator_execution_intel_updated', [
        'ride_id'               => $ride_id,
        'tenant_id'             => $tenant_id,
        'ride_state'            => $ride_state,
        'phase'                 => $phase,
        'distance_m'            => $distance_to_pickup_m,
        'eta_min'               => $eta_to_pickup_min,
        'distance_to_dropoff_m' => $distance_to_dropoff_m,
        'eta_to_dropoff_min'    => $eta_to_dropoff_min,
        'source'                => (string) ($operator['source'] ?? 'none'),
      ]);
    }
  }

  public static function read_for_ride(int $ride_id) : array {
    return [
      'phase'                 => (string) get_post_meta($ride_id, self::M_PHASE, true),
      'phase_ts'              => (int) get_post_meta($ride_id, self::M_PHASE_TS, true),
      'distance_to_pickup_m'  => (int) get_post_meta($ride_id, self::M_DISTANCE_TO_PICKUP_M, true),
      'eta_to_pickup_min'     => (int) get_post_meta($ride_id, self::M_ETA_TO_PICKUP_MIN, true),
      'distance_to_dropoff_m' => (int) get_post_meta($ride_id, self::M_DISTANCE_TO_DROPOFF_M, true),
      'eta_to_dropoff_min'    => (int) get_post_meta($ride_id, self::M_ETA_TO_DROPOFF_MIN, true),
      'operator_lat'          => (float) get_post_meta($ride_id, self::M_OPERATOR_LAT, true),
      'operator_lng'          => (float) get_post_meta($ride_id, self::M_OPERATOR_LNG, true),
      'operator_ts'           => (int) get_post_meta($ride_id, self::M_OPERATOR_TS, true),
      'operator_accuracy_m'   => (float) get_post_meta($ride_id, self::M_OPERATOR_ACCURACY_M, true),
      'pickup_lat'            => (float) get_post_meta($ride_id, self::M_PICKUP_LAT, true),
      'pickup_lng'            => (float) get_post_meta($ride_id, self::M_PICKUP_LNG, true),
      'dropoff_lat'           => (float) get_post_meta($ride_id, self::M_DROPOFF_LAT, true),
      'dropoff_lng'           => (float) get_post_meta($ride_id, self::M_DROPOFF_LNG, true),
    ];
  }

  private static function resolve_quote_id_for_ride(int $ride_id) : int {
    $from_ride = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($from_ride > 0 && get_post_type($from_ride) === 'sd_quote') {
      return $from_ride;
    }

    $latest = (int) get_post_meta($ride_id, '_sd_latest_quote_id', true);
    if ($latest > 0 && get_post_type($latest) === 'sd_quote') {
      return $latest;
    }

    $ids = get_posts([
      'post_type'      => 'sd_quote',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => SD_Meta::RIDE_ID,
          'value'   => $ride_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  private static function read_quote_draft(int $quote_id) : array {
    if ($quote_id <= 0) return [];

    $raw = (string) get_post_meta($quote_id, SD_Meta::P_QUOTE_DRAFT_JSON, true);
    if ($raw === '') return [];

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
  }

  private static function derive_phase(string $ride_state, int $distance_m) : string {
    switch ($ride_state) {
      case 'RIDE_QUEUED':
        return self::PHASE_QUEUED;

      case 'RIDE_DEADHEAD':
        if ($distance_m > 0 && $distance_m <= 120) {
          return self::PHASE_DEADHEAD_VERY_NEAR;
        }
        if ($distance_m > 0 && $distance_m <= 500) {
          return self::PHASE_DEADHEAD_NEAR;
        }
        return self::PHASE_DEADHEAD_INITIAL;

      case 'RIDE_WAITING':
        return self::PHASE_WAITING;

      case 'RIDE_INPROGRESS':
        return self::PHASE_INPROGRESS;

      case 'RIDE_ARRIVED':
        return self::PHASE_DESTINATION_ARRIVED;

      case 'RIDE_COMPLETE':
        return self::PHASE_COMPLETE;

      case 'RIDE_CANCELLED':
        return self::PHASE_CANCELLED;

      default:
        return self::PHASE_UNKNOWN;
    }
  }

  private static function haversine_m(float $lat1, float $lng1, float $lat2, float $lng2) : float {
    $earth = 6371000.0;

    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);

    $a = sin($dLat / 2) * sin($dLat / 2)
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLng / 2) * sin($dLng / 2);

    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earth * $c;
  }

  private static function valid_latlng(float $lat, float $lng) : bool {
    return ($lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0)
      && !(abs($lat) < 0.0001 && abs($lng) < 0.0001);
  }
}

SD_Module_OperatorExecutionIntel::register();