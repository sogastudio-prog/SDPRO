<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_LeadService
 *
 * Purpose:
 * - Canonical lead creation service for storefront/intake entry.
 * - Store only the minimum validated engagement snapshot.
 * - Mint token on lead and return /trip/<token>/ URL.
 *
 * Canon:
 * - sd_lead is the lifecycle root.
 * - storefront captures lead only; no quote/attempt/ride creation here.
 */
final class SD_Module_LeadService {

  public static function register() : void {}

  public static function create_from_intake(array $payload, int $tenant_id) : array {
    $tenant_id = absint($tenant_id);
    if ($tenant_id <= 0) {
      return self::fail('Missing tenant.');
    }

    if (!class_exists('SD_Module_TenantCPT') || get_post_type($tenant_id) !== SD_Module_TenantCPT::CPT) {
      return self::fail('Invalid tenant.');
    }

    $pickup_text   = self::clean_text($payload['pickup_address'] ?? '');
    $dropoff_text  = self::clean_text($payload['dropoff_address'] ?? '');
    $pickup_pid    = self::clean_token($payload['pickup_place_id'] ?? '');
    $dropoff_pid   = self::clean_token($payload['dropoff_place_id'] ?? '');
    $phone         = self::normalize_phone($payload['customer_phone'] ?? '');
    $name          = self::clean_text($payload['customer_name'] ?? '');
    $mode          = self::normalize_request_mode($payload['sd_request_mode'] ?? ($payload['request_mode'] ?? SD_Meta::LEAD_MODE_ASAP));
    $reserve_date  = self::clean_date($payload['reserve_date'] ?? '');
    $reserve_time  = self::clean_time($payload['reserve_time'] ?? '');
    $reserve_notes = self::clean_text($payload['reserve_notes'] ?? ($payload['customer_notes'] ?? ''));

    if ($pickup_pid === '' || $dropoff_pid === '') {
      return self::fail('Pickup and dropoff place IDs are required.');
    }

    if ($name === '') {
      return self::fail('Name is required.');
    }

    if (!self::is_valid_phone($phone)) {
      return self::fail('Valid phone is required.');
    }

    if ($mode === SD_Meta::LEAD_MODE_RESERVE && ($reserve_date === '' || $reserve_time === '')) {
      return self::fail('Reservation date and time are required.');
    }

    // Canonical lead request time:
    // - ASAP: anchored to intake time
    // - RESERVE: anchored to visitor-selected future time
    $requested_ts = current_time('timestamp');
    $scheduled_ts = 0;

    if ($mode === SD_Meta::LEAD_MODE_RESERVE) {
      $scheduled_ts = self::build_requested_ts($reserve_date, $reserve_time);
      if ($scheduled_ts <= 0) {
        return self::fail('Invalid reservation date or time.');
      }
      $requested_ts = $scheduled_ts;
    }

    $title_when = gmdate('Y-m-d H:i', (int) $requested_ts) . ' UTC';
    $title_name = ($name !== '') ? $name : 'Lead';

    $lead_id = wp_insert_post([
      'post_type'   => SD_Module_LeadCPT::CPT,
      'post_status' => 'publish',
      'post_title'  => sprintf('Lead — %s — %s', $title_name, $title_when),
    ], true);

    if (is_wp_error($lead_id) || (int) $lead_id <= 0) {
      return self::fail('Could not create lead record.');
    }
    $lead_id = (int) $lead_id;

    update_post_meta($lead_id, SD_Meta::TENANT_ID, $tenant_id);
    update_post_meta($lead_id, SD_Meta::PICKUP_TEXT, $pickup_text);
    update_post_meta($lead_id, SD_Meta::DROPOFF_TEXT, $dropoff_text);
    update_post_meta($lead_id, SD_Meta::PICKUP_PLACE_ID, $pickup_pid);
    update_post_meta($lead_id, SD_Meta::DROPOFF_PLACE_ID, $dropoff_pid);

    self::maybe_store_coords($lead_id, $payload, 'pickup_lat', 'pickup_lng', SD_Meta::PICKUP_LAT, SD_Meta::PICKUP_LNG);
    self::maybe_store_coords($lead_id, $payload, 'dropoff_lat', 'dropoff_lng', SD_Meta::DROPOFF_LAT, SD_Meta::DROPOFF_LNG);

    update_post_meta($lead_id, SD_Meta::CUSTOMER_PHONE, $phone);
    update_post_meta($lead_id, SD_Meta::CUSTOMER_NAME, $name);

    if ($reserve_notes !== '') {
      update_post_meta($lead_id, SD_Meta::RESERVE_NOTES, $reserve_notes);
    }

    update_post_meta($lead_id, SD_Meta::LEAD_STATUS, SD_Meta::LEAD_CAPTURED);
    update_post_meta($lead_id, SD_Meta::AVAILABILITY_STATUS, 'pending');
    update_post_meta($lead_id, SD_Meta::REQUEST_MODE, $mode);
    update_post_meta($lead_id, SD_Meta::REQUESTED_TS, $requested_ts);

    if ($mode === SD_Meta::LEAD_MODE_RESERVE) {
      update_post_meta($lead_id, SD_Meta::REQUESTED_DATE, $reserve_date);
      update_post_meta($lead_id, SD_Meta::REQUESTED_TIME, $reserve_time);
      update_post_meta($lead_id, SD_Meta::PICKUP_SCHEDULED_TS, $scheduled_ts);
      update_post_meta($lead_id, SD_Meta::RESERVATION_CREATED_TS, current_time('timestamp'));
    }

    update_post_meta($lead_id, SD_Meta::P_FORM_SNAPSHOT_JSON, wp_json_encode(self::snapshot_payload($payload)));

    $token = class_exists('SD_Module_LeadTokenService')
      ? (string) SD_Module_LeadTokenService::assign_token($lead_id)
      : '';

    if ($token === '') {
      wp_delete_post($lead_id, true);
      return self::fail('Could not mint lead token.');
    }

    $trip_url = home_url('/trip/' . rawurlencode($token) . '/');

    do_action('sd_lead_created', $lead_id, $tenant_id, [
      'request_mode' => $mode,
      'requested_ts' => $requested_ts,
      'trip_url'     => $trip_url,
    ]);

    return [
      'ok'       => true,
      'lead_id'  => $lead_id,
      'token'    => $token,
      'trip_url' => $trip_url,
      'error'    => '',
    ];
    if (class_exists('SD_CoreStage', false)) {
    SD_CoreStage::initialize($lead_id, SD_CoreStage::LEAD_CAPTURED, 'Lead captured from storefront intake.');
    }
  }

