<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_AttemptService (v1.1)
 *
 * Purpose:
 * - Canonical creator/updater for sd_attempt records.
 *
 * Rules:
 * - Never create an unscoped attempt.
 * - Attempt is the sole Stripe correlation handle (never trip tokens).
 * - Fail-soft (return 0/false) and log instead of fatal.
 */
final class SD_Module_AttemptService {

  public const STATUS_PROPOSED   = 'PROPOSED';
  public const STATUS_CREATED    = 'CREATED';
  public const STATUS_AUTHORIZED = 'AUTHORIZED';
  public const STATUS_FAILED     = 'FAILED';
  public const STATUS_CANCELLED  = 'CANCELLED';

  public static function register() : void {
    // No hooks required.
  }

  public static function create_for_quote(int $quote_id, string $source = 'kernel', array $ctx = []) : int {
    $quote_id = (int) $quote_id;
    if ($quote_id <= 0) {
      self::log('attempt_create_rejected_invalid_quote_id', [
        'quote_id' => $quote_id,
        'source'   => $source,
      ]);
      return 0;
    }

    if (!class_exists('SD_Module_AttemptCPT')) {
      self::log('attempt_create_missing_attempt_cpt_class', [
        'quote_id' => $quote_id,
        'source'   => $source,
      ]);
      return 0;
    }

    if (get_post_type($quote_id) !== 'sd_quote') {
      self::log('attempt_create_rejected_non_quote_post', [
        'quote_id'   => $quote_id,
        'post_type'  => get_post_type($quote_id),
        'source'     => $source,
      ]);
      return 0;
    }

    $ride_id = (int) get_post_meta($quote_id, SD_Meta::RIDE_ID, true);

    $tenant_id = (int) get_post_meta($quote_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0 && $ride_id > 0) {
      $tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    }
    if ($tenant_id <= 0 && class_exists('SD_Module_TenantResolver')) {
      $tenant_id = (int) SD_Module_TenantResolver::current_tenant_id();
    }

    if ($tenant_id <= 0) {
      self::log('attempt_create_missing_tenant', [
        'quote_id' => $quote_id,
        'ride_id'  => $ride_id,
        'source'   => $source,
      ]);
      return 0;
    }

    $aid = wp_insert_post([
      'post_type'   => SD_Module_AttemptCPT::CPT,
      'post_status' => 'publish',
      'post_title'  => 'Attempt - Quote #' . $quote_id . ' - ' . wp_date('Y-m-d H:i:s'),
      'post_author' => get_current_user_id() ?: 0,
    ], true);

    if (is_wp_error($aid) || !$aid) {
      self::log('attempt_create_insert_failed', [
        'quote_id' => $quote_id,
        'ride_id'  => $ride_id,
        'tenant_id'=> $tenant_id,
        'source'   => $source,
        'error'    => is_wp_error($aid) ? $aid->get_error_message() : 'unknown',
      ]);
      return 0;
    }

    $aid = (int) $aid;

    update_post_meta($aid, SD_Meta::TENANT_ID, (string) $tenant_id);
    update_post_meta($aid, SD_Meta::P_ATTEMPT_QUOTE_ID, (string) $quote_id);
    update_post_meta($aid, SD_Meta::P_ATTEMPT_RIDE_ID, (string) $ride_id);
    update_post_meta($aid, SD_Meta::P_ATTEMPT_CREATED_AT, (string) time());
    update_post_meta($aid, '_sd_attempt_source', sanitize_key($source));

    self::set_status($aid, self::STATUS_PROPOSED, [
      'source' => $source,
      'ctx'    => $ctx,
    ]);

    self::log('attempt_created', [
      'attempt_id' => $aid,
      'quote_id'   => $quote_id,
      'ride_id'    => $ride_id,
      'tenant_id'  => $tenant_id,
      'source'     => $source,
    ]);

    return $aid;
  }

  public static function get_status(int $attempt_id) : string {
    $s = (string) get_post_meta((int) $attempt_id, SD_Meta::P_ATTEMPT_STATUS, true);
    return $s !== '' ? $s : self::STATUS_PROPOSED;
  }

