<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_RideCompletionService (v0.2)
 *
 * Purpose:
 * - Snapshot final completion metrics onto sd_ride when a ride completes.
 *
 * Canon:
 * - Ride owns completion facts.
 * - Quote may contain historical calculation artifacts, but ride stores the final
 *   operational / tax / performance snapshot.
 *
 * Writes on ride:
 * - SD_Meta::TRIP_MILES
 * - SD_Meta::TRIP_MINUTES
 * - SD_Meta::TOTAL_MILES
 * - SD_Meta::TOTAL_MINUTES
 * - SD_Meta::TOTAL_FARE_CENTS
 * - SD_Meta::TOTAL_CURRENCY
 */

if (class_exists('SD_Module_RideCompletionService', false)) { return; }

final class SD_Module_RideCompletionService {

  public static function register() : void {
    add_action('sd_ride_completed', [__CLASS__, 'snapshot_completion_metrics'], 10, 1);
  }

  public static function snapshot_completion_metrics(int $ride_id) : void {
    if ($ride_id <= 0) return;
    if (get_post_type($ride_id) !== 'sd_ride') return;

    $quote_id = self::latest_quote_id($ride_id);
    $draft    = self::latest_quote_draft($quote_id);

    $trip_miles   = (float) ($draft['ops']['trip_miles'] ?? $draft['derived']['live_trip_miles'] ?? 0.0);
    $trip_minutes = (int)   ($draft['ops']['trip_mins'] ?? $draft['derived']['live_trip_minutes'] ?? 0);

    $miles_to_pickup = (float) ($draft['ops']['miles_to_pickup'] ?? 0.0);
    $pickup_eta_min  = (int)   ($draft['quote']['pickup_eta_min'] ?? 0);

    $total_miles   = round($miles_to_pickup + $trip_miles, 2);
    $total_minutes = max(0, $pickup_eta_min + $trip_minutes);

    $total_fare_cents = (int) ($draft['quote']['total_cents'] ?? 0);
    $currency         = strtolower((string) ($draft['quote']['currency'] ?? 'usd'));
    if ($currency === '') $currency = 'usd';

    update_post_meta($ride_id, SD_Meta::TRIP_MILES, (string) round($trip_miles, 2));
    update_post_meta($ride_id, SD_Meta::TRIP_MINUTES, (string) $trip_minutes);

    update_post_meta($ride_id, SD_Meta::TOTAL_MILES, (string) $total_miles);
    update_post_meta($ride_id, SD_Meta::TOTAL_MINUTES, (string) $total_minutes);

    update_post_meta($ride_id, SD_Meta::TOTAL_FARE_CENTS, (string) $total_fare_cents);
    update_post_meta($ride_id, SD_Meta::TOTAL_CURRENCY, $currency);
  }

  private static function latest_quote_id(int $ride_id) : int {
    $from_ride = 0;
    if (defined('SD_Meta::QUOTE_ID')) {
      $from_ride = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    }
    if ($from_ride > 0 && get_post_type($from_ride) === 'sd_quote') {
      return $from_ride;
    }

    $ids = get_posts([
      'post_type'      => 'sd_quote',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::RIDE_ID,
        'value'   => $ride_id,
        'compare' => '=',
        'type'    => 'NUMERIC',
      ]],
    ]);

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  private static function latest_quote_draft(int $quote_id) : array {
    if ($quote_id <= 0) return [];

    $raw = (string) get_post_meta($quote_id, SD_Meta::P_QUOTE_DRAFT_JSON, true);
    if ($raw === '') return [];

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
  }
}

SD_Module_RideCompletionService::register();