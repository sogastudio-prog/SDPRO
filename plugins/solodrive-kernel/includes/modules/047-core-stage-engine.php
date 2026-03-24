<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_CoreStage
 *
 * Purpose:
 * - Minimal core stage engine for lead-first orchestration
 * - Store current core stage on the record
 * - Enforce simple valid transitions
 * - Log transition metadata for debugging/audit
 * - Fire stage-specific worker hooks
 *
 * Canon:
 * - Lead capture is sovereign
 * - Core owns orchestration stage
 * - Workers do one job and return object to core
 * - Required stages = promotion path
 * - Exception stages = temporary detours
 *
 * Scope:
 * - v1 focuses on sd_lead orchestration only
 * - Can be extended later for ride/attempt objects if needed
 */

if (class_exists('SD_CoreStage', false)) { return; }

final class SD_CoreStage {

  // ---------------------------------------------------------------------------
  // Meta keys
  // ---------------------------------------------------------------------------

  public const META_STAGE        = 'sd_core_stage';
  public const META_STAGE_TYPE   = 'sd_core_stage_type';      // REQUIRED|EXCEPTION
  public const META_STAGE_REASON = '_sd_core_stage_reason';
  public const META_STAGE_TS     = '_sd_core_stage_ts';
  public const META_RETURN_STAGE = '_sd_core_return_stage';

  // ---------------------------------------------------------------------------
  // Stage type values
  // ---------------------------------------------------------------------------

  public const TYPE_REQUIRED  = 'REQUIRED';
  public const TYPE_EXCEPTION = 'EXCEPTION';

  // ---------------------------------------------------------------------------
  // Required stages (mainline)
  // ---------------------------------------------------------------------------

  public const LEAD_CAPTURED                = 'LEAD_CAPTURED';
  public const LEAD_NEEDS_ROUTE_INTEL       = 'LEAD_NEEDS_ROUTE_INTEL';
  public const LEAD_NEEDS_TIMEBLOCK         = 'LEAD_NEEDS_TIMEBLOCK';
  public const LEAD_NEEDS_QUOTE             = 'LEAD_NEEDS_QUOTE';
  public const LEAD_NEEDS_DRIVER_REVIEW     = 'LEAD_NEEDS_DRIVER_REVIEW';
  public const LEAD_NEEDS_PRESENTATION      = 'LEAD_NEEDS_PRESENTATION';
  public const LEAD_AWAITING_RIDER_DECISION = 'LEAD_AWAITING_RIDER_DECISION';
  public const LEAD_NEEDS_AUTH              = 'LEAD_NEEDS_AUTH';
  public const LEAD_AUTHORIZED              = 'LEAD_AUTHORIZED';
  public const LEAD_NEEDS_RIDE_PROMOTION    = 'LEAD_NEEDS_RIDE_PROMOTION';
  public const LEAD_PROMOTED                = 'LEAD_PROMOTED';

  // ---------------------------------------------------------------------------
  // Exception stages (first-pass obvious detours)
  // ---------------------------------------------------------------------------

  public const LEAD_EXCEPTION_ROUTE_INTEL       = 'LEAD_EXCEPTION_ROUTE_INTEL';
  public const LEAD_EXCEPTION_TIMEBLOCK         = 'LEAD_EXCEPTION_TIMEBLOCK';
  public const LEAD_EXCEPTION_QUOTE             = 'LEAD_EXCEPTION_QUOTE';
  public const LEAD_EXCEPTION_AUTH              = 'LEAD_EXCEPTION_AUTH';
  public const LEAD_EXCEPTION_RIDER_CHANGE      = 'LEAD_EXCEPTION_RIDER_CHANGE';
  public const LEAD_EXCEPTION_DRIVER_ADJUSTMENT = 'LEAD_EXCEPTION_DRIVER_ADJUSTMENT';
  public const LEAD_EXCEPTION_CANCELLATION      = 'LEAD_EXCEPTION_CANCELLATION';

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  /**
   * Set initial stage without transition validation.
   * Use for first assignment only.
   */
  public static function initialize(int $lead_id, string $stage, string $reason = '') : bool {
    $lead_id = absint($lead_id);
    if (!self::is_valid_lead($lead_id)) return false;
    if (!self::is_known_stage($stage)) return false;

    self::write_stage($lead_id, $stage, $reason);
    self::fire_stage_hook($lead_id, $stage, '', $reason);

    return true;
  }

  /**
   * Advance to a new stage with validation.
   */
  public static function advance(int $lead_id, string $to_stage, string $reason = '') : bool {
    $lead_id = absint($lead_id);
    if (!self::is_valid_lead($lead_id)) return false;
    if (!self::is_known_stage($to_stage)) return false;

    $from_stage = self::current_stage($lead_id);

    if ($from_stage !== '' && !self::can_transition($from_stage, $to_stage)) {
      self::log_transition($lead_id, $from_stage, $to_stage, $reason, false, 'invalid_transition');
      return false;
    }

    self::write_stage($lead_id, $to_stage, $reason);
    self::fire_stage_hook($lead_id, $to_stage, $from_stage, $reason);

    return true;
  }