  public static function set_status(int $attempt_id, string $status, array $ctx = []) : bool {
    $attempt_id = (int) $attempt_id;
    $status     = strtoupper((string) $status);

    if ($attempt_id <= 0) return false;
    if (get_post_type($attempt_id) !== 'sd_attempt') return false;

    $tenant_id = (int) get_post_meta($attempt_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0) return false;

    $from = self::get_status($attempt_id);

    update_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, $status);
    update_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS_TS, (string) time());

    if ($status === self::STATUS_AUTHORIZED) {
      update_post_meta($attempt_id, SD_Meta::P_ATTEMPT_AUTHORIZED_AT, (string) time());
    }

    self::log('attempt_status_set', [
      'attempt_id' => $attempt_id,
      'from'       => $from,
      'to'         => $status,
      'ctx'        => $ctx,
    ]);

    return true;
  }

  public static function attach_stripe_session(int $attempt_id, string $session_id) : bool {
    $attempt_id = (int) $attempt_id;
    $session_id = trim((string) $session_id);
    if ($attempt_id <= 0 || $session_id === '') return false;
    if (get_post_type($attempt_id) !== 'sd_attempt') return false;

    update_post_meta($attempt_id, SD_Meta::P_STRIPE_SESSION_ID, $session_id);

    self::log('attempt_stripe_session_attached', [
      'attempt_id' => $attempt_id,
      'session_id' => $session_id,
    ]);

    return true;
  }

  public static function attach_stripe_payment_intent(int $attempt_id, string $payment_intent_id) : bool {
    $attempt_id        = (int) $attempt_id;
    $payment_intent_id = trim((string) $payment_intent_id);
    if ($attempt_id <= 0 || $payment_intent_id === '') return false;
    if (get_post_type($attempt_id) !== 'sd_attempt') return false;

    update_post_meta($attempt_id, SD_Meta::P_STRIPE_PAYMENT_INTENT, $payment_intent_id);

    self::log('attempt_stripe_pi_attached', [
      'attempt_id'        => $attempt_id,
      'payment_intent_id' => $payment_intent_id,
    ]);

    return true;
  }

  public static function set_last_stripe_event_id(int $attempt_id, string $event_id) : bool {
    $attempt_id = (int) $attempt_id;
    $event_id   = trim((string) $event_id);
    if ($attempt_id <= 0 || $event_id === '') return false;
    if (get_post_type($attempt_id) !== 'sd_attempt') return false;

    update_post_meta($attempt_id, SD_Meta::P_STRIPE_LAST_EVENT_ID, $event_id);
    return true;
  }

  public static function get_last_stripe_event_id(int $attempt_id) : string {
    return (string) get_post_meta((int) $attempt_id, SD_Meta::P_STRIPE_LAST_EVENT_ID, true);
  }

  public static function set_error(int $attempt_id, string $error, array $ctx = []) : void {
    $attempt_id = (int) $attempt_id;
    if ($attempt_id <= 0) return;

    update_post_meta($attempt_id, SD_Meta::P_ATTEMPT_ERROR, wp_strip_all_tags($error));

    self::log('attempt_error', [
      'attempt_id' => $attempt_id,
      'error'      => $error,
      'ctx'        => $ctx,
    ]);
  }

  public static function get_quote_id(int $attempt_id) : int {
    return (int) get_post_meta((int) $attempt_id, SD_Meta::P_ATTEMPT_QUOTE_ID, true);
  }

  public static function get_ride_id(int $attempt_id) : int {
    return (int) get_post_meta((int) $attempt_id, SD_Meta::P_ATTEMPT_RIDE_ID, true);
  }

  public static function get_tenant_id(int $attempt_id) : int {
    return (int) get_post_meta((int) $attempt_id, SD_Meta::TENANT_ID, true);
  }

  private static function log(string $event, array $ctx = []) : void {
    if (class_exists('SD_Util')) {
      SD_Util::log($event, $ctx);
    }
  }
}