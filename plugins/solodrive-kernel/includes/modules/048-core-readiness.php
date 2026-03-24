<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_CoreReadiness
 *
 * Purpose:
 * - Provide minimal boolean checks for required handoffs between core stages
 * - Keep orchestration conditions out of worker classes where possible
 *
 * Canon:
 * - Core verifies readiness before advancing to the next required stage
 * - Workers may still defensively re-check on entry
 * - v1 focuses on sd_lead readiness only
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
   */
  public static function lead_is_captured(int $lead_id) : bool {
    if (!self::has_valid_lead($lead_id)) return false;

    $tenant_id       = absint(get_post_meta($lead_id, SD_Meta::TENANT_ID, true));
    $pickup_place_id = (string) get_post_meta($lead_id, SD_Meta::PICKUP_PLACE_ID, true);
    $dropoff_place_id= (string) get_post_meta($lead_id, SD_Meta::DROPOFF_PLACE_ID, true);
    $requested_ts    = absint(get_post_meta($lead_id, SD_Meta::REQUESTED_TS, true));
    $customer_name   = trim((string) get_post_meta($lead_id, SD_Meta::CUSTOMER_NAME, true));
    $customer_phone  = trim((string) get_post_meta($lead_id, SD_Meta::CUSTOMER_PHONE, true));
    $token           = trim((string) get_post_meta($lead_id, SD_Meta::TRIP_TOKEN, true));

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
   * Route intel is available.
   *
   * v1 rule:
   * - meters and/or seconds must exist
   * - route job must not be in error
   */
  public static function lead_has_route_intel(int $lead_id) : bool {
    if (!self::has_valid_lead($lead_id)) return false;

    $meters  = absint(get_post_meta($lead_id, SD_Meta::ROUTE_METERS, true));
    $seconds = absint(get_post_meta($lead_id, SD_Meta::ROUTE_SECONDS, true));
    $job     = trim((string) get_post_meta($lead_id, SD_Meta::P_ROUTE_JOB_STATE, true));
    $error   = trim((string) get_post_meta($lead_id, SD_Meta::P_ROUTE_ERROR, true));

    if ($job === 'error' || $error !== '') return false;

    return ($meters > 0 || $seconds > 0);
  }

  /**
   * Timeblock evaluation/availability is available.
   *
   * v1 rule:
   * - AVAILABILITY_STATUS exists and is not pending
   */
  public static function lead_has_timeblock_result(int $lead_id) : bool {
    if (!self::has_valid_lead($lead_id)) return false;

    $availability = trim((string) get_post_meta($lead_id, SD_Meta::AVAILABILITY_STATUS, true));
    if ($availability === '') return false;
    if ($availability === 'pending') return false;

    return true;
  }

  /**
   * A current quote exists and is in a usable status.
   */
  public static function lead_has_current_quote(int $lead_id) : bool {
    if (!self::has_valid_lead($lead_id)) return false;

    $quote_id = absint(get_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, true));
    if ($quote_id <= 0) return false;
    if (get_post_type($quote_id) !== SD_Meta::QUOTE_CPT) return false;

    $status = trim((string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true));
    if ($status === '') return false;

    return !in_array($status, [
      SD_Meta::QUOTE_CANCELLED,
      SD_Meta::QUOTE_SUPERSEDED,
      SD_Meta::QUOTE_EXPIRED,
      SD_Meta::QUOTE_USER_REJECTED,
      SD_Meta::QUOTE_LEAD_REJECTED,
    ], true);
  }

  /**
   * Quote is ready for driver review.
   *
   * v1: same as current quote exists in PROPOSED or APPROVED.
   */
  public static function lead_needs_driver_review(int $lead_id) : bool {
    if (!self::lead_has_current_quote($lead_id)) return false;

    $quote_id = absint(get_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, true));
    $status   = trim((string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true));

    return in_array($status, [
      SD_Meta::QUOTE_PROPOSED,
      SD_Meta::QUOTE_APPROVED,
    ], true);
  }

  /**
   * Quote has been presented and is awaiting rider/payor decision.
   */
  public static function lead_is_awaiting_rider_decision(int $lead_id) : bool {
    if (!self::lead_has_current_quote($lead_id)) return false;

    $quote_id = absint(get_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, true));
    $status   = trim((string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true));

    return ($status === SD_Meta::QUOTE_PRESENTED);
  }

  /**
   * Rider accepted quote and lead can move toward auth.
   */
  public static function lead_needs_auth(int $lead_id) : bool {
    if (!self::lead_has_current_quote($lead_id)) return false;

    $quote_id = absint(get_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, true));
    $status   = trim((string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true));

    return in_array($status, [
      SD_Meta::QUOTE_LEAD_ACCEPTED,
      SD_Meta::QUOTE_PAYMENT_PENDING,
    ], true);
  }

  /**
   * Auth/attempt is complete enough to promote toward ride.
   *
   * v1 rule:
   * - current attempt exists
   * - attempt status is AUTHORIZED or CAPTURED
   *
   * Note:
   * - Uses public ATTEMPT_STATUS if present
   * - Falls back to private P_ATTEMPT_STATUS
   */
  public static function lead_is_authorized(int $lead_id) : bool {
    if (!self::has_valid_lead($lead_id)) return false;

    $attempt_id = absint(get_post_meta($lead_id, SD_Meta::CURRENT_ATTEMPT_ID, true));
    if ($attempt_id <= 0) return false;

    $status = '';
    if (defined('SD_Meta::ATTEMPT_STATUS')) {
      $status = trim((string) get_post_meta($attempt_id, SD_Meta::ATTEMPT_STATUS, true));
    }

    if ($status === '' && defined('SD_Meta::P_ATTEMPT_STATUS')) {
      $status = trim((string) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, true));
    }

    return in_array($status, [
      'AUTHORIZED',
      'CAPTURED',
    ], true);
  }

  /**
   * Lead has enough to promote into an operational ride.
   *
   * v1 rule:
   * - authorized
   * - current quote exists
   */
  public static function lead_can_promote_to_ride(int $lead_id) : bool {
    return self::lead_is_authorized($lead_id) && self::lead_has_current_quote($lead_id);
  }

  /**
   * Lead already has a promoted ride.
   */
  public static function lead_is_promoted(int $lead_id) : bool {
    if (!self::has_valid_lead($lead_id)) return false;

    $ride_id = absint(get_post_meta($lead_id, SD_Meta::PROMOTED_RIDE_ID, true));
    if ($ride_id <= 0) return false;

    return get_post_type($ride_id) === SD_Meta::RIDE_CPT;
  }

  /**
   * Generic readiness helper for orchestration.
   */
  public static function can_enter_stage(int $lead_id, string $stage) : bool {
    if (!class_exists('SD_CoreStage', false)) return false;

    switch ($stage) {
      case SD_CoreStage::LEAD_CAPTURED:
        return self::lead_is_captured($lead_id);

      case SD_CoreStage::LEAD_NEEDS_ROUTE_INTEL:
        return self::lead_is_captured($lead_id);

      case SD_CoreStage::LEAD_NEEDS_TIMEBLOCK:
        return self::lead_has_route_intel($lead_id);

      case SD_CoreStage::LEAD_NEEDS_QUOTE:
        return self::lead_has_timeblock_result($lead_id);

      case SD_CoreStage::LEAD_NEEDS_DRIVER_REVIEW:
        return self::lead_has_current_quote($lead_id);

      case SD_CoreStage::LEAD_NEEDS_PRESENTATION:
        return self::lead_needs_driver_review($lead_id);

      case SD_CoreStage::LEAD_AWAITING_RIDER_DECISION:
        return self::lead_is_awaiting_rider_decision($lead_id);

      case SD_CoreStage::LEAD_NEEDS_AUTH:
        return self::lead_needs_auth($lead_id);

      case SD_CoreStage::LEAD_AUTHORIZED:
        return self::lead_is_authorized($lead_id);

      case SD_CoreStage::LEAD_NEEDS_RIDE_PROMOTION:
        return self::lead_is_authorized($lead_id);

      case SD_CoreStage::LEAD_PROMOTED:
        return self::lead_is_promoted($lead_id);
    }

    // Exception stages are deliberately broad in v1.
    if (method_exists('SD_CoreStage', 'stage_type') && SD_CoreStage::stage_type($stage) === SD_CoreStage::TYPE_EXCEPTION) {
      return self::has_valid_lead($lead_id);
    }

    return false;
  }

  private static function phone_is_valid(string $value) : bool {
    return (bool) preg_match('/^\+?[0-9]{10,15}$/', $value);
  }
}