  /**
   * Route to an exception stage and optionally remember where to return.
   */
  public static function divert_to_exception(int $lead_id, string $exception_stage, string $return_stage = '', string $reason = '') : bool {
    $lead_id = absint($lead_id);
    if (!self::is_valid_lead($lead_id)) return false;
    if (!self::is_exception_stage($exception_stage)) return false;
    if ($return_stage !== '' && !self::is_known_stage($return_stage)) return false;

    if ($return_stage !== '') {
      update_post_meta($lead_id, self::META_RETURN_STAGE, $return_stage);
    }

    return self::advance($lead_id, $exception_stage, $reason);
  }

  /**
   * Resolve the current exception and return to remembered stage,
   * or supplied fallback stage.
   */
  public static function resolve_exception(int $lead_id, string $fallback_return_stage = '', string $reason = '') : bool {
    $lead_id = absint($lead_id);
    if (!self::is_valid_lead($lead_id)) return false;

    $current = self::current_stage($lead_id);
    if (!self::is_exception_stage($current)) return false;

    $return_stage = (string) get_post_meta($lead_id, self::META_RETURN_STAGE, true);
    if ($return_stage === '') {
      $return_stage = $fallback_return_stage;
    }

    if (!self::is_known_stage($return_stage)) return false;

    delete_post_meta($lead_id, self::META_RETURN_STAGE);

    return self::advance($lead_id, $return_stage, $reason);
  }

  public static function current_stage(int $lead_id) : string {
    $stage = get_post_meta($lead_id, self::META_STAGE, true);
    return is_string($stage) ? trim($stage) : '';
  }

  public static function current_stage_type(int $lead_id) : string {
    $type = get_post_meta($lead_id, self::META_STAGE_TYPE, true);
    return is_string($type) ? trim($type) : '';
  }

  public static function stage_type(string $stage) : string {
    if (self::is_exception_stage($stage)) return self::TYPE_EXCEPTION;
    if (self::is_required_stage($stage)) return self::TYPE_REQUIRED;
    return '';
  }

  public static function can_transition(string $from_stage, string $to_stage) : bool {
    if ($from_stage === $to_stage) return true;
    if (!self::is_known_stage($from_stage) || !self::is_known_stage($to_stage)) return false;

    // Exception stages can always be entered from any known stage.
    if (self::is_exception_stage($to_stage)) return true;

    $map = self::transition_map();

    // Explicit allowed required-stage transition.
    if (isset($map[$from_stage]) && in_array($to_stage, $map[$from_stage], true)) {
      return true;
    }

    // Exception -> required return is allowed broadly.
    if (self::is_exception_stage($from_stage) && self::is_required_stage($to_stage)) {
      return true;
    }

    return false;
  }

  public static function all_required_stages() : array {
    return [
      self::LEAD_CAPTURED,
      self::LEAD_NEEDS_ROUTE_INTEL,
      self::LEAD_NEEDS_TIMEBLOCK,
      self::LEAD_NEEDS_QUOTE,
      self::LEAD_NEEDS_DRIVER_REVIEW,
      self::LEAD_NEEDS_PRESENTATION,
      self::LEAD_AWAITING_RIDER_DECISION,
      self::LEAD_NEEDS_AUTH,
      self::LEAD_AUTHORIZED,
      self::LEAD_NEEDS_RIDE_PROMOTION,
      self::LEAD_PROMOTED,
    ];
  }

