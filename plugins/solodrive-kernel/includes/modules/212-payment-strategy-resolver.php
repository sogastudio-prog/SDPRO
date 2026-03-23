<?php
if (!defined('ABSPATH')) { exit; }

final class SD_PaymentStrategyResolver {

  public static function resolve_for_ride(int $ride_id) : string {
    $ride_mode        = (string) get_post_meta($ride_id, 'sd_ride_mode', true);
    $service_start_ts = (int) get_post_meta($ride_id, 'sd_service_start_ts', true);

    if ($ride_mode === 'RESERVED') {
      $now = time();

      // safe window under auth-expiry complexity
      if ($service_start_ts > 0 && ($service_start_ts - $now) <= 5 * DAY_IN_SECONDS) {
        return 'AUTHORIZE_LATER';
      }

      return 'SAVE_ONLY';
    }

    return 'IMMEDIATE_AUTHORIZE';
  }
}