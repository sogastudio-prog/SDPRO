<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_CoreReadiness
 *
 * Purpose:
 * - Minimal readiness checks for intake hardening
 *
 * Canon:
 * - v1 scope is lead capture only
 * - Do not pull downstream orchestration concerns into intake phase
 */

if (class_exists('SD_CoreReadiness', false)) { return; }

final class SD_CoreReadiness {

  /**
   * Lead exists and is correctly typed.
   */
  public static function has_valid_lead(int $lead_id) : bool {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) return false;

    if (defined('SD_Meta::LEAD_CPT')) {
      return get_post_type($lead_id) === SD_Meta::LEAD_CPT;
    }

    return get_post_type($lead_id) === 'sd_lead';
  }

  /**
   * Minimum captured lead contract.
   *
   * Required:
   * - tenant_id
   * - pickup_place_id
   * - dropoff_place_id
   * - requested_ts
   * - customer_name
   * - customer_phone
   * - trip token
   */
  public static function lead_is_captured(int $lead_id) : bool {
    if (!self::has_valid_lead($lead_id)) return false;

    $tenant_id        = absint(get_post_meta($lead_id, SD_Meta::TENANT_ID, true));
    $pickup_place_id  = trim((string) get_post_meta($lead_id, SD_Meta::PICKUP_PLACE_ID, true));
    $dropoff_place_id = trim((string) get_post_meta($lead_id, SD_Meta::DROPOFF_PLACE_ID, true));
    $requested_ts     = absint(get_post_meta($lead_id, SD_Meta::REQUESTED_TS, true));
    $customer_name    = trim((string) get_post_meta($lead_id, SD_Meta::CUSTOMER_NAME, true));
    $customer_phone   = trim((string) get_post_meta($lead_id, SD_Meta::CUSTOMER_PHONE, true));
    $token            = trim((string) get_post_meta($lead_id, SD_Meta::TRIP_TOKEN, true));

    if ($tenant_id <= 0) return false;
    if ($pickup_place_id === '') return false;
    if ($dropoff_place_id === '') return false;
    if ($requested_ts <= 0) return false;
    if ($customer_name === '') return false;
    if (!self::phone_is_valid($customer_phone)) return false;
    if ($token === '') return false;

    return true;
  }

  /**
   * Minimal stage-entry readiness for intake phase only.
   */
  public static function can_enter_stage(int $lead_id, string $stage) : bool {
    if (!class_exists('SD_CoreStage', false)) return false;

    switch ($stage) {
      case SD_CoreStage::LEAD_CAPTURED:
        return self::lead_is_captured($lead_id);

      case SD_CoreStage::LEAD_NEEDS_ROUTE_INTEL:
        return self::lead_is_captured($lead_id);
    }

    return false;
  }

  private static function phone_is_valid(string $value) : bool {
    return (bool) preg_match('/^\+?[0-9]{10,15}$/', $value);
  }
}