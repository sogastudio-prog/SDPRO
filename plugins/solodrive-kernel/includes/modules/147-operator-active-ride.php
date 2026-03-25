<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorActiveRide (lead-root refactor)
 *
 * Purpose:
 * - Build the stable lead-root operator header/intel payload used by Drive Mode
 * - Keep payload composition separate from page rendering
 *
 * Canon:
 * - sd_lead is the canonical context
 * - quote / attempt / ride are hydrated as children of lead
 * - ride only exists after successful authorization
 * - stable header, state-governed body
 * - tenant/operator context is read-only here
 * - quote draft payload is parsed but not authored here
 *
 * Intended consumers:
 * - 144-operator-drive-mode.php
 * - future operator widgets / lead detail panels
 */

if (class_exists('SD_Module_OperatorActiveRide', false)) { return; }

final class SD_Module_OperatorActiveRide {

  /**
   * Build the operator payload for a selected lead and tenant.
   *
   * Return shape:
   * [
   *   'tenant_id'             => int,
   *   'tenant_name'           => string,
   *   'lead_id'               => int,
   *   'quote_id'              => int,
   *   'attempt_id'            => int,
   *   'ride_id'               => int,
   *   'trip_token'            => string,
   *   'customer_name'         => string,
   *   'customer_phone'        => string,
   *   'pickup_text'           => string,
   *   'dropoff_text'          => string,
   *   'scheduled_ts'          => int,
   *   'lead_status'           => string,
   *   'ride_state'            => string,
   *   'quote_status'          => string,
   *   'attempt_status'        => string,
   *   'requested_at'          => int,
   *   'updated_at'            => int,
   *   'authorized_at'         => int,
   *   'captured_at'           => int,
   *   'capture_error'         => string,
   *   'operator_status'       => string,
   *   'operator_status_label' => string,
   *   'live_location_label'   => string,
   *   'last_known_loc'        => string,
   *   'last_ping_ts'          => int,
   *   'last_ping_ago'         => string,
   *   'base_location_label'   => string,
   *   'quote_total_cents'     => int,
   *   'quote_currency'        => string,
   *   'pickup_eta_min'        => int,
   *   'confidence_label'      => string,
   *   'miles_to_pickup'       => float,
   *   'trip_miles'            => float,
   *   'trip_mins'             => int,
   *   'tot_per_60'            => float,
   *   'tot_per_mile'          => float,
   * ]
   */
  public static function build(int $lead_id, int $tenant_id) : array {
    $lead_id   = absint($lead_id);
    $tenant_id = absint($tenant_id);

    $operator = self::operator_context();

    if ($lead_id <= 0) {
      return self::empty_payload($tenant_id, $operator);
    }

    $quote_id   = self::latest_quote_id_for_lead($lead_id);
    $attempt    = self::latest_attempt_for_lead($lead_id);
    $attempt_id = (int) ($attempt['attempt_id'] ?? 0);
    $ride_id    = self::latest_ride_id_for_lead($lead_id);

    if (
      $quote_id > 0 &&
      class_exists('SD_Module_QuoteEngine', false) &&
      method_exists('SD_Module_QuoteEngine', 'ensure_quote_draft')
    ) {
      SD_Module_QuoteEngine::ensure_quote_draft($lead_id, [
        'tenant_id' => $tenant_id,
        'timeout'   => 8,
        'force'     => false,
      ]);

      $quote_id = self::latest_quote_id_for_lead($lead_id);
    }

    $draft = self::parse_quote_draft_payload($quote_id);

    return [
      'tenant_id'             => $tenant_id,
      'tenant_name'           => $tenant_id > 0 ? (string) get_the_title($tenant_id) : '',
      'lead_id'               => $lead_id,
      'quote_id'              => $quote_id,
      'attempt_id'            => $attempt_id,
      'ride_id'               => $ride_id,
      'trip_token'            => (string) get_post_meta($lead_id, self::meta_key('TRIP_TOKEN', 'sog_trip_token'), true),
      'customer_name'         => self::resolve_person_text($lead_id, $ride_id, ['CUSTOMER_NAME', 'customer_name']),
      'customer_phone'        => self::resolve_person_text($lead_id, $ride_id, ['CUSTOMER_PHONE', 'customer_phone']),
      'pickup_text'           => self::resolve_person_text($lead_id, $ride_id, ['PICKUP_TEXT', 'pickup_text']),
      'dropoff_text'          => self::resolve_person_text($lead_id, $ride_id, ['DROPOFF_TEXT', 'dropoff_text']),
      'scheduled_ts'          => self::resolve_scheduled_ts($lead_id, $quote_id, $ride_id),
      'lead_status'           => (string) get_post_meta($lead_id, self::meta_key('LEAD_STATUS', 'sog_lead_status'), true),
      'ride_state'            => $ride_id > 0 ? (string) get_post_meta($ride_id, self::meta_key('RIDE_STATE', 'state'), true) : '',
      'quote_status'          => $quote_id > 0 ? (string) get_post_meta($quote_id, self::meta_key('QUOTE_STATUS', 'sog_quote_status'), true) : '',
      'attempt_status'        => (string) ($attempt['status'] ?? ''),
      'requested_at'          => (int) get_post_time('U', true, $lead_id),
      'updated_at'            => self::resolve_updated_at($lead_id, $quote_id, $attempt_id, $ride_id),
      'authorized_at'         => (int) ($attempt['authorized_at'] ?? 0),
      'captured_at'           => (int) ($attempt['captured_at'] ?? 0),
      'capture_error'         => (string) ($attempt['capture_error'] ?? ''),
      'operator_status'       => (string) ($operator['status'] ?? 'offline'),
      'operator_status_label' => (string) ($operator['status_label'] ?? 'OFFLINE'),
      'live_location_label'   => (string) ($operator['live_location_label'] ?? 'missing'),
      'last_known_loc'        => self::format_last_known_loc(
        (float) ($operator['last_lat'] ?? 0),
        (float) ($operator['last_lng'] ?? 0)
      ),
      'last_ping_ts'          => (int) ($operator['last_ts'] ?? 0),
      'last_ping_ago'         => self::human_time((int) ($operator['last_ts'] ?? 0)),
      'base_location_label'   => (string) ($operator['base_location_label'] ?? 'missing'),
      'quote_total_cents'     => (int) ($draft['quote']['total_cents'] ?? 0),
      'quote_currency'        => (string) ($draft['quote']['currency'] ?? 'usd'),
      'pickup_eta_min'        => (int) ($draft['quote']['pickup_eta_min'] ?? 0),
      'confidence_label'      => (string) ($draft['quote']['confidence_label'] ?? 'Missing deadhead'),
      'miles_to_pickup'       => (float) ($draft['ops']['miles_to_pickup'] ?? 0.0),
      'trip_miles'            => (float) ($draft['ops']['trip_miles'] ?? 0.0),
      'trip_mins'             => (int) ($draft['ops']['trip_mins'] ?? 0),
      'tot_per_60'            => (float) ($draft['ops']['tot_per_60'] ?? 0.0),
      'tot_per_mile'          => (float) ($draft['ops']['tot_per_mile'] ?? 0.0),
    ];
  }

