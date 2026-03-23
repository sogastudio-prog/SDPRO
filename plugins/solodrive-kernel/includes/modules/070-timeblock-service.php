<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Canon:
 * - Lead is captured first.
 * - Availability is evaluated after lead creation.
 * - Timeblocks are held against lead, not ride.
 * - No quote should be created until lead has feasible held capacity.
 */
final class SD_Module_TimeblockService {

  private const DEFAULT_BLOCK_MINUTES = 90;
  private const ASAP_BUFFER_MINUTES   = 15;

  public static function register() : void {
    add_action('sd_lead_created', [__CLASS__, 'handle_lead_created'], 20, 3);
  }

  public static function handle_lead_created(int $lead_id, int $tenant_id, array $ctx = []) : void {
    $lead_id   = absint($lead_id);
    $tenant_id = absint($tenant_id);

    if ($lead_id <= 0 || $tenant_id <= 0) {
      return;
    }

    self::evaluate_lead($lead_id, $tenant_id);
  }

  public static function evaluate_lead(int $lead_id, int $tenant_id = 0) : array {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0 || get_post_type($lead_id) !== SD_Module_LeadCPT::CPT) {
      return ['ok' => false, 'error' => 'invalid_lead'];
    }

    $tenant_id = $tenant_id > 0 ? absint($tenant_id) : (int) get_post_meta($lead_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0) {
      return ['ok' => false, 'error' => 'missing_tenant'];
    }

    self::release_existing_holds($lead_id);

    update_post_meta($lead_id, SD_Meta::LEAD_STATUS, 'LEAD_PENDING_AVAILABILITY');
    update_post_meta($lead_id, SD_Meta::AVAILABILITY_STATUS, 'pending');
    update_post_meta($lead_id, SD_Meta::P_STATE_UPDATED_AT, time());

    $window = self::requested_window_for_lead($lead_id);
    if ($window['start_ts'] <= 0 || $window['end_ts'] <= $window['start_ts']) {
      self::mark_unavailable($lead_id, 'invalid_window');
      return ['ok' => false, 'error' => 'invalid_window'];
    }

    $block_ids = SD_TimeBlockRepository::find_open_blocks(
      $tenant_id,
      $window['start_ts'],
      $window['end_ts'],
      10
    );

    if (empty($block_ids)) {
      self::mark_unavailable($lead_id, 'no_open_blocks');
      return ['ok' => false, 'error' => 'no_open_blocks'];
    }

    $held_ids = [];
    foreach ($block_ids as $block_id) {
      $block_start = (int) get_post_meta($block_id, SD_Meta::TIMEBLOCK_START_TS, true);
      $block_end   = (int) get_post_meta($block_id, SD_Meta::TIMEBLOCK_END_TS, true);

      if ($block_start > $window['start_ts']) {
        continue;
      }
      if ($block_end < $window['end_ts']) {
        continue;
      }

      if (SD_TimeBlockRepository::hold_block((int) $block_id, $lead_id)) {
        $held_ids[] = (int) $block_id;
        break; // v1: one covering block is enough
      }
    }

    if (empty($held_ids)) {
      self::mark_unavailable($lead_id, 'no_covering_block');
      return ['ok' => false, 'error' => 'no_covering_block'];
    }

    update_post_meta($lead_id, SD_Meta::TIMEBLOCK_IDS_JSON, wp_json_encode(array_values($held_ids)));
    update_post_meta($lead_id, SD_Meta::TIMEBLOCK_STATUS, 'HELD');
    update_post_meta($lead_id, SD_Meta::AVAILABILITY_STATUS, 'available');
    update_post_meta($lead_id, SD_Meta::LEAD_STATUS, 'LEAD_AVAILABLE');
    update_post_meta($lead_id, SD_Meta::P_STATE_UPDATED_AT, time());

    do_action('sd_lead_available', $lead_id, $tenant_id, [
      'block_ids' => $held_ids,
      'window'    => $window,
    ]);

