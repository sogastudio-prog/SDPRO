<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_TimeSpaceLedger {

  public static function write(array $data) : int {

    $tenant_id = absint($data['tenant_id'] ?? 0);
    if ($tenant_id <= 0) return 0;

    $post_id = wp_insert_post([
      'post_type'   => SD_Module_TimeSpaceEventCPT::CPT,
      'post_status' => 'publish',
      'post_title'  => $data['event_type'] . ' @ ' . gmdate('Y-m-d H:i:s'),
    ], true);

    if (is_wp_error($post_id) || !$post_id) return 0;

    $post_id = (int) $post_id;

    // Required
    update_post_meta($post_id, SD_Meta::TENANT_ID, $tenant_id);
    update_post_meta($post_id, SD_Meta::TS_EVENT_TYPE, $data['event_type']);

    // Time
    update_post_meta($post_id, SD_Meta::TS_START_TS, $data['start_ts'] ?? time());

    if (!empty($data['end_ts'])) {
      update_post_meta($post_id, SD_Meta::TS_END_TS, $data['end_ts']);
    }

    // Location
    if (isset($data['start_lat'])) {
      update_post_meta($post_id, SD_Meta::TS_START_LAT, $data['start_lat']);
      update_post_meta($post_id, SD_Meta::TS_START_LNG, $data['start_lng']);
    }

    if (isset($data['end_lat'])) {
      update_post_meta($post_id, SD_Meta::TS_END_LAT, $data['end_lat']);
      update_post_meta($post_id, SD_Meta::TS_END_LNG, $data['end_lng']);
    }

    // Relationships
    if (!empty($data['driver_id'])) {
      update_post_meta($post_id, SD_Meta::TS_DRIVER_ID, $data['driver_id']);
    }

    if (!empty($data['lead_id'])) {
      update_post_meta($post_id, SD_Meta::TS_LEAD_ID, $data['lead_id']);
    }

    if (!empty($data['ride_id'])) {
      update_post_meta($post_id, SD_Meta::TS_RIDE_ID, $data['ride_id']);
    }

    return $post_id;
  }
}