  private static function is_valid_phone(string $value) : bool {
    return (bool) preg_match('/^\+?[0-9]{10,15}$/', $value);
  }

  private static function normalize_phone($value) : string {
    $normalized = preg_replace('/[^0-9+]/', '', (string) $value);
    return is_string($normalized) ? trim($normalized) : '';
  }

  private static function snapshot_payload(array $payload) : array {
    $allow = [
      'pickup_address','dropoff_address','pickup_place_id','dropoff_place_id',
      'pickup_lat','pickup_lng','dropoff_lat','dropoff_lng',
      'customer_phone','customer_name','sd_request_mode','request_mode',
      'reserve_date','reserve_time','reserve_notes','customer_notes','sd_tenant_id'
    ];
    $snap = [];
    foreach ($allow as $key) {
      if (!array_key_exists($key, $payload)) continue;
      $val = $payload[$key];
      $snap[$key] = is_scalar($val) ? (string) $val : $val;
    }
    return $snap;
  }

  private static function maybe_store_coords(int $lead_id, array $payload, string $lat_key, string $lng_key, string $meta_lat, string $meta_lng) : void {
    $lat = self::to_float($payload[$lat_key] ?? null);
    $lng = self::to_float($payload[$lng_key] ?? null);
    if (abs($lat) > 0.0001 && abs($lng) > 0.0001) {
      update_post_meta($lead_id, $meta_lat, $lat);
      update_post_meta($lead_id, $meta_lng, $lng);
    }
  }

  private static function build_requested_ts(string $date, string $time) : int {
    if ($date === '' || $time === '') return 0;
    $ts = strtotime($date . ' ' . $time, current_time('timestamp'));
    return $ts ? (int) $ts : 0;
  }

  private static function normalize_request_mode($value) : string {
    $v = strtoupper(trim((string) $value));
    return ($v === SD_Meta::LEAD_MODE_RESERVE || $v === 'RESERVATION')
      ? SD_Meta::LEAD_MODE_RESERVE
      : SD_Meta::LEAD_MODE_ASAP;
  }

  private static function clean_text($value) : string {
    return trim(sanitize_text_field((string) $value));
  }

  private static function clean_token($value) : string {
    return preg_replace('/[^A-Za-z0-9_:\-]/', '', trim((string) $value)) ?: '';
  }

  private static function clean_date($value) : string {
    $value = trim((string) $value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
  }

  private static function clean_time($value) : string {
    $value = trim((string) $value);
    return preg_match('/^\d{2}:\d{2}$/', $value) ? $value : '';
  }

  private static function to_float($value) : float {
    return is_numeric($value) ? (float) $value : 0.0;
  }

  private static function fail(string $error) : array {
    return [
      'ok'       => false,
      'lead_id'  => 0,
      'token'    => '',
      'trip_url' => '',
      'error'    => $error,
    ];
  }
}