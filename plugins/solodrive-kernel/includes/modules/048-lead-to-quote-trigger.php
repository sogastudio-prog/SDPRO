<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_LeadToQuoteTrigger
 *
 * Purpose:
 * - Create exactly one initial quote when the lead enters LEAD_NEEDS_QUOTE
 * - Prevent duplicate quote creation at the stage boundary
 *
 * Canon:
 * - Lead is the engagement root
 * - Initial quote is a child artifact of lead
 * - Initial quote creation is stage-gated
 * - This module does NOT create attempts or rides
 * - This module does NOT version or adjust quotes; initial quote only
 *
 * Stage ownership:
 * - ONLY runs on sd_core_stage_LEAD_NEEDS_QUOTE
 * - All other systems must advance the lead into quote stage
 * - No other hook may create the initial quote directly
 */

if (class_exists('SD_Module_LeadToQuoteTrigger', false)) { return; }

final class SD_Module_LeadToQuoteTrigger {

  private const JOB_STATE_KEY = '_sd_quote_job_state';

  public static function register() : void {
    add_action('sd_core_stage_LEAD_NEEDS_QUOTE', [__CLASS__, 'handle_stage'], 10, 3);
  }

  /**
   * Stage-gated initial quote creation.
   *
   * Hook signature from SD_CoreStage:
   * do_action('sd_core_stage_' . $stage, $lead_id, $from_stage, $reason);
   */
  public static function handle_stage($lead_id, $from_stage = '', $reason = '') : void {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) return;
    if (get_post_type($lead_id) !== SD_Module_LeadCPT::CPT) return;

    if (!class_exists('SD_CoreStage', false)) return;

    // Hard stage guard: only run in LEAD_NEEDS_QUOTE.
    if (SD_CoreStage::current_stage($lead_id) !== SD_CoreStage::LEAD_NEEDS_QUOTE) {
      return;
    }

