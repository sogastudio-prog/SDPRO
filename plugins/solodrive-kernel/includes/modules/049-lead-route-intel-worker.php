<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_LeadRouteIntelWorker
 *
 * Purpose:
 * - Perform the real route-intel job for a lead
 * - Compute canonical route meters/seconds from lead pickup -> dropoff
 * - Advance the lead from LEAD_NEEDS_ROUTE_INTEL to LEAD_NEEDS_TIMEBLOCK
 *
 * Canon:
 * - Lead is the orchestration root
 * - This worker does one job only: route intel
 * - This worker does NOT create quotes, attempts, or rides
 * - Stage engine remains the only orchestrator
 *
 * Requirements:
 * - Runs only when current stage is LEAD_NEEDS_ROUTE_INTEL
 * - Idempotent
 * - Concurrency-safe
 * - Writes route output back to the lead
 */

if (class_exists('SD_Module_LeadRouteIntelWorker', false)) { return; }

final class SD_Module_LeadRouteIntelWorker {

  private const JOB_STATE_KEY = '_sd_route_job_state';

  public static function register() : void {
    add_action('sd_core_stage_LEAD_NEEDS_ROUTE_INTEL', [__CLASS__, 'handle_stage'], 10, 3);
  }

  /**
   * Hook signature from SD_CoreStage:
   * do_action('sd_core_stage_' . $stage, $lead_id, $from_stage, $reason);
   */
  public static function handle_stage($lead_id, $from_stage = '', $reason = '') : void {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) return;
    if (get_post_type($lead_id) !== SD_Module_LeadCPT::CPT) return;

    if (!class_exists('SD_CoreStage', false)) return;

    // Hard stage guard.
    if (SD_CoreStage::current_stage($lead_id) !== SD_CoreStage::LEAD_NEEDS_ROUTE_INTEL) {
      return;
    }

    // Idempotency guard: if usable route intel already exists, just advance.
    if (self::has_route_intel($lead_id)) {
      delete_post_meta($lead_id, SD_Meta::P_ROUTE_ERROR);
      update_post_meta($lead_id, self::JOB_STATE_KEY, 'ok');

      SD_CoreStage::advance(
        $lead_id,
        SD_CoreStage::LEAD_NEEDS_TIMEBLOCK,
        'Route intel already exists.'
      );
      return;
    }

    // Concurrency guard.
    $job_state = (string) get_post_meta($lead_id, self::JOB_STATE_KEY, true);
    if ($job_state === 'running') {
      return;
    }

    update_post_meta($lead_id, self::JOB_STATE_KEY, 'running');
    delete_post_meta($lead_id, SD_Meta::P_ROUTE_ERROR);

    try {
      if (!class_exists('SD_Route_Service')) {
        throw new \Exception('Route service unavailable.');
      }

      $tenant_id = absint(get_post_meta($lead_id, SD_Meta::TENANT_ID, true));
      if ($tenant_id <= 0) {
        throw new \Exception('Missing tenant_id on lead.');
      }

      $origin = self::lead_pickup_point($lead_id);
      $dest   = self::lead_dropoff_point($lead_id);

      if (!$origin || !$dest) {
        throw new \Exception('Lead pickup/dropoff route points missing.');
      }

      $leg = SD_Route_Service::compute_leg($origin, $dest, [
        'tenant_id' => $tenant_id,
        'timeout'   => 8,
      ]);

      if (!is_array($leg) || empty($leg)) {
        throw new \Exception('Route compute failed.');
      }

      $meters  = isset($leg['meters'])  ? absint($leg['meters'])  : 0;
      $seconds = isset($leg['seconds']) ? absint($leg['seconds']) : 0;

      if ($meters <= 0 && $seconds <= 0) {
        throw new \Exception('Route compute returned no usable distance or duration.');
      }

      update_post_meta($lead_id, SD_Meta::ROUTE_METERS, $meters);
      update_post_meta($lead_id, SD_Meta::ROUTE_SECONDS, $seconds);

      if (isset($leg['polyline']) && is_scalar($leg['polyline']) && trim((string) $leg['polyline']) !== '') {
        update_post_meta($lead_id, SD_Meta::P_ROUTE_POLYLINE, trim((string) $leg['polyline']));
      }

      if (isset($leg['provider']) && is_scalar($leg['provider']) && trim((string) $leg['provider']) !== '') {
        update_post_meta($lead_id, SD_Meta::P_ROUTE_PROVIDER, trim((string) $leg['provider']));
      }

      update_post_meta($lead_id, self::JOB_STATE_KEY, 'ok');
      update_post_meta($lead_id, SD_Meta::P_ROUTE_COMPUTED_AT, time());

      SD_CoreStage::advance(
        $lead_id,
        SD_CoreStage::LEAD_NEEDS_TIMEBLOCK,
        'Route intel computed.'
      );

    } catch (\Throwable $e) {
      update_post_meta($lead_id, self::JOB_STATE_KEY, 'error');
      update_post_meta($lead_id, SD_Meta::P_ROUTE_ERROR, $e->getMessage());
    }
  }

  private static function has_route_intel(int $lead_id) : bool {
    $meters  = absint(get_post_meta($lead_id, SD_Meta::ROUTE_METERS, true));
    $seconds = absint(get_post_meta($lead_id, SD_Meta::ROUTE_SECONDS, true));
    $error   = trim((string) get_post_meta($lead_id, SD_Meta::P_ROUTE_ERROR, true));
    $state   = trim((string) get_post_meta($lead_id, self::JOB_STATE_KEY, true));

    if ($state === 'error' || $error !== '') {
      return false;
    }

    return ($meters > 0 || $seconds > 0);
  }

  private static function lead_pickup_point(int $lead_id) : ?array {
    $place_id = trim((string) get_post_meta($lead_id, SD_Meta::PICKUP_PLACE_ID, true));
    if ($place_id !== '') {
      return ['place_id' => $place_id];
    }

    $lat = (float) get_post_meta($lead_id, SD_Meta::PICKUP_LAT, true);
    $lng = (float) get_post_meta($lead_id, SD_Meta::PICKUP_LNG, true);

    if (abs($lat) > 0.0001 && abs($lng) > 0.0001) {
      return ['lat' => $lat, 'lng' => $lng];
    }

    return null;
  }

  private static function lead_dropoff_point(int $lead_id) : ?array {
    $place_id = trim((string) get_post_meta($lead_id, SD_Meta::DROPOFF_PLACE_ID, true));
    if ($place_id !== '') {
      return ['place_id' => $place_id];
    }

    $lat = (float) get_post_meta($lead_id, SD_Meta::DROPOFF_LAT, true);
    $lng = (float) get_post_meta($lead_id, SD_Meta::DROPOFF_LNG, true);

    if (abs($lat) > 0.0001 && abs($lng) > 0.0001) {
      return ['lat' => $lat, 'lng' => $lng];
    }

    return null;
  }
}