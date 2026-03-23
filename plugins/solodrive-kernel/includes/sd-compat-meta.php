<?php
if (!defined('ABSPATH')) { exit; }

if (class_exists('SD_Compat_Meta', false)) { return; }

/**
 * SD_Compat_Meta
 *
 * Transitional helper:
 * - Reads canon first, falls back to legacy.
 * - Writes canon only.
 * - Optionally backfills canon from legacy on read.
 *
 * Goal: allow incremental refactor of old modules that still reference sog_* / state.
 */
final class SD_Compat_Meta {

  // Legacy → canon key map
  private const MAP = [
    // ride
    'sog_trip_token'            => 'sd_trip_token',
    'sog_lead_status'           => 'sd_lead_status',
    'state'                     => 'sd_ride_state',
    'sog_pickup_text'           => 'sd_pickup_text',
    'sog_dropoff_text'          => 'sd_dropoff_text',
    'sog_route_meters'          => 'sd_route_meters',
    'sog_route_seconds'         => 'sd_route_seconds',
    'sog_pickup_scheduled_ts'   => 'sd_pickup_scheduled_ts',

    // quote
    'sog_ride_id'               => 'sd_ride_id',
    'sog_quote_status'          => 'sd_quote_status',
  ];

  // Legacy quote values → canon quote values
  private const QUOTE_STATUS_MAP = [
    'QUOTE_PROPOSED'        => 'PROPOSED',
    'QUOTE_APPROVED'        => 'APPROVED',
    'QUOTE_PRESENTED'       => 'PRESENTED',
    'QUOTE_USER_REJECTED'   => 'USER_REJECTED',
    'QUOTE_USER_TIMEOUT'    => 'USER_TIMEOUT',
    'QUOTE_LEAD_ACCEPTED'   => 'LEAD_ACCEPTED',
    'QUOTE_PAYMENT_PENDING' => 'PAYMENT_PENDING',
    'QUOTE_LEAD_REJECTED'   => 'LEAD_REJECTED',
    'QUOTE_EXPIRED'         => 'EXPIRED',
    'QUOTE_SUPERSEDED'      => 'SUPERSEDED',
    'QUOTE_CANCELLED'       => 'CANCELLED',
    'QUOTE_CAPTURED'        => 'CAPTURED', // optional; consider moving to attempt audit instead
  ];

  public static function canon_key(string $key) : string {
    return self::MAP[$key] ?? $key;
  }

  public static function get(int $post_id, string $key, bool $single = true, bool $backfill = true) {
    $canon = self::canon_key($key);

    $val = get_post_meta($post_id, $canon, $single);
    if ($val !== '' && $val !== [] && $val !== null) return $val;

    // fallback to legacy if a mapped key
    if ($canon !== $key) {
      $legacy_val = get_post_meta($post_id, $key, $single);
      if ($backfill && ($legacy_val !== '' && $legacy_val !== [] && $legacy_val !== null)) {
        $normalized = self::normalize_value($canon, $legacy_val);
        update_post_meta($post_id, $canon, $normalized);
      }
      return $legacy_val;
    }

    return $val;
  }

  public static function set(int $post_id, string $key, $value) : void {
    $canon = self::canon_key($key);
    $normalized = self::normalize_value($canon, $value);
    update_post_meta($post_id, $canon, $normalized);
  }

  private static function normalize_value(string $canon_key, $value) {
    if ($canon_key === 'sd_quote_status' && is_string($value)) {
      return self::QUOTE_STATUS_MAP[$value] ?? $value;
    }
    if ($canon_key === 'sd_ride_state' && $value === 'REQUEST_SUBMITTED') {
      // Do not persist non-canon state.
      return 'RIDE_QUEUED';
    }
    return $value;
  }
}