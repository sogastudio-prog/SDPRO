<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Quote_Versioning_Service
 *
 * Purpose:
 * - Create new quote versions for any material commercial change
 * - Supersede prior current quote
 * - Keep one authoritative CURRENT_QUOTE_ID on lead
 *
 * Canon:
 * - One quote engine, many triggers
 * - Every material change creates a new sd_quote record
 * - Old quote is preserved, not mutated into history
 * - Lead has many historical quotes, exactly one current quote pointer
 * - Driver is gate to presentation
 *
 * This service does NOT:
 * - authorize payment
 * - create attempts
 * - create rides
 * - capture payment
 *
 * Suggested sources:
 * - engine_initial
 * - driver_adjustment
 * - rider_change
 * - post_auth_reprice
 * - admin_adjustment
 */

if (class_exists('SD_Quote_Versioning_Service', false)) { return; }

final class SD_Quote_Versioning_Service {

  /**
   * Create a new quote version and make it current.
   *
   * Required args:
   * - lead_id
   *
   * Optional args:
   * - source           string
   * - reason           string
   * - parent_quote_id  int
   * - total_cents      int
   * - currency         string
   * - confidence       string
   * - route_meters     int
   * - route_seconds    int
   * - pricing          array
   * - snapshot         array
   * - requires_driver_approval bool
   * - requires_rider_acceptance bool
   *
   * @return array{
   *   ok: bool,
   *   quote_id?: int,
   *   lead_id?: int,
   *   previous_quote_id?: int,
   *   error?: string
   * }
   */
  public static function create_version(array $args) : array {
    $lead_id = isset($args['lead_id']) ? absint($args['lead_id']) : 0;
    if ($lead_id <= 0) {
      return self::fail('Missing lead_id.');
    }

    if (get_post_type($lead_id) !== SD_Meta::LEAD_CPT) {
      return self::fail('Invalid lead.');
    }

    $tenant_id = absint(get_post_meta($lead_id, SD_Meta::TENANT_ID, true));
    if ($tenant_id <= 0) {
      return self::fail('Lead is missing tenant_id.');
    }

    $source  = self::string_arg($args, 'source', 'engine_initial');
    $reason  = self::string_arg($args, 'reason', '');
    $currency = self::string_arg($args, 'currency', self::tenant_string($tenant_id, SD_Meta::CURRENCY, 'USD'));
    $confidence = self::string_arg($args, 'confidence', 'MEDIUM');

    $provided_parent = isset($args['parent_quote_id']) ? absint($args['parent_quote_id']) : 0;
    $current_quote_id = absint(get_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, true));

    $parent_quote_id = $provided_parent > 0 ? $provided_parent : $current_quote_id;

    // Prevent duplicate version creation when the exact same version data is already current.
    $fingerprint = self::fingerprint($lead_id, $args, $parent_quote_id);
    if ($current_quote_id > 0 && self::quote_has_same_fingerprint($current_quote_id, $fingerprint)) {
      return [
        'ok'                => true,
        'quote_id'          => $current_quote_id,
        'lead_id'           => $lead_id,
        'previous_quote_id' => $parent_quote_id,
      ];
    }

    $quote_data = self::resolve_quote_data($lead_id, $tenant_id, $args);
    if (empty($quote_data['ok'])) {
      return self::fail((string) ($quote_data['error'] ?? 'Could not resolve quote data.'));
    }

    $version = self::next_version_number($lead_id);

    $quote_id = wp_insert_post([
      'post_type'   => SD_Meta::QUOTE_CPT,
      'post_status' => 'publish',
      'post_title'  => self::build_quote_title($lead_id, $version, $source),
    ], true);

    if (is_wp_error($quote_id) || (int) $quote_id <= 0) {
      return self::fail('Could not create quote version.');
    }

    $quote_id = (int) $quote_id;

    // Core quote identity
    update_post_meta($quote_id, SD_Meta::TENANT_ID, $tenant_id);
    update_post_meta($quote_id, SD_Meta::LEAD_ID, $lead_id);
    update_post_meta($quote_id, SD_Meta::QUOTE_STATUS, SD_Meta::QUOTE_PROPOSED);

