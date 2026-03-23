<?php
if (!defined('ABSPATH')) { exit; }

final class SD_ReservationRideCreator {

  public static function create(array $data) : array {
    $tenant_id = (int) ($data['tenant_id'] ?? 0);
    if ($tenant_id <= 0) {
      return ['ok' => false, 'error' => 'missing_tenant'];
    }

    $pickup_text  = trim((string) ($data['pickup_address'] ?? ''));
    $dropoff_text = trim((string) ($data['dropoff_address'] ?? ''));
    $pickup_pid   = trim((string) ($data['pickup_place_id'] ?? ''));
    $dropoff_pid  = trim((string) ($data['dropoff_place_id'] ?? ''));
    $phone        = trim((string) ($data['customer_phone'] ?? ''));
    $name         = trim((string) ($data['customer_name'] ?? ''));
    $notes        = trim((string) ($data['reserve_notes'] ?? ''));
    $date         = trim((string) ($data['reserve_date'] ?? ''));
    $time         = trim((string) ($data['reserve_time'] ?? ''));

    if ($pickup_text === '' || $dropoff_text === '' || $pickup_pid === '' || $dropoff_pid === '') {
      return ['ok' => false, 'error' => 'missing_locations'];
    }

    if ($phone === '') {
      return ['ok' => false, 'error' => 'missing_phone'];
    }

    $requested_ts = self::build_requested_ts($date, $time, $tenant_id);
    if ($requested_ts <= time()) {
      return ['ok' => false, 'error' => 'invalid_requested_ts'];
    }

    $token = self::generate_reservation_token();

    $ride_id = wp_insert_post([
      'post_type'   => 'sd_ride',
      'post_status' => 'publish',
      'post_title'  => 'Reservation — ' . gmdate('Y-m-d H:i:s', $requested_ts),
    ], true);

    if (is_wp_error($ride_id) || (int) $ride_id <= 0) {
      return ['ok' => false, 'error' => 'ride_create_failed'];
    }
    $ride_id = (int) $ride_id;

    update_post_meta($ride_id, SD_Meta::TENANT_ID, $tenant_id);
    update_post_meta($ride_id, SD_Meta::PICKUP_TEXT, $pickup_text);
    update_post_meta($ride_id, SD_Meta::DROPOFF_TEXT, $dropoff_text);
    update_post_meta($ride_id, SD_Meta::PICKUP_PLACE_ID, $pickup_pid);
    update_post_meta($ride_id, SD_Meta::DROPOFF_PLACE_ID, $dropoff_pid);

    if ($phone !== '') update_post_meta($ride_id, SD_Meta::CUSTOMER_PHONE, $phone);
    if ($name !== '')  update_post_meta($ride_id, SD_Meta::CUSTOMER_NAME, $name);

    update_post_meta($ride_id, 'sd_ride_mode', 'RESERVED');
    update_post_meta($ride_id, 'sd_requested_ts', $requested_ts);
    update_post_meta($ride_id, 'sd_reserve_notes', $notes);
    update_post_meta($ride_id, 'sd_reservation_created_ts', time());
    update_post_meta($ride_id, 'sd_reservation_token', $token);

    update_post_meta($ride_id, SD_Meta::LEAD_STATUS, 'LEAD_CAPTURED');
    update_post_meta($ride_id, SD_Meta::RIDE_STATE, 'RIDE_QUEUED');
    update_post_meta($ride_id, SD_Meta::P_STATE_UPDATED_AT, time());

    $block = SD_ServiceBlockBuilder::build_from_ride($ride_id);
    if (empty($block['tenant_id']) || empty($block['start_ts']) || empty($block['end_ts'])) {
      return ['ok' => false, 'error' => 'block_build_failed'];
    }

    $available = SD_TimeBlockRepository::is_available(
      (int) $block['tenant_id'],
      (int) $block['start_ts'],
      (int) $block['end_ts']
    );

    if (!$available) {
      update_post_meta($ride_id, '_sd_block_conflict', 1);
      return ['ok' => false, 'error' => 'time_conflict'];
    }

    $block_id = SD_TimeBlockRepository::create_for_ride($ride_id, $block);
    update_post_meta($ride_id, 'sd_service_start_ts', (int) $block['start_ts']);
    update_post_meta($ride_id, 'sd_service_end_ts', (int) $block['end_ts']);
    update_post_meta($ride_id, 'sd_time_block_id', (int) $block_id);

    $payment_strategy = SD_PaymentStrategyResolver::resolve_for_ride($ride_id);
    update_post_meta($ride_id, 'sd_payment_strategy', $payment_strategy);

    $customer = SD_StripeCustomerService::get_or_create_for_ride($ride_id);
    if (empty($customer['ok'])) {
      return [
        'ok'      => true,
        'ride_id' => $ride_id,
        'token'   => $token,
      ];
    }

    if (in_array($payment_strategy, ['SAVE_ONLY', 'AUTHORIZE_LATER'], true)) {
      $setup = SD_StripeSetupIntentService::create_for_ride($ride_id);
      if (!empty($setup['ok'])) {
        update_post_meta($ride_id, 'sd_setup_intent_client_secret', (string) $setup['client_secret']);
      }
    }

    return [
      'ok'      => true,
      'ride_id' => $ride_id,
      'token'   => $token,
    ];
  }

  private static function build_requested_ts(string $date, string $time, int $tenant_id) : int {
    $raw = trim($date . ' ' . $time);
    if ($raw === '') return 0;

    $ts = strtotime($raw);
    return $ts ? (int) $ts : 0;
  }

  private static function generate_reservation_token() : string {
    $raw = random_bytes(16);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
  }
}