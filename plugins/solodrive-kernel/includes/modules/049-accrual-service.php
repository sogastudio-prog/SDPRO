<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Accrual_Service
 *
 * Purpose:
 * - Track locked (earned) miles, minutes, and fare
 * - Provide snapshot before any modification or cancellation
 *
 * Canon:
 * - Locked revenue can only increase
 * - Locked revenue is never reduced
 * - Locked revenue represents completed work
 */

if (class_exists('SD_Accrual_Service', false)) { return; }

final class SD_Accrual_Service {

  /**
   * Snapshot current progress into locked revenue.
   *
   * Safe to call multiple times.
   * Will only increase values.
   */
  public static function snapshot(int $ride_id) : void {
    $ride_id = absint($ride_id);
    if ($ride_id <= 0) return;
    if (get_post_type($ride_id) !== SD_Meta::RIDE_CPT) return;

    $current = self::get_current_progress($ride_id);
    if (!$current['ok']) return;

    $existing = self::get_locked($ride_id);

    $locked_miles   = max($existing['miles'], $current['miles']);
    $locked_minutes = max($existing['minutes'], $current['minutes']);
    $locked_cents   = max($existing['fare_cents'], $current['fare_cents']);

    update_post_meta($ride_id, SD_Meta::LOCKED_MILES, $locked_miles);
    update_post_meta($ride_id, SD_Meta::LOCKED_MINUTES, $locked_minutes);
    update_post_meta($ride_id, SD_Meta::LOCKED_FARE_CENTS, $locked_cents);
    update_post_meta($ride_id, SD_Meta::LOCKED_UPDATED_AT, time());
  }

  /**
   * Get locked revenue snapshot.
   */
  public static function get_locked(int $ride_id) : array {
    return [
      'miles'      => (float) get_post_meta($ride_id, SD_Meta::LOCKED_MILES, true),
      'minutes'    => (float) get_post_meta($ride_id, SD_Meta::LOCKED_MINUTES, true),
      'fare_cents' => (int) get_post_meta($ride_id, SD_Meta::LOCKED_FARE_CENTS, true),
    ];
  }

  /**
   * Compute current progress.
   *
   * v1: Uses route meters/seconds if available
   * (later can be replaced with GPS/polyline tracking)
   */
  private static function get_current_progress(int $ride_id) : array {

    $meters  = (int) get_post_meta($ride_id, SD_Meta::ROUTE_METERS, true);
    $seconds = (int) get_post_meta($ride_id, SD_Meta::ROUTE_SECONDS, true);

    if ($meters <= 0 && $seconds <= 0) {
      return ['ok' => false];
    }

    $miles   = $meters > 0 ? ($meters / 1609.344) : 0.0;
    $minutes = $seconds > 0 ? ($seconds / 60.0)   : 0.0;

    $tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);

    $per_mile   = self::tenant_rate($tenant_id, SD_Meta::PER_MILE_RATE);
    $per_minute = self::tenant_rate($tenant_id, SD_Meta::PER_MINUTE_RATE);

    $fare = ($miles * $per_mile) + ($minutes * $per_minute);

    return [
      'ok'         => true,
      'miles'      => round($miles, 2),
      'minutes'    => round($minutes, 1),
      'fare_cents' => (int) round($fare * 100),
    ];
  }

  private static function tenant_rate(int $tenant_id, string $key) : float {
    $v = get_post_meta($tenant_id, $key, true);
    return is_numeric($v) ? (float) $v : 0.0;
  }
}