  /**
   * Title helper for execution state.
   */
  public static function ride_state_title(string $ride_state) : string {
    switch ($ride_state) {
      case 'RIDE_QUEUED':
        return 'Ready to dispatch';
      case 'RIDE_DEADHEAD':
        return 'En route to pickup';
      case 'RIDE_WAITING':
        return 'Waiting at pickup';
      case 'RIDE_INPROGRESS':
        return 'Trip in progress';
      case 'RIDE_ARRIVED':
        return 'Arrived at destination';
      case 'RIDE_COMPLETE':
        return 'Ride complete';
      case 'RIDE_CANCELLED':
        return 'Ride cancelled';
      default:
        return 'Open lead';
    }
  }

  /**
   * Title helper for lead-root lifecycle before promotion.
   */
  public static function lead_state_title(string $lead_status, string $quote_status = '', string $attempt_status = '', string $ride_state = '') : string {
    if ($ride_state !== '') {
      return self::ride_state_title($ride_state);
    }

    if (in_array($quote_status, ['PROPOSED', 'APPROVED', 'PRESENTED'], true) || $lead_status === 'LEAD_WAITING_QUOTE') {
      return 'Quote awaiting operator action';
    }

    if ($quote_status === 'LEAD_ACCEPTED' && !in_array($attempt_status, ['AUTHORIZED', 'SUCCEEDED'], true)) {
      return 'Authorization pending';
    }

    if ($lead_status === 'LEAD_PROMOTED') {
      return 'Ready to dispatch';
    }

    switch ($lead_status) {
      case 'LEAD_CAPTURED':
        return 'Lead captured';
      case 'LEAD_WAITING_QUOTE':
        return 'Preparing quote';
      case 'LEAD_OFFERED':
        return 'Offer in flight';
      case 'LEAD_PROMOTED':
        return 'Lead promoted';
      case 'LEAD_DECLINED':
        return 'Lead declined';
      case 'LEAD_EXPIRED':
        return 'Lead expired';
      case 'LEAD_AUTH_FAILED':
        return 'Authorization failed';
      default:
        return 'Open lead';
    }
  }

