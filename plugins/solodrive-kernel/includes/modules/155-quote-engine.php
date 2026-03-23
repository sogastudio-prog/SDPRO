<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Lead-root quote engine.
 *
 * Canon:
 * - runs only after lead becomes AVAILABLE
 * - creates/updates a draft quote for the lead
 * - never presents directly to rider
 * - operator must approve/present later
 */
final class SD_Module_QuoteEngine {

  private static bool $did_register = false;

  public static function register() : void {
    if (self::$did_register) return;
    self::$did_register = true;

    add_action('sd_lead_available', [__CLASS__, 'handle_lead_available'], 20, 3);
  }

  public static function handle_lead_available(int $lead_id, int $tenant_id, array $ctx = []) : void {
    $lead_id   = absint($lead_id);
    $tenant_id = absint($tenant_id);

    if ($lead_id <= 0 || $tenant_id <= 0) {
      return;
    }

    self::ensure_quote_draft($lead_id, [
      'tenant_id' => $tenant_id,
      'context'   => $ctx,
    ]);
  }

  public static function ensure_quote_draft(int $lead_id, array $opts = []) : int {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) return 0;
    if (!class_exists('SD_Module_LeadCPT') || get_post_type($lead_id) !== SD_Module_LeadCPT::CPT) return 0;

    $tenant_id   = isset($opts['tenant_id']) ? absint($opts['tenant_id']) : (int) get_post_meta($lead_id, SD_Meta::TENANT_ID, true);
    $lead_status = (string) get_post_meta($lead_id, SD_Meta::LEAD_STATUS, true);

    if ($tenant_id <= 0) return 0;
    if (!in_array($lead_status, ['LEAD_AVAILABLE', 'LEAD_QUOTING', 'LEAD_QUOTED'], true)) return 0;

    $block_status = (string) get_post_meta($lead_id, SD_Meta::TIMEBLOCK_STATUS, true);
    if (!in_array($block_status, ['HELD', 'COMMITTED'], true)) {
      self::write_lead_quote_error($lead_id, 'missing_held_capacity');
      return 0;
    }

    $quote_id = SD_Module_QuoteService::create_for_lead($lead_id, [], 'quote_engine');
    if ($quote_id <= 0) {
      self::write_lead_quote_error($lead_id, 'quote_create_failed');
      return 0;
    }

    SD_Module_QuoteService::supersede_active_quote($lead_id, $quote_id);

    $draft = self::build_draft($lead_id, $tenant_id, $quote_id);
    if (empty($draft['ok'])) {
      update_post_meta($quote_id, SD_Meta::P_QUOTE_BUILD_ERROR, (string) ($draft['error'] ?? 'quote_build_failed'));
      return $quote_id;
    }

    update_post_meta($quote_id, SD_Meta::P_QUOTE_DRAFT_JSON, wp_json_encode($draft['payload']));
    update_post_meta($quote_id, SD_Meta::P_QUOTE_BUILT_AT, time());
    delete_post_meta($quote_id, SD_Meta::P_QUOTE_BUILD_ERROR);

    update_post_meta($quote_id, SD_Meta::QUOTE_TOTAL_CENTS, (int) $draft['payload']['price']['total_cents']);
    update_post_meta($quote_id, SD_Meta::QUOTE_CURRENCY, (string) $draft['payload']['price']['currency']);
    update_post_meta($quote_id, SD_Meta::QUOTE_CONFIDENCE, (string) $draft['payload']['price']['confidence']);
    update_post_meta($quote_id, SD_Meta::QUOTE_PRESENTABLE_TOTAL, (string) $draft['payload']['price']['display_total']);

    SD_Module_QuoteStateService::set($quote_id, SD_Quote_State::PENDING_OPERATOR, [
      'lead_id'   => $lead_id,
      'tenant_id' => $tenant_id,
      'source'    => 'quote_engine',
    ]);

    if (class_exists('SD_Util')) {
      SD_Util::log('quote_draft_built', [
        'quote_id'   => $quote_id,
        'lead_id'    => $lead_id,
        'tenant_id'  => $tenant_id,
      ]);
    }

