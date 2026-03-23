<?php
if (!defined('ABSPATH')) { exit; }

final class SD_ServiceBlockBuilder {

  public static function build_from_ride(int $ride_id) : array {

    $tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);

    $request_mode = get_post_meta($ride_id, 'sd_ride_mode', true);
    $requested_ts = (int) get_post_meta($ride_id, 'sd_requested_ts', true);

    if ($requested_ts <= 0) {
      $requested_ts = time();
    }

    // -----------------------------------------------------------------------
    // V1: use existing route estimates (already stored)
    // -----------------------------------------------------------------------

    $route_seconds = (int) get_post_meta($ride_id, 'sd_route_seconds', true);
    if ($route_seconds <= 0) {
      $route_seconds = 900; // fallback 15 min
    }

    // Simple buffer (tenant configurable later)
    $buffer_seconds = 300; // 5 minutes

    // -----------------------------------------------------------------------
    // Build block
    // -----------------------------------------------------------------------

    $start_ts = $requested_ts;
    $end_ts   = $start_ts + $route_seconds + $buffer_seconds;

    return [
      'tenant_id' => $tenant_id,
      'start_ts'  => $start_ts,
      'end_ts'    => $end_ts,
      'duration'  => $end_ts - $start_ts,
    ];
  }
}