    // Commercial data
    update_post_meta($quote_id, SD_Meta::QUOTE_TOTAL_CENTS, (int) $quote_data['total_cents']);
    update_post_meta($quote_id, SD_Meta::QUOTE_CURRENCY, $currency);
    update_post_meta($quote_id, SD_Meta::QUOTE_CONFIDENCE, $confidence);
    update_post_meta($quote_id, SD_Meta::QUOTE_PRESENTABLE_TOTAL, self::format_money((int) $quote_data['total_cents'], $currency));

    if (isset($quote_data['route_meters'])) {
      update_post_meta($quote_id, SD_Meta::ROUTE_METERS, (int) $quote_data['route_meters']);
    }
    if (isset($quote_data['route_seconds'])) {
      update_post_meta($quote_id, SD_Meta::ROUTE_SECONDS, (int) $quote_data['route_seconds']);
    }

    // Private versioning/audit metadata
    update_post_meta($quote_id, '_sd_quote_version', $version);
    update_post_meta($quote_id, '_sd_quote_source', $source);
    update_post_meta($quote_id, '_sd_quote_reason', $reason);
    update_post_meta($quote_id, '_sd_quote_fingerprint', $fingerprint);

    if ($parent_quote_id > 0) {
      update_post_meta($quote_id, '_sd_parent_quote_id', $parent_quote_id);
    }

    update_post_meta($quote_id, '_sd_requires_driver_approval', !empty($args['requires_driver_approval']) ? '1' : '0');
    update_post_meta($quote_id, '_sd_requires_rider_acceptance', !empty($args['requires_rider_acceptance']) ? '1' : '0');

    if (!empty($quote_data['pricing'])) {
      update_post_meta($quote_id, '_sd_quote_pricing_json', wp_json_encode($quote_data['pricing']));
    }

    if (!empty($quote_data['snapshot'])) {
      update_post_meta($quote_id, '_sd_quote_snapshot_json', wp_json_encode($quote_data['snapshot']));
    }

    update_post_meta($quote_id, SD_Meta::P_QUOTE_JOB_STATE, 'ok');
    update_post_meta($quote_id, SD_Meta::P_QUOTE_BUILT_AT, time());
    update_post_meta($quote_id, SD_Meta::P_QUOTE_STATUS_UPDATED_AT, time());

    // Supersede previous current quote, if any.
    $previous_quote_id = 0;
    if ($current_quote_id > 0 && $current_quote_id !== $quote_id && get_post_type($current_quote_id) === SD_Meta::QUOTE_CPT) {
      $previous_quote_id = $current_quote_id;
      self::supersede_quote($current_quote_id);
    }