  /**
   * Available next execution actions for state-driven body.
   */
  public static function ride_progress_actions(string $ride_state) : array {
    switch ($ride_state) {
      case 'RIDE_QUEUED':
        return [
          ['label' => 'Start deadhead', 'to_state' => 'RIDE_DEADHEAD', 'primary' => true],
        ];

      case 'RIDE_DEADHEAD':
        return [
          ['label' => 'Arrived at pickup', 'to_state' => 'RIDE_WAITING', 'primary' => true],
        ];

      case 'RIDE_WAITING':
        return [
          ['label' => 'Begin trip', 'to_state' => 'RIDE_INPROGRESS', 'primary' => true],
        ];

      case 'RIDE_INPROGRESS':
        return [
          ['label' => 'Arrived at destination', 'to_state' => 'RIDE_ARRIVED', 'primary' => true],
        ];

      case 'RIDE_ARRIVED':
        return [
          ['label' => 'Complete ride & capture', 'to_state' => 'RIDE_COMPLETE', 'primary' => true],
        ];

      default:
        return [];
    }
  }

  /**
   * Lead-root pre-promotion actions.
   */
  public static function lead_progress_actions(string $lead_status, string $quote_status = '', string $attempt_status = '', string $ride_state = '') : array {
    if ($ride_state !== '') {
      return self::ride_progress_actions($ride_state);
    }

    if (in_array($quote_status, ['PROPOSED', 'APPROVED', 'PRESENTED'], true) || $lead_status === 'LEAD_WAITING_QUOTE') {
      return [
        ['label' => 'Review quote', 'action' => 'review_quote', 'primary' => true],
      ];
    }

    if ($quote_status === 'LEAD_ACCEPTED' && !in_array($attempt_status, ['AUTHORIZED', 'SUCCEEDED'], true)) {
      return [
        ['label' => 'Review authorization', 'action' => 'review_authorization', 'primary' => true],
      ];
    }

    if ($lead_status === 'LEAD_PROMOTED') {
      return [
        ['label' => 'Open dispatch', 'action' => 'open_dispatch', 'primary' => true],
      ];
    }

    return [];
  }

  /**
   * Latest quote id for a lead.
   */
  public static function latest_quote_id_for_lead(int $lead_id) : int {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) return 0;

    $pointer_keys = [
      'sd_current_quote_id',
      'sd_active_quote_id',
      self::meta_key('QUOTE_ID', 'sd_quote_id'),
    ];

