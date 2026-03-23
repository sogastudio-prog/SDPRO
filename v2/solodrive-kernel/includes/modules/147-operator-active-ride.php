<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorActiveRide (v0.1)
 *
 * Purpose:
 * - Build the stable active-ride header/intel payload used by Drive Mode
 * - Keep active ride composition separate from page rendering
 *
 * Canon:
 * - Stable header, state-governed body
 * - Tenant/operator context is read-only here
 * - Quote draft payload is parsed but not authored here
 *
 * Intended consumers:
 * - 144-operator-drive-mode.php
 * - future operator widgets / ride detail panels
 */

if (class_exists('SD_Module_OperatorActiveRide', false)) { return; }

final class SD_Module_OperatorActiveRide {

  /**
   * Build the active ride payload for a selected ride and tenant.
   *
   * Return shape:
   * [
   *   'tenant_id'             => int,
   *   'tenant_name'           => string,
   *   'ride_id'               => int,
   *   'quote_id'              => int,
   *   'trip_token'            => string,
   *   'customer_name'         => string,
   *   'customer_phone'        => string,
   *   'pickup_text'           => string,
   *   'dropoff_text'          => string,
   *   'scheduled_ts'          => int,
   *   'lead_status'           => string,
   *   'ride_state'            => string,
   *   'quote_status'          => string,
   *   'requested_at'          => int,
   *   'updated_at'            => int,
   *   'attempt_status'        => string,
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
  public static function build(int $ride_id, int $tenant_id) : array {
    $ride_id   = absint($ride_id);
    $tenant_id = absint($tenant_id);

    $operator = self::operator_context();

    if ($ride_id <= 0) {
      return self::empty_payload($tenant_id, $operator);
    }

    $quote_id = self::latest_quote_id_for_ride($ride_id);

    if (
      $quote_id > 0 &&
      class_exists('SD_Module_QuoteEngine', false) &&
      method_exists('SD_Module_QuoteEngine', 'ensure_quote_draft')
    ) {
      SD_Module_QuoteEngine::ensure_quote_draft($ride_id, [
        'tenant_id' => $tenant_id,
        'timeout'   => 8,
        'force'     => false,
      ]);

      $quote_id = self::latest_quote_id_for_ride($ride_id);
    }

    $attempt = self::latest_attempt_for_ride($ride_id);
    $draft   = self::parse_quote_draft_payload($quote_id);

    return [
      'tenant_id'             => $tenant_id,
      'tenant_name'           => $tenant_id > 0 ? (string) get_the_title($tenant_id) : '',
      'ride_id'               => $ride_id,
      'quote_id'              => $quote_id,
      'trip_token'            => (string) get_post_meta($ride_id, SD_Meta::TRIP_TOKEN, true),
      'customer_name'         => (string) get_post_meta($ride_id, SD_Meta::CUSTOMER_NAME, true),
      'customer_phone'        => (string) get_post_meta($ride_id, SD_Meta::CUSTOMER_PHONE, true),
      'pickup_text'           => (string) get_post_meta($ride_id, SD_Meta::PICKUP_TEXT, true),
      'dropoff_text'          => (string) get_post_meta($ride_id, SD_Meta::DROPOFF_TEXT, true),
      'scheduled_ts'          => (int) get_post_meta($ride_id, SD_Meta::PICKUP_SCHEDULED_TS, true),
      'lead_status'           => (string) get_post_meta($ride_id, SD_Meta::LEAD_STATUS, true),
      'ride_state'            => (string) get_post_meta($ride_id, SD_Meta::RIDE_STATE, true),
      'quote_status'          => $quote_id > 0 ? (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true) : '',
      'requested_at'          => (int) get_post_time('U', true, $ride_id),
      'updated_at'            => (int) get_post_modified_time('U', true, $ride_id),
      'attempt_status'        => (string) ($attempt['status'] ?? ''),
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
   * Title helper for ride execution state.
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
        return 'Open ride';
    }
  }

  /**
   * Available next execution actions for ride-state-driven body.
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
   * Latest quote id for a ride.
   * Mirrors queue/service behavior so both surfaces stay aligned.
   */
  public static function latest_quote_id_for_ride(int $ride_id) : int {
    $ride_id = absint($ride_id);
    if ($ride_id <= 0) return 0;

    $quote_id = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
      return $quote_id;
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
          'key'     => SD_Meta::RIDE_ID,
          'value'   => $ride_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    $quote_id = !empty($ids[0]) ? (int) $ids[0] : 0;

    if ($quote_id > 0) {
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, $quote_id);
    }

    return $quote_id;
  }

  /**
   * Latest attempt summary for a ride.
   */
  public static function latest_attempt_for_ride(int $ride_id) : array {
    $ride_id = absint($ride_id);
    if ($ride_id <= 0) return [];

    $ids = get_posts([
      'post_type'      => 'sd_attempt',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => SD_Meta::P_ATTEMPT_RIDE_ID,
          'value'   => $ride_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    $attempt_id = !empty($ids[0]) ? (int) $ids[0] : 0;
    if ($attempt_id <= 0) return [];

    return [
      'attempt_id'    => $attempt_id,
      'status'        => (string) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, true),
      'authorized_at' => (int) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_AUTHORIZED_AT, true),
      'captured_at'   => (int) get_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURED_AT, true),
      'capture_error' => (string) get_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURE_ERROR, true),
    ];
  }

  /**
   * Parse quote draft payload from quote meta.
   */
  public static function parse_quote_draft_payload(int $quote_id) : array {
    $quote_id = absint($quote_id);
    if ($quote_id <= 0) {
      return [];
    }

    $raw = (string) get_post_meta($quote_id, SD_Meta::P_QUOTE_DRAFT_JSON, true);
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
      'ride_id'               => 0,
      'quote_id'              => 0,
      'trip_token'            => '',
      'customer_name'         => '',
      'customer_phone'        => '',
      'pickup_text'           => '',
      'dropoff_text'          => '',
      'scheduled_ts'          => 0,
      'lead_status'           => '',
      'ride_state'            => '',
      'quote_status'          => '',
      'requested_at'          => 0,
      'updated_at'            => 0,
      'attempt_status'        => '',
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
}