    // Promote new version to current.
    update_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, $quote_id);
    delete_post_meta($lead_id, SD_Meta::P_QUOTE_BUILD_ERROR);
    update_post_meta($lead_id, SD_Meta::P_QUOTE_BUILT_AT, time());
    update_post_meta($lead_id, SD_Meta::P_QUOTE_DRAFT_JSON, wp_json_encode([
      'source'      => $source,
      'reason'      => $reason,
      'version'     => $version,
      'total_cents' => (int) $quote_data['total_cents'],
      'currency'    => $currency,
      'confidence'  => $confidence,
    ]));

    do_action('sd_quote_version_created', $quote_id, $lead_id, [
      'previous_quote_id' => $previous_quote_id,
      'parent_quote_id'   => $parent_quote_id,
      'source'            => $source,
      'reason'            => $reason,
      'version'           => $version,
      'total_cents'       => (int) $quote_data['total_cents'],
      'currency'          => $currency,
    ]);

    return [
      'ok'                => true,
      'quote_id'          => $quote_id,
      'lead_id'           => $lead_id,
      'previous_quote_id' => $previous_quote_id,
    ];
  }

  /**
   * Driver-initiated pre-auth adjustment.
   */
  public static function create_driver_adjustment(int $lead_id, array $overrides = [], string $reason = '') : array {
    $base = self::quote_context_from_current($lead_id);
    if (empty($base['ok'])) {
      return $base;
    }

    return self::create_version(array_merge($base['args'], $overrides, [
      'lead_id'                    => $lead_id,
      'source'                     => 'driver_adjustment',
      'reason'                     => $reason,
      'requires_driver_approval'   => false,
      'requires_rider_acceptance'  => true,
    ]));
  }

  /**
   * Rider/payor initiated change request.
   * Driver remains the gate to presentation.
   */
  public static function create_rider_change_quote(int $lead_id, array $overrides = [], string $reason = '') : array {
    $base = self::quote_context_from_current($lead_id);
    if (empty($base['ok'])) {
      return $base;
    }

    return self::create_version(array_merge($base['args'], $overrides, [
      'lead_id'                    => $lead_id,
      'source'                     => 'rider_change',
      'reason'                     => $reason,
      'requires_driver_approval'   => true,
      'requires_rider_acceptance'  => true,
    ]));
  }

  /**
   * Mark an existing quote as superseded.
   */
  public static function supersede_quote(int $quote_id) : void {
    $quote_id = absint($quote_id);
    if ($quote_id <= 0) return;
    if (get_post_type($quote_id) !== SD_Meta::QUOTE_CPT) return;

    update_post_meta($quote_id, SD_Meta::QUOTE_STATUS, SD_Meta::QUOTE_SUPERSEDED);
    update_post_meta($quote_id, SD_Meta::P_QUOTE_STATUS_UPDATED_AT, time());
  }

  /**
   * Build a new quote from the current quote context when adjusting.
   */
  private static function quote_context_from_current(int $lead_id) : array {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0 || get_post_type($lead_id) !== SD_Meta::LEAD_CPT) {
      return self::fail('Invalid lead.');
    }

    $current_quote_id = absint(get_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, true));
    if ($current_quote_id <= 0 || get_post_type($current_quote_id) !== SD_Meta::QUOTE_CPT) {
      return self::fail('No current quote found.');
    }

    return [
      'ok' => true,
      'args' => [
        'parent_quote_id' => $current_quote_id,
        'total_cents'     => absint(get_post_meta($current_quote_id, SD_Meta::QUOTE_TOTAL_CENTS, true)),
        'currency'        => (string) get_post_meta($current_quote_id, SD_Meta::QUOTE_CURRENCY, true),
        'confidence'      => (string) get_post_meta($current_quote_id, SD_Meta::QUOTE_CONFIDENCE, true),
        'route_meters'    => absint(get_post_meta($current_quote_id, SD_Meta::ROUTE_METERS, true)),
        'route_seconds'   => absint(get_post_meta($current_quote_id, SD_Meta::ROUTE_SECONDS, true)),
      ],
    ];
  }

  /**
   * Resolve quote data for the new version.
   * Accepts explicit overrides; otherwise falls back to current lead/current quote context.
   */
  private static function resolve_quote_data(int $lead_id, int $tenant_id, array $args) : array {
    $total_cents = isset($args['total_cents']) ? absint($args['total_cents']) : 0;
    $route_meters = isset($args['route_meters']) ? absint($args['route_meters']) : 0;
    $route_seconds = isset($args['route_seconds']) ? absint($args['route_seconds']) : 0;
    $pricing = isset($args['pricing']) && is_array($args['pricing']) ? $args['pricing'] : [];
    $snapshot = isset($args['snapshot']) && is_array($args['snapshot']) ? $args['snapshot'] : [];

    // If total is explicitly supplied, trust it.
    if ($total_cents > 0) {
      return [
        'ok'            => true,
        'total_cents'   => $total_cents,
        'route_meters'  => $route_meters,
        'route_seconds' => $route_seconds,
        'pricing'       => $pricing,
        'snapshot'      => $snapshot,
      ];
    }

    // Otherwise compute from route metrics, or from current quote if route metrics absent.
    if ($route_meters <= 0) {
      $route_meters = absint(get_post_meta($lead_id, SD_Meta::ROUTE_METERS, true));
    }
    if ($route_seconds <= 0) {
      $route_seconds = absint(get_post_meta($lead_id, SD_Meta::ROUTE_SECONDS, true));
    }

    $base_fare      = self::tenant_money_float($tenant_id, SD_Meta::BASE_FARE, 0);
    $minimum_fare   = self::tenant_money_float($tenant_id, SD_Meta::MINIMUM_FARE, 0);
    $per_mile_rate  = self::tenant_money_float($tenant_id, SD_Meta::PER_MILE_RATE, 0);
    $per_min_rate   = self::tenant_money_float($tenant_id, SD_Meta::PER_MINUTE_RATE, 0);
    $service_fee    = self::tenant_money_float($tenant_id, SD_Meta::SERVICE_FEE, 0);

    $miles   = ($route_meters > 0)  ? ($route_meters / 1609.344) : 0.0;
    $minutes = ($route_seconds > 0) ? ($route_seconds / 60.0)    : 0.0;

    $computed = $base_fare + ($miles * $per_mile_rate) + ($minutes * $per_min_rate) + $service_fee;
    $computed = max($computed, $minimum_fare);

    if ($computed <= 0) {
      return [
        'ok'    => false,
        'error' => 'Quote total resolved to zero.',
      ];
    }

    return [
      'ok'            => true,
      'total_cents'   => (int) round($computed * 100),
      'route_meters'  => $route_meters,
      'route_seconds' => $route_seconds,
      'pricing'       => !empty($pricing) ? $pricing : [
        'base_fare'       => $base_fare,
        'minimum_fare'    => $minimum_fare,
        'per_mile_rate'   => $per_mile_rate,
        'per_minute_rate' => $per_min_rate,
        'service_fee'     => $service_fee,
      ],
      'snapshot'      => $snapshot,
    ];
  }

  private static function next_version_number(int $lead_id) : int {
    $quotes = get_posts([
      'post_type'      => SD_Meta::QUOTE_CPT,
      'post_status'    => 'publish',
      'posts_per_page' => 1,
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

    if (empty($quotes)) {
      return 1;
    }

    $last_version = (int) get_post_meta((int) $quotes[0], '_sd_quote_version', true);
    return max(1, $last_version + 1);
  }

  private static function fingerprint(int $lead_id, array $args, int $parent_quote_id) : string {
    $payload = [
      'lead_id'         => $lead_id,
      'parent_quote_id' => $parent_quote_id,
      'source'          => self::string_arg($args, 'source', ''),
      'reason'          => self::string_arg($args, 'reason', ''),
      'total_cents'     => isset($args['total_cents']) ? absint($args['total_cents']) : 0,
      'currency'        => self::string_arg($args, 'currency', ''),
      'confidence'      => self::string_arg($args, 'confidence', ''),
      'route_meters'    => isset($args['route_meters']) ? absint($args['route_meters']) : 0,
      'route_seconds'   => isset($args['route_seconds']) ? absint($args['route_seconds']) : 0,
      'pricing'         => isset($args['pricing']) && is_array($args['pricing']) ? $args['pricing'] : [],
      'snapshot'        => isset($args['snapshot']) && is_array($args['snapshot']) ? $args['snapshot'] : [],
      'rda'             => !empty($args['requires_driver_approval']) ? 1 : 0,
      'rra'             => !empty($args['requires_rider_acceptance']) ? 1 : 0,
    ];

    return hash('sha256', wp_json_encode($payload));
  }

  private static function quote_has_same_fingerprint(int $quote_id, string $fingerprint) : bool {
    $current = (string) get_post_meta($quote_id, '_sd_quote_fingerprint', true);
    return $current !== '' && hash_equals($current, $fingerprint);
  }

  private static function build_quote_title(int $lead_id, int $version, string $source) : string {
    return sprintf('Quote v%d — Lead #%d — %s', $version, $lead_id, $source);
  }

  private static function format_money(int $cents, string $currency = 'USD') : string {
    $amount = number_format($cents / 100, 2, '.', ',');
    return strtoupper($currency) . ' ' . $amount;
  }

  private static function string_arg(array $args, string $key, string $default = '') : string {
    if (!array_key_exists($key, $args)) return $default;
    $value = $args[$key];
    return is_scalar($value) ? trim((string) $value) : $default;
  }

  private static function tenant_string(int $tenant_id, string $key, string $default = '') : string {
    $value = get_post_meta($tenant_id, $key, true);
    return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $default;
  }

  private static function tenant_money_float(int $tenant_id, string $key, float $default = 0.0) : float {
    $value = get_post_meta($tenant_id, $key, true);
    return is_numeric($value) ? (float) $value : $default;
  }

  private static function fail(string $error) : array {
    return [
      'ok'    => false,
      'error' => $error,
    ];
  }
}