    foreach ($pointer_keys as $key) {
      if (!$key) { continue; }
      $quote_id = (int) get_post_meta($lead_id, $key, true);
      if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
        return $quote_id;
      }
    }

    $ids = get_posts([
      'post_type'      => 'sd_quote',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => self::lead_child_meta_key(),
          'value'   => $lead_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  /**
   * Latest attempt summary for a lead.
   */
  public static function latest_attempt_for_lead(int $lead_id) : array {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) return [];

    $ids = get_posts([
      'post_type'      => 'sd_attempt',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => self::lead_child_meta_key(),
          'value'   => $lead_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    $attempt_id = !empty($ids[0]) ? (int) $ids[0] : 0;
    if ($attempt_id <= 0) return [];

    return [
      'attempt_id'    => $attempt_id,
      'status'        => (string) get_post_meta($attempt_id, self::attempt_status_meta_key(), true),
      'authorized_at' => (int) get_post_meta($attempt_id, self::attempt_authorized_meta_key(), true),
      'captured_at'   => (int) get_post_meta($attempt_id, self::attempt_captured_meta_key(), true),
      'capture_error' => (string) get_post_meta($attempt_id, self::attempt_capture_error_meta_key(), true),
    ];
  }

  /**
   * Latest ride id for a lead.
   */
  public static function latest_ride_id_for_lead(int $lead_id) : int {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) return 0;

    $pointer_keys = [
      'sd_promoted_ride_id',
      'sd_active_ride_id',
      self::meta_key('RIDE_ID', 'sd_ride_id'),
    ];

    foreach ($pointer_keys as $key) {
      if (!$key) { continue; }
      $ride_id = (int) get_post_meta($lead_id, $key, true);
      if ($ride_id > 0 && get_post_type($ride_id) === 'sd_ride') {
        return $ride_id;
      }
    }

    $ids = get_posts([
      'post_type'      => 'sd_ride',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => self::lead_child_meta_key(),
          'value'   => $lead_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  /**
   * Parse quote draft payload from quote meta.
   */
  public static function parse_quote_draft_payload(int $quote_id) : array {
    $quote_id = absint($quote_id);
    if ($quote_id <= 0) {
      return [];
    }

    $raw = (string) get_post_meta($quote_id, self::quote_draft_meta_key(), true);
    if ($raw === '') {
      return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  private static function operator_context() : array {
    if (class_exists('SD_Module_OperatorLocation', false) && method_exists('SD_Module_OperatorLocation', 'get_context')) {
      return SD_Module_OperatorLocation::get_context(get_current_user_id());
    }

    return [
      'status'              => 'offline',
      'status_label'        => 'OFFLINE',
      'live_location_label' => 'missing',
      'last_lat'            => 0.0,
      'last_lng'            => 0.0,
      'last_ts'             => 0,
      'base_location_label' => 'missing',
    ];
  }

  private static function empty_payload(int $tenant_id, array $operator) : array {
    return [
      'tenant_id'             => $tenant_id,
      'tenant_name'           => $tenant_id > 0 ? (string) get_the_title($tenant_id) : '',
      'lead_id'               => 0,
      'quote_id'              => 0,
      'attempt_id'            => 0,
      'ride_id'               => 0,
      'trip_token'            => '',
      'customer_name'         => '',
      'customer_phone'        => '',
      'pickup_text'           => '',
      'dropoff_text'          => '',
      'scheduled_ts'          => 0,
      'lead_status'           => '',
      'ride_state'            => '',
      'quote_status'          => '',
      'attempt_status'        => '',
      'requested_at'          => 0,
      'updated_at'            => 0,
      'authorized_at'         => 0,
      'captured_at'           => 0,
      'capture_error'         => '',
      'operator_status'       => (string) ($operator['status'] ?? 'offline'),
      'operator_status_label' => (string) ($operator['status_label'] ?? 'OFFLINE'),
      'live_location_label'   => (string) ($operator['live_location_label'] ?? 'missing'),
      'last_known_loc'        => self::format_last_known_loc(
        (float) ($operator['last_lat'] ?? 0),
        (float) ($operator['last_lng'] ?? 0)
      ),
      'last_ping_ts'          => (int) ($operator['last_ts'] ?? 0),
      'last_ping_ago'         => self::human_time((int) ($operator['last_ts'] ?? 0)),
      'base_location_label'   => (string) ($operator['base_location_label'] ?? 'missing'),
      'quote_total_cents'     => 0,
      'quote_currency'        => 'usd',
      'pickup_eta_min'        => 0,
      'confidence_label'      => 'Missing deadhead',
      'miles_to_pickup'       => 0.0,
      'trip_miles'            => 0.0,
      'trip_mins'             => 0,
      'tot_per_60'            => 0.0,
      'tot_per_mile'          => 0.0,
    ];
  }

  private static function resolve_scheduled_ts(int $lead_id, int $quote_id, int $ride_id) : int {
    return (int) self::resolve_meta_int([$ride_id, $quote_id, $lead_id], [
      self::meta_key('PICKUP_SCHEDULED_TS', 'pickup_scheduled_ts'),
      'pickup_scheduled_ts',
      self::meta_key('SERVICE_START_TS', 'sd_service_start_ts'),
      'sd_service_start_ts',
    ]);
  }

  private static function resolve_updated_at(int $lead_id, int $quote_id, int $attempt_id, int $ride_id) : int {
    $times = array_filter([
      (int) get_post_modified_time('U', true, $lead_id),
      $quote_id > 0 ? (int) get_post_modified_time('U', true, $quote_id) : 0,
      $attempt_id > 0 ? (int) get_post_modified_time('U', true, $attempt_id) : 0,
      $ride_id > 0 ? (int) get_post_modified_time('U', true, $ride_id) : 0,
    ]);

    return empty($times) ? 0 : max($times);
  }

  private static function resolve_person_text(int $lead_id, int $ride_id, array $keys) : string {
    $resolved_keys = [];

    foreach ($keys as $key) {
      if (class_exists('SD_Meta', false)) {
        $const = 'SD_Meta::' . $key;
        if (defined($const)) {
          $resolved_keys[] = constant($const);
        }
      }
      $resolved_keys[] = $key;
    }

    return self::resolve_meta_string([$lead_id, $ride_id], $resolved_keys);
  }

  private static function resolve_meta_int(array $post_ids, array $keys) : int {
    foreach ($post_ids as $post_id) {
      $post_id = absint($post_id);
      if ($post_id <= 0) { continue; }

      foreach ($keys as $key) {
        if (!$key) { continue; }
        $value = get_post_meta($post_id, $key, true);
        if ($value !== '' && $value !== null) {
          return (int) $value;
        }
      }
    }

    return 0;
  }

  private static function resolve_meta_string(array $post_ids, array $keys) : string {
    foreach ($post_ids as $post_id) {
      $post_id = absint($post_id);
      if ($post_id <= 0) { continue; }

      foreach ($keys as $key) {
        if (!$key) { continue; }
        $value = get_post_meta($post_id, $key, true);
        if (is_string($value) && $value !== '') {
          return $value;
        }
      }
    }

    return '';
  }

  private static function format_last_known_loc(float $lat, float $lng) : string {
    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'format_last_known_loc')) {
      return SD_Module_OperatorUI::format_last_known_loc($lat, $lng);
    }

    if (abs($lat) < 0.0001 || abs($lng) < 0.0001) {
      return '—';
    }

    return number_format($lat, 4) . ', ' . number_format($lng, 4);
  }

  private static function human_time(int $ts) : string {
    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'human_time')) {
      return SD_Module_OperatorUI::human_time($ts);
    }

    if ($ts <= 0) return '—';
    return human_time_diff($ts, time()) . ' ago';
  }

  private static function meta_key(string $const_name, string $fallback) : string {
    if (class_exists('SD_Meta', false)) {
      $const = 'SD_Meta::' . $const_name;
      if (defined($const)) {
        $value = constant($const);
        if (is_string($value) && $value !== '') {
          return $value;
        }
      }
    }

    return $fallback;
  }

  private static function lead_child_meta_key() : string {
    return 'sd_lead_id';
  }

  private static function quote_draft_meta_key() : string {
    return self::meta_key('P_QUOTE_DRAFT_JSON', '_sd_quote_draft_json');
  }

  private static function attempt_status_meta_key() : string {
    return self::meta_key('P_ATTEMPT_STATUS', 'sd_attempt_status');
  }

  private static function attempt_authorized_meta_key() : string {
    return self::meta_key('P_ATTEMPT_AUTHORIZED_AT', '_sd_attempt_authorized_at');
  }

  private static function attempt_captured_meta_key() : string {
    return self::meta_key('P_STRIPE_CAPTURED_AT', '_sd_stripe_captured_at');
  }

  private static function attempt_capture_error_meta_key() : string {
    return self::meta_key('P_STRIPE_CAPTURE_ERROR', '_sd_stripe_capture_error');
  }
}