    // Idempotency guard 1: usable current quote already exists.
    $current_quote_id = absint(get_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, true));
    if ($current_quote_id > 0 && get_post_type($current_quote_id) === SD_Meta::QUOTE_CPT) {
      SD_CoreStage::advance(
        $lead_id,
        SD_CoreStage::LEAD_NEEDS_DRIVER_REVIEW,
        'Usable current quote already exists.'
      );
      return;
    }

    // Idempotency guard 2: if a prior usable quote exists, reuse it.
    $existing_quote_id = self::find_existing_currentish_quote($lead_id);
    if ($existing_quote_id > 0) {
      update_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, $existing_quote_id);
      delete_post_meta($lead_id, SD_Meta::P_QUOTE_BUILD_ERROR);
      update_post_meta($lead_id, self::JOB_STATE_KEY, 'done');

      SD_CoreStage::advance(
        $lead_id,
        SD_CoreStage::LEAD_NEEDS_DRIVER_REVIEW,
        'Reused existing quote.'
      );
      return;
    }

    // Concurrency / repeat-load guard.
    $job_state = (string) get_post_meta($lead_id, self::JOB_STATE_KEY, true);
    if ($job_state === 'running') {
      return;
    }

    update_post_meta($lead_id, self::JOB_STATE_KEY, 'running');

    try {
      $tenant_id = absint(get_post_meta($lead_id, SD_Meta::TENANT_ID, true));
      if ($tenant_id <= 0) {
        throw new \Exception('Missing tenant_id on lead.');
      }

      $quote = self::build_initial_quote_payload($lead_id, $tenant_id);
      if (empty($quote['ok'])) {
        throw new \Exception((string) ($quote['error'] ?? 'Quote build failed.'));
      }

      $quote_id = wp_insert_post([
        'post_type'   => SD_Meta::QUOTE_CPT,
        'post_status' => 'publish',
        'post_title'  => self::build_quote_title($lead_id),
        'meta_input'  => [
        SD_Meta::TENANT_ID => $tenant_id,
        SD_Meta::LEAD_ID   => $lead_id,
        ],
      ], true);

      if (is_wp_error($quote_id) || (int) $quote_id <= 0) {
        throw new \Exception('Could not create quote record.');
      }

      $quote_id = (int) $quote_id;


      update_post_meta($quote_id, SD_Meta::QUOTE_STATUS, SD_Meta::QUOTE_PROPOSED);

      update_post_meta($quote_id, SD_Meta::QUOTE_TOTAL_CENTS, (int) $quote['total_cents']);
      update_post_meta($quote_id, SD_Meta::QUOTE_CURRENCY, (string) $quote['currency']);
      update_post_meta($quote_id, SD_Meta::QUOTE_CONFIDENCE, (string) $quote['confidence']);
      update_post_meta(
        $quote_id,
        SD_Meta::QUOTE_PRESENTABLE_TOTAL,
        self::format_money((int) $quote['total_cents'], (string) $quote['currency'])
      );

      update_post_meta($quote_id, SD_Meta::ROUTE_METERS, (int) $quote['route_meters']);
      update_post_meta($quote_id, SD_Meta::ROUTE_SECONDS, (int) $quote['route_seconds']);

      update_post_meta($quote_id, SD_Meta::P_QUOTE_JOB_STATE, 'ok');
      update_post_meta($quote_id, SD_Meta::P_QUOTE_BUILT_AT, time());
      update_post_meta($quote_id, SD_Meta::P_QUOTE_STATUS_UPDATED_AT, time());

      // Lead pointers.
      update_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, $quote_id);
      delete_post_meta($lead_id, SD_Meta::P_QUOTE_BUILD_ERROR);
      update_post_meta($lead_id, SD_Meta::P_QUOTE_BUILT_AT, time());
      update_post_meta($lead_id, SD_Meta::P_QUOTE_DRAFT_JSON, wp_json_encode($quote));
      update_post_meta($lead_id, self::JOB_STATE_KEY, 'done');

      // Advance stage only after successful quote creation.
      SD_CoreStage::advance(
        $lead_id,
        SD_CoreStage::LEAD_NEEDS_DRIVER_REVIEW,
        'Initial quote created.'
      );

      /**
       * Downstream hook for operator review / presentation flow.
       * Safe because initial quote creation is already stage-gated.
       */
      do_action('sd_quote_created', $quote_id, $lead_id, $tenant_id, $quote);

    } catch (\Throwable $e) {
      update_post_meta($lead_id, self::JOB_STATE_KEY, 'error');
      self::mark_quote_build_error($lead_id, $e->getMessage());
    }
  }

  /**
   * Build the initial quote payload.
   *
   * Current strategy:
   * - Use route_meters / route_seconds from lead if already present
   * - Otherwise tolerate 0 values and still build from tenant pricing minimum/base
   * - Initial quote remains PROPOSED for human review/presentation
   */
  private static function build_initial_quote_payload(int $lead_id, int $tenant_id) : array {
    $route_meters  = absint(get_post_meta($lead_id, SD_Meta::ROUTE_METERS, true));
    $route_seconds = absint(get_post_meta($lead_id, SD_Meta::ROUTE_SECONDS, true));

    $base_fare     = self::tenant_money_float($tenant_id, SD_Meta::BASE_FARE, 0);
    $minimum_fare  = self::tenant_money_float($tenant_id, SD_Meta::MINIMUM_FARE, 0);
    $per_mile_rate = self::tenant_money_float($tenant_id, SD_Meta::PER_MILE_RATE, 0);
    $per_min_rate  = self::tenant_money_float($tenant_id, SD_Meta::PER_MINUTE_RATE, 0);
    $service_fee   = self::tenant_money_float($tenant_id, SD_Meta::SERVICE_FEE, 0);
    $currency      = self::tenant_string($tenant_id, SD_Meta::CURRENCY, 'USD');

    $miles   = ($route_meters > 0)  ? ($route_meters / 1609.344) : 0.0;
    $minutes = ($route_seconds > 0) ? ($route_seconds / 60.0)    : 0.0;

    $computed = $base_fare + ($miles * $per_mile_rate) + ($minutes * $per_min_rate) + $service_fee;
    $computed = max($computed, $minimum_fare);

    if ($computed <= 0) {
      return [
        'ok'    => false,
        'error' => 'Tenant pricing is incomplete; quote total resolved to zero.',
      ];
    }

    $total_cents = (int) round($computed * 100);

    return [
      'ok'            => true,
      'total_cents'   => $total_cents,
      'currency'      => $currency,
      'confidence'    => ($route_meters > 0 && $route_seconds > 0) ? 'HIGH' : 'LOW',
      'route_meters'  => $route_meters,
      'route_seconds' => $route_seconds,
      'miles'         => round($miles, 2),
      'minutes'       => round($minutes, 1),
      'pricing'       => [
        'base_fare'       => $base_fare,
        'minimum_fare'    => $minimum_fare,
        'per_mile_rate'   => $per_mile_rate,
        'per_minute_rate' => $per_min_rate,
        'service_fee'     => $service_fee,
      ],
    ];
  }

  /**
   * Reuse an existing current-ish quote if one exists.
   * Prevents duplicate quotes under repeated trigger firing.
   */
  private static function find_existing_currentish_quote(int $lead_id) : int {
    $quotes = get_posts([
      'post_type'      => SD_Meta::QUOTE_CPT,
      'post_status'    => 'publish',
      'posts_per_page' => 5,
      'orderby'        => 'ID',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'   => SD_Meta::LEAD_ID,
          'value' => $lead_id,
        ],
      ],
    ]);

    if (empty($quotes)) return 0;

    foreach ($quotes as $quote_id) {
      $status = (string) get_post_meta((int) $quote_id, SD_Meta::QUOTE_STATUS, true);
      if (!in_array($status, [
        SD_Meta::QUOTE_CANCELLED,
        SD_Meta::QUOTE_SUPERSEDED,
        SD_Meta::QUOTE_EXPIRED,
        SD_Meta::QUOTE_USER_REJECTED,
        SD_Meta::QUOTE_LEAD_REJECTED,
      ], true)) {
        return (int) $quote_id;
      }
    }

    return 0;
  }

  private static function mark_quote_build_error(int $lead_id, string $message) : void {
    update_post_meta($lead_id, SD_Meta::P_QUOTE_JOB_STATE, 'error');
    update_post_meta($lead_id, SD_Meta::P_QUOTE_BUILD_ERROR, $message);
  }

  private static function build_quote_title(int $lead_id) : string {
    $requested_ts = absint(get_post_meta($lead_id, SD_Meta::REQUESTED_TS, true));
    $when = $requested_ts > 0 ? gmdate('Y-m-d H:i', $requested_ts) . ' UTC' : 'pending-time';
    return sprintf('Quote — Lead #%d — %s', $lead_id, $when);
  }

  private static function format_money(int $cents, string $currency = 'USD') : string {
    $amount = number_format($cents / 100, 2, '.', ',');
    return strtoupper($currency) . ' ' . $amount;
  }

  /**
   * Reads tenant-scoped config from tenant post meta.
   * This avoids hard dependency on a specific config wrapper.
   */
  private static function tenant_string(int $tenant_id, string $key, string $default = '') : string {
    $value = get_post_meta($tenant_id, $key, true);
    return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
  }

  private static function tenant_money_float(int $tenant_id, string $key, float $default = 0.0) : float {
    $value = get_post_meta($tenant_id, $key, true);
    return is_numeric($value) ? (float) $value : $default;
  }
}