    return $quote_id;
  }

  private static function build_draft(int $lead_id, int $tenant_id, int $quote_id) : array {
    $pickup_label  = (string) get_post_meta($lead_id, SD_Meta::PICKUP_ADDRESS, true);
    $dropoff_label = (string) get_post_meta($lead_id, SD_Meta::DROPOFF_ADDRESS, true);

    $route_meters  = (int) get_post_meta($lead_id, SD_Meta::ROUTE_METERS, true);
    $route_seconds = (int) get_post_meta($lead_id, SD_Meta::ROUTE_SECONDS, true);

    $request_mode  = strtoupper((string) get_post_meta($lead_id, SD_Meta::REQUEST_MODE, true));
    if ($request_mode !== 'RESERVE') {
      $request_mode = 'ASAP';
    }

    $requested_ts  = (int) get_post_meta($lead_id, SD_Meta::REQUESTED_TS, true);
    $block_ids_json = (string) get_post_meta($lead_id, SD_Meta::TIMEBLOCK_IDS_JSON, true);
    $block_ids = json_decode($block_ids_json, true);
    if (!is_array($block_ids)) {
      $block_ids = [];
    }

    $currency      = (string) get_post_meta($tenant_id, SD_Meta::CURRENCY, true);
    if ($currency === '') {
      $currency = 'usd';
    }

    $base_fare     = (float) get_post_meta($tenant_id, SD_Meta::BASE_FARE, true);
    $minimum_fare  = (float) get_post_meta($tenant_id, SD_Meta::MINIMUM_FARE, true);
    $per_mile      = (float) get_post_meta($tenant_id, SD_Meta::PER_MILE_RATE, true);
    $per_minute    = (float) get_post_meta($tenant_id, SD_Meta::PER_MINUTE_RATE, true);
    $service_fee   = (float) get_post_meta($tenant_id, SD_Meta::SERVICE_FEE, true);

    $miles   = $route_meters > 0 ? ($route_meters / 1609.344) : 0.0;
    $minutes = $route_seconds > 0 ? ceil($route_seconds / 60) : 0.0;

    $computed = $base_fare + ($miles * $per_mile) + ($minutes * $per_minute) + $service_fee;
    $display  = max($computed, $minimum_fare > 0 ? $minimum_fare : 0);
    $total_cents = (int) round($display * 100);

    if ($total_cents <= 0) {
      return ['ok' => false, 'error' => 'non_positive_quote_total'];
    }

    $confidence = 'medium';
    if ($route_meters > 0 && $route_seconds > 0 && !empty($block_ids)) {
      $confidence = 'high';
    } elseif ($route_meters <= 0 || $route_seconds <= 0) {
      $confidence = 'low';
    }

    $payload = [
      'quote_id'   => $quote_id,
      'lead_id'    => $lead_id,
      'tenant_id'  => $tenant_id,
      'built_at'   => time(),
      'mode'       => $request_mode,
      'requested_ts' => $requested_ts,
      'routing'    => [
        'pickup_label'    => $pickup_label,
        'dropoff_label'   => $dropoff_label,
        'route_meters'    => $route_meters,
        'route_seconds'   => $route_seconds,
        'trip_miles'      => round($miles, 1),
        'trip_minutes'    => (int) ceil($minutes),
      ],
      'capacity'   => [
        'timeblock_ids' => array_values(array_map('intval', $block_ids)),
        'status'        => (string) get_post_meta($lead_id, SD_Meta::TIMEBLOCK_STATUS, true),
      ],
      'price'      => [
        'currency'      => strtolower($currency),
        'total_cents'   => $total_cents,
        'display_total' => self::format_money($total_cents, $currency),
        'confidence'    => $confidence,
        'components'    => [
          'base_fare'    => round($base_fare, 2),
          'per_mile'     => round($per_mile, 2),
          'per_minute'   => round($per_minute, 2),
          'service_fee'  => round($service_fee, 2),
          'minimum_fare' => round($minimum_fare, 2),
        ],
      ],
    ];

    return ['ok' => true, 'payload' => $payload];
  }

  private static function format_money(int $cents, string $currency) : string {
    $amount = number_format($cents / 100, 2);
    $currency = strtoupper($currency);

    if ($currency === 'USD') {
      return '$' . $amount;
    }

    return $currency . ' ' . $amount;
  }

  private static function write_lead_quote_error(int $lead_id, string $error) : void {
    $quote_id = (int) get_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, true);
    if ($quote_id > 0 && get_post_type($quote_id) === SD_Module_QuoteCPT::CPT) {
      update_post_meta($quote_id, SD_Meta::P_QUOTE_BUILD_ERROR, $error);
    }

    if (class_exists('SD_Util')) {
      SD_Util::log('quote_draft_error', [
        'lead_id' => $lead_id,
        'error'   => $error,
      ]);
    }
  }
}