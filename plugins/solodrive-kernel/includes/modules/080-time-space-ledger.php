<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_TimeSpaceLedger {

  public static function write(array $data) : int {
    $tenant_id  = absint($data['tenant_id'] ?? 0);
    $event_type = isset($data['event_type']) ? (string) $data['event_type'] : '';

    if ($tenant_id <= 0 || $event_type === '') {
      return 0;
    }

    if (!in_array($event_type, SD_TimeSpace_EventType::all(), true)) {
      return 0;
    }

    $start_ts = !empty($data['start_ts']) ? (int) $data['start_ts'] : time();
    $end_ts   = !empty($data['end_ts']) ? (int) $data['end_ts'] : 0;

    $has_start_coords = isset($data['start_lat']) && isset($data['start_lng']);
    $has_end_coords   = isset($data['end_lat']) && isset($data['end_lng']);

    $post_id = wp_insert_post([
      'post_type'   => SD_Module_TimeSpaceEventCPT::CPT,
      'post_status' => 'publish',
      'post_title'  => $event_type . ' @ ' . gmdate('Y-m-d H:i:s', $start_ts),
    ], true);

    if (is_wp_error($post_id) || !$post_id) {
      return 0;
    }

    $post_id = (int) $post_id;

    update_post_meta($post_id, SD_Meta::TENANT_ID, $tenant_id);
    update_post_meta($post_id, SD_Meta::TS_EVENT_TYPE, $event_type);
    update_post_meta($post_id, SD_Meta::TS_START_TS, $start_ts);

    if ($end_ts > 0) {
      update_post_meta($post_id, SD_Meta::TS_END_TS, $end_ts);
    }

    if ($has_start_coords) {
      update_post_meta($post_id, SD_Meta::TS_START_LAT, (float) $data['start_lat']);
      update_post_meta($post_id, SD_Meta::TS_START_LNG, (float) $data['start_lng']);
    }

    if ($has_end_coords) {
      update_post_meta($post_id, SD_Meta::TS_END_LAT, (float) $data['end_lat']);
      update_post_meta($post_id, SD_Meta::TS_END_LNG, (float) $data['end_lng']);
    }

    if (!empty($data['driver_id'])) {
      update_post_meta($post_id, SD_Meta::TS_DRIVER_ID, absint($data['driver_id']));
    }

    if (!empty($data['lead_id'])) {
      update_post_meta($post_id, SD_Meta::TS_LEAD_ID, absint($data['lead_id']));
    }

    if (!empty($data['ride_id'])) {
      update_post_meta($post_id, SD_Meta::TS_RIDE_ID, absint($data['ride_id']));
    }

    if (!empty($data['quote_id']) && defined('SD_Meta::TS_QUOTE_ID')) {
      update_post_meta($post_id, SD_Meta::TS_QUOTE_ID, absint($data['quote_id']));
    }

    if (!empty($data['attempt_id']) && defined('SD_Meta::TS_ATTEMPT_ID')) {
      update_post_meta($post_id, SD_Meta::TS_ATTEMPT_ID, absint($data['attempt_id']));
    }

    return $post_id;
  }
}