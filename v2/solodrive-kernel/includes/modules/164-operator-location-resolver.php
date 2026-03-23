<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorLocationResolver
 *
 * Purpose:
 * - Resolve the effective operator location for a tenant.
 * - Prefer fresh live operator telemetry.
 * - Fall back to tenant base location.
 *
 * Canon:
 * - v1a is owner/operator only.
 * - This is a resolver service only. No UI. No lifecycle writes.
 * - Public surfaces may consume this output, but this module does not render.
 */

if (class_exists('SD_Module_OperatorLocationResolver', false)) { return; }

final class SD_Module_OperatorLocationResolver {

  private const LIVE_FRESH_SECONDS = 120;

  public static function register() : void {
    // Pure service module.
  }

  /**
   * Return the effective operator location for this tenant.
   *
   * Shape:
   * [
   *   'ok'         => bool,
   *   'source'     => 'live'|'base'|'none',
   *   'lat'        => float,
   *   'lng'        => float,
   *   'ts'         => int,
   *   'accuracy_m' => float,
   *   'label'      => string,
   *   'fresh'      => bool,
   * ]
   */
  public static function resolve_for_tenant(int $tenant_id) : array {
    if ($tenant_id <= 0) {
      return self::none();
    }

    $live = self::live_location($tenant_id);
    if (!empty($live['ok'])) {
      return $live;
    }

    $base = self::base_location($tenant_id);
    if (!empty($base['ok'])) {
      return $base;
    }

    return self::none();
  }

  private static function live_location(int $tenant_id) : array {
    $lat = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LAT, true);
    $lng = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LNG, true);
    $ts  = (int) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_TS, true);
    $acc = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_ACCURACY_M, true);

    if (!self::valid_latlng($lat, $lng) || $ts <= 0) {
      return self::none();
    }

    $fresh = ((time() - $ts) <= self::LIVE_FRESH_SECONDS);

    if (!$fresh) {
      return self::none();
    }

    return [
      'ok'         => true,
      'source'     => 'live',
      'lat'        => $lat,
      'lng'        => $lng,
      'ts'         => $ts,
      'accuracy_m' => $acc > 0 ? $acc : 0.0,
      'label'      => 'Live operator location',
      'fresh'      => true,
    ];
  }

  private static function base_location(int $tenant_id) : array {
    $lat   = (float) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LAT, true);
    $lng   = (float) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LNG, true);
    $label = trim((string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LABEL, true));

    if (!self::valid_latlng($lat, $lng)) {
      return self::none();
    }

    return [
      'ok'         => true,
      'source'     => 'base',
      'lat'        => $lat,
      'lng'        => $lng,
      'ts'         => 0,
      'accuracy_m' => 0.0,
      'label'      => ($label !== '' ? $label : 'Base location'),
      'fresh'      => false,
    ];
  }

  private static function valid_latlng(float $lat, float $lng) : bool {
    return ($lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0)
      && !(abs($lat) < 0.0001 && abs($lng) < 0.0001);
  }

  private static function none() : array {
    return [
      'ok'         => false,
      'source'     => 'none',
      'lat'        => 0.0,
      'lng'        => 0.0,
      'ts'         => 0,
      'accuracy_m' => 0.0,
      'label'      => 'Location unavailable',
      'fresh'      => false,
    ];
  }
}

SD_Module_OperatorLocationResolver::register();