    return [
      'ok'       => true,
      'lead_id'  => $lead_id,
      'block_ids'=> $held_ids,
      'window'   => $window,
    ];
  }

  public static function release_existing_holds(int $lead_id) : void {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) {
      return;
    }

    $block_ids = SD_TimeBlockRepository::find_blocks_for_lead($lead_id, ['HELD']);
    foreach ($block_ids as $block_id) {
      SD_TimeBlockRepository::release_block((int) $block_id);
    }

    delete_post_meta($lead_id, SD_Meta::TIMEBLOCK_IDS_JSON);
    delete_post_meta($lead_id, SD_Meta::TIMEBLOCK_STATUS);
  }

  public static function commit_for_ride(int $lead_id, int $ride_id) : bool {
    $lead_id = absint($lead_id);
    $ride_id = absint($ride_id);

    if ($lead_id <= 0 || $ride_id <= 0) {
      return false;
    }

    $block_ids = SD_TimeBlockRepository::find_blocks_for_lead($lead_id, ['HELD']);
    if (empty($block_ids)) {
      return false;
    }

    $ok = true;
    foreach ($block_ids as $block_id) {
      if (!SD_TimeBlockRepository::commit_block((int) $block_id, $lead_id, $ride_id)) {
        $ok = false;
      }
    }

    if ($ok) {
      update_post_meta($lead_id, SD_Meta::TIMEBLOCK_STATUS, 'COMMITTED');
    }

    return $ok;
  }

  private static function requested_window_for_lead(int $lead_id) : array {
    $mode         = strtoupper((string) get_post_meta($lead_id, SD_Meta::REQUEST_MODE, true));
    $requested_ts = (int) get_post_meta($lead_id, SD_Meta::REQUESTED_TS, true);
    $pickup_ts    = (int) get_post_meta($lead_id, SD_Meta::PICKUP_SCHEDULED_TS, true);

    $start_ts = 0;
    if ($mode === 'RESERVE') {
      $start_ts = $pickup_ts > 0 ? $pickup_ts : $requested_ts;
    } else {
      $start_ts = current_time('timestamp') + (self::ASAP_BUFFER_MINUTES * MINUTE_IN_SECONDS);
    }

    if ($start_ts <= 0) {
      $start_ts = current_time('timestamp') + (self::ASAP_BUFFER_MINUTES * MINUTE_IN_SECONDS);
    }

    $duration_minutes = self::estimate_duration_minutes($lead_id);
    $end_ts = $start_ts + ($duration_minutes * MINUTE_IN_SECONDS);

    return [
      'start_ts'         => $start_ts,
      'end_ts'           => $end_ts,
      'duration_minutes' => $duration_minutes,
      'mode'             => ($mode === 'RESERVE' ? 'RESERVE' : 'ASAP'),
    ];
  }

  private static function estimate_duration_minutes(int $lead_id) : int {
    $route_seconds = (int) get_post_meta($lead_id, SD_Meta::ROUTE_SECONDS, true);
    if ($route_seconds > 0) {
      $minutes = (int) ceil($route_seconds / 60);
      $minutes += 30; // buffer for deadhead/load/unload in v1
      return max(30, $minutes);
    }

    return self::DEFAULT_BLOCK_MINUTES;
  }

  private static function mark_unavailable(int $lead_id, string $reason) : void {
    update_post_meta($lead_id, SD_Meta::TIMEBLOCK_STATUS, 'UNAVAILABLE');
    update_post_meta($lead_id, SD_Meta::AVAILABILITY_STATUS, 'unavailable');
    update_post_meta($lead_id, SD_Meta::LEAD_STATUS, 'LEAD_UNAVAILABLE');
    update_post_meta($lead_id, SD_Meta::P_STATE_UPDATED_AT, time());
    update_post_meta($lead_id, SD_Meta::P_AVAILABILITY_REASON, sanitize_key($reason));
  }
}