  public static function all_exception_stages() : array {
    return [
      self::LEAD_EXCEPTION_ROUTE_INTEL,
      self::LEAD_EXCEPTION_TIMEBLOCK,
      self::LEAD_EXCEPTION_QUOTE,
      self::LEAD_EXCEPTION_AUTH,
      self::LEAD_EXCEPTION_RIDER_CHANGE,
      self::LEAD_EXCEPTION_DRIVER_ADJUSTMENT,
      self::LEAD_EXCEPTION_CANCELLATION,
    ];
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  private static function write_stage(int $lead_id, string $stage, string $reason = '') : void {
    update_post_meta($lead_id, self::META_STAGE, $stage);
    update_post_meta($lead_id, self::META_STAGE_TYPE, self::stage_type($stage));
    update_post_meta($lead_id, self::META_STAGE_TS, time());

    if ($reason !== '') {
      update_post_meta($lead_id, self::META_STAGE_REASON, $reason);
    }

    // Keep business lifecycle synchronized where appropriate.
    self::sync_business_lifecycle($lead_id, $stage);

    self::log_transition(
      $lead_id,
      self::current_stage_before_write($lead_id),
      $stage,
      $reason,
      true,
      'ok'
    );
  }

  private static function current_stage_before_write(int $lead_id) : string {
    $stage = get_post_meta($lead_id, self::META_STAGE, true);
    return is_string($stage) ? trim($stage) : '';
  }

  private static function fire_stage_hook(int $lead_id, string $stage, string $from_stage, string $reason) : void {
    /**
     * Generic stage change hook.
     */
    do_action('sd_core_stage_changed', $lead_id, $stage, $from_stage, $reason);

    /**
     * Specific worker hook.
     * Example:
     *   do_action('sd_core_stage_LEAD_NEEDS_ROUTE_INTEL', $lead_id, $from_stage, $reason);
     */
    do_action('sd_core_stage_' . $stage, $lead_id, $from_stage, $reason);
  }

  private static function sync_business_lifecycle(int $lead_id, string $stage) : void {
    if (!defined('SD_Meta::LEAD_STATUS')) return;

    switch ($stage) {
      case self::LEAD_CAPTURED:
        update_post_meta($lead_id, SD_Meta::LEAD_STATUS, SD_Meta::LEAD_CAPTURED);
        break;

      case self::LEAD_NEEDS_QUOTE:
      case self::LEAD_NEEDS_DRIVER_REVIEW:
      case self::LEAD_NEEDS_PRESENTATION:
      case self::LEAD_AWAITING_RIDER_DECISION:
        update_post_meta($lead_id, SD_Meta::LEAD_STATUS, SD_Meta::LEAD_WAITING_QUOTE);
        break;

      case self::LEAD_NEEDS_AUTH:
        update_post_meta($lead_id, SD_Meta::LEAD_STATUS, SD_Meta::LEAD_OFFERED);
        break;

      case self::LEAD_AUTHORIZED:
        // Leave business status unchanged for now unless you want a dedicated state.
        break;

      case self::LEAD_NEEDS_RIDE_PROMOTION:
      case self::LEAD_PROMOTED:
        update_post_meta($lead_id, SD_Meta::LEAD_STATUS, SD_Meta::LEAD_PROMOTED);
        break;

      case self::LEAD_EXCEPTION_AUTH:
        if (defined('SD_Meta::LEAD_AUTH_FAILED')) {
          update_post_meta($lead_id, SD_Meta::LEAD_STATUS, SD_Meta::LEAD_AUTH_FAILED);
        }
        break;

      case self::LEAD_EXCEPTION_CANCELLATION:
        if (defined('SD_Meta::LEAD_DECLINED')) {
          update_post_meta($lead_id, SD_Meta::LEAD_STATUS, SD_Meta::LEAD_DECLINED);
        }
        break;
    }
  }

  private static function transition_map() : array {
    return [
      self::LEAD_CAPTURED                => [self::LEAD_NEEDS_ROUTE_INTEL],
      self::LEAD_NEEDS_ROUTE_INTEL       => [self::LEAD_NEEDS_TIMEBLOCK],
      self::LEAD_NEEDS_TIMEBLOCK         => [self::LEAD_NEEDS_QUOTE],
      self::LEAD_NEEDS_QUOTE             => [self::LEAD_NEEDS_DRIVER_REVIEW],
      self::LEAD_NEEDS_DRIVER_REVIEW     => [self::LEAD_NEEDS_PRESENTATION],
      self::LEAD_NEEDS_PRESENTATION      => [self::LEAD_AWAITING_RIDER_DECISION],
      self::LEAD_AWAITING_RIDER_DECISION => [self::LEAD_NEEDS_AUTH],
      self::LEAD_NEEDS_AUTH              => [self::LEAD_AUTHORIZED],
      self::LEAD_AUTHORIZED              => [self::LEAD_NEEDS_RIDE_PROMOTION],
      self::LEAD_NEEDS_RIDE_PROMOTION    => [self::LEAD_PROMOTED],
    ];
  }

  private static function is_valid_lead(int $lead_id) : bool {
    if ($lead_id <= 0) return false;

    if (defined('SD_Meta::LEAD_CPT')) {
      return get_post_type($lead_id) === SD_Meta::LEAD_CPT;
    }

    return get_post_type($lead_id) === 'sd_lead';
  }

  private static function is_known_stage(string $stage) : bool {
    return self::is_required_stage($stage) || self::is_exception_stage($stage);
  }

  private static function is_required_stage(string $stage) : bool {
    return in_array($stage, self::all_required_stages(), true);
  }

  private static function is_exception_stage(string $stage) : bool {
    return in_array($stage, self::all_exception_stages(), true);
  }

  private static function log_transition(int $lead_id, string $from_stage, string $to_stage, string $reason, bool $ok, string $code) : void {
    if (!(defined('WP_DEBUG') && WP_DEBUG)) return;
    if (!function_exists('error_log')) return;

    error_log('[solodrive] ' . wp_json_encode([
      'sd'    => true,
      'event' => 'core_stage_transition',
      'ts'    => gmdate('c'),
      'ctx'   => [
        'lead_id'    => $lead_id,
        'from_stage' => $from_stage,
        'to_stage'   => $to_stage,
        'stage_type' => self::stage_type($to_stage),
        'reason'     => $reason,
        'ok'         => $ok,
        'code'       => $code,
      ],
    ]));
  }
}