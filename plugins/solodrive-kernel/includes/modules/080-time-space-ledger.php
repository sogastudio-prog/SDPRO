<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_TimeSpaceLedger {

  public const TRUTH_OBSERVED  = 'OBSERVED';
  public const TRUTH_PROJECTED = 'PROJECTED';
  public const TRUTH_COMMITTED = 'COMMITTED';

  public const SUBJECT_DRIVER  = 'driver';
  public const SUBJECT_LEAD    = 'lead';
  public const SUBJECT_QUOTE   = 'quote';
  public const SUBJECT_RIDE    = 'ride';
  public const SUBJECT_ATTEMPT = 'attempt';
  public const SUBJECT_TENANT  = 'tenant';

  public const ACTOR_SYSTEM   = 'system';
  public const ACTOR_DRIVER   = 'driver';
  public const ACTOR_OPERATOR = 'operator';
  public const ACTOR_RIDER    = 'rider';

  public static function write(array $data) : int {
    $tenant_id   = absint($data['tenant_id'] ?? 0);
    $event_type  = isset($data['event_type']) ? strtoupper(trim((string) $data['event_type'])) : '';
    $truth_class = isset($data['truth_class']) ? strtoupper(trim((string) $data['truth_class'])) : '';

    if ($tenant_id <= 0 || $event_type === '' || $truth_class === '') {
      return 0;
    }

    if (!in_array($event_type, SD_TimeSpace_EventType::all(), true)) {
      return 0;
    }

    if (!in_array($truth_class, self::truth_classes(), true)) {
      return 0;
    }

    $subject_type = isset($data['subject_type']) ? strtolower(trim((string) $data['subject_type'])) : '';
    $subject_id   = absint($data['subject_id'] ?? 0);

    if ($subject_type === '' || !in_array($subject_type, self::subject_types(), true)) {
      return 0;
    }

    if ($subject_id <= 0) {
      return 0;
    }

    $actor_type = isset($data['actor_type']) ? strtolower(trim((string) $data['actor_type'])) : self::ACTOR_SYSTEM;
    $actor_id   = absint($data['actor_id'] ?? 0);

    if (!in_array($actor_type, self::actor_types(), true)) {
      return 0;
    }

    if ($actor_type === self::ACTOR_SYSTEM) {
      $actor_id = 0;
    }

    $start_ts = !empty($data['start_ts']) ? (int) $data['start_ts'] : time();
    $end_ts   = !empty($data['end_ts']) ? (int) $data['end_ts'] : 0;

    if ($start_ts <= 0) {
      return 0;
    }

    if ($end_ts > 0 && $end_ts < $start_ts) {
      return 0;
    }

    $has_start_lat = array_key_exists('start_lat', $data);
    $has_start_lng = array_key_exists('start_lng', $data);
    $has_end_lat   = array_key_exists('end_lat', $data);
    $has_end_lng   = array_key_exists('end_lng', $data);

    if ($has_start_lat !== $has_start_lng) {
      return 0;
    }

    if ($has_end_lat !== $has_end_lng) {
      return 0;
    }

    $start_lat = $has_start_lat ? (float) $data['start_lat'] : null;
    $start_lng = $has_start_lng ? (float) $data['start_lng'] : null;
    $end_lat   = $has_end_lat ? (float) $data['end_lat'] : null;
    $end_lng   = $has_end_lng ? (float) $data['end_lng'] : null;

    if ($has_start_lat && !self::valid_coords($start_lat, $start_lng)) {
      return 0;
    }

    if ($has_end_lat && !self::valid_coords($end_lat, $end_lng)) {
      return 0;
    }

    $driver_id  = absint($data['driver_id'] ?? 0);
    $lead_id    = absint($data['lead_id'] ?? 0);
    $ride_id    = absint($data['ride_id'] ?? 0);
    $quote_id   = absint($data['quote_id'] ?? 0);
    $attempt_id = absint($data['attempt_id'] ?? 0);

    $payload_json = self::normalize_payload_json($data['payload_json'] ?? ($data['payload'] ?? null));

    $title_parts = [$event_type];

    if ($lead_id > 0) {
      $title_parts[] = 'lead#' . $lead_id;
    } elseif ($driver_id > 0) {
      $title_parts[] = 'driver#' . $driver_id;
    } elseif ($ride_id > 0) {
      $title_parts[] = 'ride#' . $ride_id;
    } else {
      $title_parts[] = $subject_type . '#' . $subject_id;
    }

    $title_parts[] = gmdate('Y-m-d H:i:s', $start_ts);

    $post_id = wp_insert_post([
      'post_type'   => SD_Module_TimeSpaceEventCPT::CPT,
      'post_status' => 'publish',
      'post_title'  => implode(' @ ', [
        implode(' ', array_slice($title_parts, 0, count($title_parts) - 1)),
        end($title_parts),
      ]),
    ], true);

    if (is_wp_error($post_id) || !$post_id) {
      return 0;
    }

    $post_id = (int) $post_id;

    // Required
    update_post_meta($post_id, SD_Meta::TENANT_ID, $tenant_id);
    update_post_meta($post_id, SD_Meta::TS_EVENT_TYPE, $event_type);
    update_post_meta($post_id, SD_Meta::TS_TRUTH_CLASS, $truth_class);
    update_post_meta($post_id, SD_Meta::TS_START_TS, $start_ts);
    update_post_meta($post_id, SD_Meta::TS_SUBJECT_TYPE, $subject_type);
    update_post_meta($post_id, SD_Meta::TS_SUBJECT_ID, $subject_id);
    update_post_meta($post_id, SD_Meta::TS_ACTOR_TYPE, $actor_type);
    update_post_meta($post_id, SD_Meta::TS_ACTOR_ID, $actor_id);

    // Optional time
    if ($end_ts > 0) {
      update_post_meta($post_id, SD_Meta::TS_END_TS, $end_ts);
    }

    // Optional location
    if ($has_start_lat) {
      update_post_meta($post_id, SD_Meta::TS_START_LAT, $start_lat);
      update_post_meta($post_id, SD_Meta::TS_START_LNG, $start_lng);
    }

    if ($has_end_lat) {
      update_post_meta($post_id, SD_Meta::TS_END_LAT, $end_lat);
      update_post_meta($post_id, SD_Meta::TS_END_LNG, $end_lng);
    }

    // Relationships
    if ($driver_id > 0) {
      update_post_meta($post_id, SD_Meta::TS_DRIVER_ID, $driver_id);
    }

    if ($lead_id > 0) {
      update_post_meta($post_id, SD_Meta::TS_LEAD_ID, $lead_id);
    }

    if ($ride_id > 0) {
      update_post_meta($post_id, SD_Meta::TS_RIDE_ID, $ride_id);
    }

    if ($quote_id > 0 && defined('SD_Meta::TS_QUOTE_ID')) {
      update_post_meta($post_id, constant('SD_Meta::TS_QUOTE_ID'), $quote_id);
    }

    if ($attempt_id > 0 && defined('SD_Meta::TS_ATTEMPT_ID')) {
      update_post_meta($post_id, constant('SD_Meta::TS_ATTEMPT_ID'), $attempt_id);
    }

    if ($payload_json !== '') {
      update_post_meta($post_id, SD_Meta::TS_PAYLOAD_JSON, $payload_json);
    }

    if (class_exists('SD_Util')) {
      SD_Util::log('time_space_ledger_written', [
        'post_id'      => $post_id,
        'tenant_id'    => $tenant_id,
        'event_type'   => $event_type,
        'truth_class'  => $truth_class,
        'subject_type' => $subject_type,
        'subject_id'   => $subject_id,
        'lead_id'      => $lead_id,
        'ride_id'      => $ride_id,
        'driver_id'    => $driver_id,
      ]);
    }

    // Truth classification
if (!empty($data['truth_class'])) {
  update_post_meta($post_id, SD_Meta::TS_TRUTH_CLASS, sanitize_text_field($data['truth_class']));
}

// Subject
if (!empty($data['subject_type'])) {
  update_post_meta($post_id, SD_Meta::TS_SUBJECT_TYPE, sanitize_text_field($data['subject_type']));
}
if (!empty($data['subject_id'])) {
  update_post_meta($post_id, SD_Meta::TS_SUBJECT_ID, absint($data['subject_id']));
}

// Actor
if (!empty($data['actor_type'])) {
  update_post_meta($post_id, SD_Meta::TS_ACTOR_TYPE, sanitize_text_field($data['actor_type']));
}
if (!empty($data['actor_id'])) {
  update_post_meta($post_id, SD_Meta::TS_ACTOR_ID, absint($data['actor_id']));
}

// Payload
if (!empty($data['payload']) && is_array($data['payload'])) {
  update_post_meta(
    $post_id,
    SD_Meta::TS_PAYLOAD_JSON,
    wp_json_encode($data['payload'])
  );
}

    return $post_id;
  }

  public static function truth_classes() : array {
    return [
      self::TRUTH_OBSERVED,
      self::TRUTH_PROJECTED,
      self::TRUTH_COMMITTED,
    ];
  }

  public static function subject_types() : array {
    return [
      self::SUBJECT_DRIVER,
      self::SUBJECT_LEAD,
      self::SUBJECT_QUOTE,
      self::SUBJECT_RIDE,
      self::SUBJECT_ATTEMPT,
      self::SUBJECT_TENANT,
    ];
  }

  public static function actor_types() : array {
    return [
      self::ACTOR_SYSTEM,
      self::ACTOR_DRIVER,
      self::ACTOR_OPERATOR,
      self::ACTOR_RIDER,
    ];
  }

  private static function valid_coords(float $lat, float $lng) : bool {
    return ($lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0);
  }

  private static function normalize_payload_json($raw) : string {
    if ($raw === null || $raw === '') {
      return '';
    }

    if (is_string($raw)) {
      $decoded = json_decode($raw, true);
      if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return wp_json_encode($decoded);
      }
      return '';
    }

    if (is_array($raw)) {
      return wp_json_encode($raw);
    }

    return '';
  }
}