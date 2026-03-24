<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorTripActions (v0.2)
 *
 * Purpose:
 * - Handle private operator actions from /operator/trips/
 * - Clear pending quote-waiting push state when operator resolves the quote
 */

if (class_exists('SD_Module_OperatorTripActions', false)) { return; }

final class SD_Module_OperatorTripActions {

  private const NONCE_ACTION = 'sd_operator_trip_action';

  private const META_QUOTE_AMOUNT_CENTS    = '_sd_quote_amount_cents';
  private const META_QUOTE_CURRENCY        = '_sd_quote_currency';
  private const META_QUOTE_PICKUP_ETA_MIN  = '_sd_quote_pickup_eta_min';
  private const META_QUOTE_CONFIDENCE      = '_sd_quote_confidence_label';
  
  private const META_LAST_QUOTE_WAITING_PUSH_TS  = '_sd_last_quote_waiting_push_ts';
  private const META_LAST_QUOTE_WAITING_QUOTE_ID = '_sd_last_quote_waiting_quote_id';
  private const META_LAST_QUOTE_WAITING_STATUS   = '_sd_last_quote_waiting_status';

  public static function register() : void {
    add_action('admin_post_sd_operator_quote_approve',          [__CLASS__, 'handle_quote_approve']);
    add_action('admin_post_sd_operator_quote_recalculate',      [__CLASS__, 'handle_quote_recalculate']);
    add_action('admin_post_sd_operator_quote_adjust_percent',   [__CLASS__, 'handle_quote_adjust_percent']);
    add_action('admin_post_sd_operator_quote_adjust_eta',       [__CLASS__, 'handle_quote_adjust_eta']);
    add_action('admin_post_sd_operator_quote_present_adjusted', [__CLASS__, 'handle_quote_present_adjusted']);
    add_action('admin_post_sd_operator_quote_reject',           [__CLASS__, 'handle_quote_reject']);
    add_action('admin_post_sd_operator_ride_progress',          [__CLASS__, 'handle_ride_progress']);
  }

  public static function handle_quote_approve() : void {
    $quote_id = self::post_int('quote_id');
    $ride_id  = self::post_int('ride_id');

    if (!self::guard_operator_request($ride_id, $quote_id)) {
      self::redirect_back($ride_id, 'auth_fail');
    }

    if ($quote_id <= 0) {
      self::redirect_back($ride_id, 'missing_quote');
    }

    $ride_id = self::resolve_ride_id_for_quote($quote_id, $ride_id);
    if ($ride_id <= 0) {
      self::redirect_back(0, 'bad_quote');
    }

    if (!self::current_user_can_access_ride($ride_id)) {
      self::redirect_back($ride_id, 'tenant_mismatch');
    }

    if (!class_exists('SD_Module_QuoteStateService') || !class_exists('SD_Quote_State')) {
      self::redirect_back($ride_id, 'service_missing');
    }

    $current = SD_Module_QuoteStateService::get($quote_id);
    if (!in_array($current, [SD_Quote_State::PROPOSED, SD_Meta::QUOTE_APPROVED], true)) {
      self::redirect_back($ride_id, 'quote_state_blocked');
    }

    if (class_exists('SD_Module_QuoteEngine') && method_exists('SD_Module_QuoteEngine', 'ensure_quote_draft')) {
      SD_Module_QuoteEngine::ensure_quote_draft($ride_id);
    }

    $draft = self::get_quote_draft($quote_id);
    if (empty($draft)) {
      self::redirect_back($ride_id, 'draft_missing');
    }

    $ok = self::persist_quote_snapshot_from_draft($quote_id, $draft);
    if (!$ok) {
      self::redirect_back($ride_id, 'draft_invalid');
    }

    self::write_tenant_decision_audit($quote_id, 'approve', '');
    update_post_meta($quote_id, SD_Meta::P_QUOTE_PRESENTED_AT, time());

    $state_ok = SD_Module_QuoteStateService::set($quote_id, SD_Meta::QUOTE_PRESENTED, [
      'source'  => 'operator_surface',
      'action'  => 'approve_quote',
      'user_id' => get_current_user_id(),
      'ride_id' => $ride_id,
    ]);

    if ($state_ok) {
      update_post_meta($ride_id, SD_Meta::LEAD_STATUS, 'LEAD_OFFERED');
      self::clear_quote_waiting_push_state($ride_id);
      SD_Util::log('operator_quote_approved', [
        'quote_id' => $quote_id,
        'ride_id'  => $ride_id,
        'user_id'  => get_current_user_id(),
      ]);
    }

    self::redirect_back($ride_id, $state_ok ? 'quote_presented' : 'quote_present_failed');
  }

  public static function handle_quote_reject() : void {
    $quote_id = self::post_int('quote_id');
    $ride_id  = self::post_int('ride_id');
    $note     = self::post_text('decision_note');

    if (!self::guard_operator_request($ride_id, $quote_id)) {
      self::redirect_back($ride_id, 'auth_fail');
    }

    if ($quote_id <= 0) {
      self::redirect_back($ride_id, 'missing_quote');
    }

    $ride_id = self::resolve_ride_id_for_quote($quote_id, $ride_id);
    if ($ride_id <= 0) {
      self::redirect_back(0, 'bad_quote');
    }

    if (!self::current_user_can_access_ride($ride_id)) {
      self::redirect_back($ride_id, 'tenant_mismatch');
    }

    if (!class_exists('SD_Module_QuoteStateService') || !class_exists('SD_Quote_State')) {
      self::redirect_back($ride_id, 'service_missing');
    }

    self::write_tenant_decision_audit($quote_id, 'reject', $note);

    $state_ok = SD_Module_QuoteStateService::set($quote_id, SD_Quote_State::CANCELLED, [
      'source'  => 'operator_surface',
      'action'  => 'reject_quote',
      'user_id' => get_current_user_id(),
      'ride_id' => $ride_id,
    ]);

    if ($state_ok) {
      update_post_meta($ride_id, SD_Meta::LEAD_STATUS, 'LEAD_DECLINED');
      self::clear_quote_waiting_push_state($ride_id);
      SD_Util::log('operator_quote_rejected', [
        'quote_id' => $quote_id,
        'ride_id'  => $ride_id,
        'user_id'  => get_current_user_id(),
      ]);
    }

    self::redirect_back($ride_id, $state_ok ? 'quote_rejected' : 'quote_reject_failed');
  }

  public static function handle_ride_progress() : void {
    $ride_id   = self::post_int('ride_id');
    $to_state  = self::post_text('to_state');

    if (!self::guard_operator_request($ride_id, 0)) {
      self::redirect_back($ride_id, 'auth_fail');
    }

    if ($ride_id <= 0) {
      self::redirect_back(0, 'missing_ride');
    }

    if (!self::current_user_can_access_ride($ride_id)) {
      self::redirect_back($ride_id, 'tenant_mismatch');
    }

    if (!class_exists('SD_Module_RideStateService') || !class_exists('SD_Ride_State')) {
      self::redirect_back($ride_id, 'service_missing');
    }

    $current = SD_Module_RideStateService::get($ride_id);
    if ($to_state === '') {
      self::redirect_back($ride_id, 'missing_state');
    }

    if (!in_array($to_state, SD_Ride_State::all(), true)) {
      self::redirect_back($ride_id, 'bad_state');
    }

    if (in_array($to_state, [SD_Ride_State::DEADHEAD, SD_Ride_State::WAITING, SD_Ride_State::ARRIVED, SD_Ride_State::INPROGRESS], true)) {
      if (!self::ride_is_authorization_ready($ride_id)) {
        self::redirect_back($ride_id, 'auth_required');
      }
    }

    $ok = SD_Module_RideStateService::set($ride_id, $to_state, [
      'source'  => 'operator_surface',
      'user_id' => get_current_user_id(),
      'from'    => $current,
      'to'      => $to_state,
    ]);

    if ($ok) {
      SD_Util::log('operator_ride_progressed', [
        'ride_id' => $ride_id,
        'from'    => $current,
        'to'      => $to_state,
        'user_id' => get_current_user_id(),
      ]);
    }

    self::redirect_back($ride_id, $ok ? 'ride_progressed' : 'ride_progress_failed');
  }

  public static function handle_quote_recalculate() : void {
    $quote_id = self::post_int('quote_id');
    $ride_id  = self::post_int('ride_id');

    if (!self::guard_operator_request($ride_id, $quote_id)) {
      self::redirect_back($ride_id, 'auth_fail');
    }

    if ($quote_id <= 0) {
      self::redirect_back($ride_id, 'missing_quote');
    }

    $ride_id = self::resolve_ride_id_for_quote($quote_id, $ride_id);
    if ($ride_id <= 0) {
      self::redirect_back(0, 'bad_quote');
    }

    if (!self::current_user_can_access_ride($ride_id)) {
      self::redirect_back($ride_id, 'tenant_mismatch');
    }

    if (!class_exists('SD_Module_QuoteEngine') || !method_exists('SD_Module_QuoteEngine', 'ensure_quote_draft')) {
      self::redirect_back($ride_id, 'quote_engine_missing');
    }

    SD_Module_QuoteEngine::ensure_quote_draft($ride_id);
    self::redirect_back($ride_id, 'quote_recalculated');
  }

  public static function handle_quote_adjust_percent() : void {
    $quote_id      = self::post_int('quote_id');
    $ride_id       = self::post_int('ride_id');
    $delta_percent = self::post_int('delta_percent');

    if (!self::guard_operator_request($ride_id, $quote_id)) {
      self::redirect_back($ride_id, 'auth_fail');
    }

    if ($quote_id <= 0) {
      self::redirect_back($ride_id, 'missing_quote');
    }

    $ride_id = self::resolve_ride_id_for_quote($quote_id, $ride_id);
    if ($ride_id <= 0) {
      self::redirect_back(0, 'bad_quote');
    }

    if (!self::current_user_can_access_ride($ride_id)) {
      self::redirect_back($ride_id, 'tenant_mismatch');
    }

    $draft = self::get_quote_draft($quote_id);
    if (empty($draft) || empty($draft['quote']) || !is_array($draft['quote'])) {
      self::redirect_back($ride_id, 'draft_missing');
    }

    $amount_cents = (int) ($draft['quote']['total_cents'] ?? 0);
    if ($amount_cents <= 0) {
      self::redirect_back($ride_id, 'amount_invalid');
    }

    $allowed = [-10, -5, 5, 10];
    if (!in_array($delta_percent, $allowed, true)) {
      self::redirect_back($ride_id, 'bad_adjustment');
    }

    $new_amount = (int) round($amount_cents * (1 + ($delta_percent / 100)));
    $new_amount = max(100, (int) (round($new_amount / 25) * 25));

    $draft['quote']['total_cents'] = $new_amount;
    $draft['quote']['confidence_label'] = 'Adjusted';
    $draft['adjustments']['percent'] = ($draft['adjustments']['percent'] ?? 0) + $delta_percent;
    $draft['adjustments']['last_action'] = 'percent';
    $draft['adjustments']['adjusted_at'] = time();
    $draft['adjustments']['adjusted_by'] = get_current_user_id();

    update_post_meta($quote_id, SD_Meta::P_QUOTE_DRAFT_JSON, wp_json_encode($draft));
    update_post_meta($quote_id, SD_Meta::P_QUOTE_BUILT_AT, time());

    self::redirect_back($ride_id, 'quote_adjusted');
  }

  public static function handle_quote_adjust_eta() : void {
    $quote_id      = self::post_int('quote_id');
    $ride_id       = self::post_int('ride_id');
    $delta_minutes = self::post_int('delta_minutes');

    if (!self::guard_operator_request($ride_id, $quote_id)) {
      self::redirect_back($ride_id, 'auth_fail');
    }

    if ($quote_id <= 0) {
      self::redirect_back($ride_id, 'missing_quote');
    }

    $ride_id = self::resolve_ride_id_for_quote($quote_id, $ride_id);
    if ($ride_id <= 0) {
      self::redirect_back(0, 'bad_quote');
    }

    if (!self::current_user_can_access_ride($ride_id)) {
      self::redirect_back($ride_id, 'tenant_mismatch');
    }

    $draft = self::get_quote_draft($quote_id);
    if (empty($draft) || empty($draft['quote']) || !is_array($draft['quote'])) {
      self::redirect_back($ride_id, 'draft_missing');
    }

    $allowed = [-5, 5];
    if (!in_array($delta_minutes, $allowed, true)) {
      self::redirect_back($ride_id, 'bad_adjustment');
    }

    $eta = (int) ($draft['quote']['pickup_eta_min'] ?? 0);
    $eta = max(0, $eta + $delta_minutes);

    $draft['quote']['pickup_eta_min'] = $eta;
    $draft['quote']['confidence_label'] = 'Adjusted';
    $draft['adjustments']['eta_minutes'] = ($draft['adjustments']['eta_minutes'] ?? 0) + $delta_minutes;
    $draft['adjustments']['last_action'] = 'eta';
    $draft['adjustments']['adjusted_at'] = time();
    $draft['adjustments']['adjusted_by'] = get_current_user_id();

    update_post_meta($quote_id, SD_Meta::P_QUOTE_DRAFT_JSON, wp_json_encode($draft));
    update_post_meta($quote_id, SD_Meta::P_QUOTE_BUILT_AT, time());

    self::redirect_back($ride_id, 'quote_adjusted');
  }

  public static function handle_quote_present_adjusted() : void {
    $quote_id = self::post_int('quote_id');
    $ride_id  = self::post_int('ride_id');

    if (!self::guard_operator_request($ride_id, $quote_id)) {
      self::redirect_back($ride_id, 'auth_fail');
    }

    if ($quote_id <= 0) {
      self::redirect_back($ride_id, 'missing_quote');
    }

    $ride_id = self::resolve_ride_id_for_quote($quote_id, $ride_id);
    if ($ride_id <= 0) {
      self::redirect_back(0, 'bad_quote');
    }

    if (!self::current_user_can_access_ride($ride_id)) {
      self::redirect_back($ride_id, 'tenant_mismatch');
    }

    if (!class_exists('SD_Module_QuoteStateService') || !class_exists('SD_Quote_State')) {
      self::redirect_back($ride_id, 'service_missing');
    }

    $draft = self::get_quote_draft($quote_id);
    if (empty($draft)) {
      self::redirect_back($ride_id, 'draft_missing');
    }

    $ok = self::persist_quote_snapshot_from_draft($quote_id, $draft);
    if (!$ok) {
      self::redirect_back($ride_id, 'draft_invalid');
    }

    self::write_tenant_decision_audit($quote_id, 'adjust', 'Presented adjusted quote');
    update_post_meta($quote_id, SD_Meta::P_QUOTE_PRESENTED_AT, time());

    $state_ok = SD_Module_QuoteStateService::set($quote_id, SD_Meta::QUOTE_PRESENTED, [
      'source'  => 'operator_surface',
      'action'  => 'present_adjusted_quote',
      'user_id' => get_current_user_id(),
      'ride_id' => $ride_id,
    ]);

    if ($state_ok) {
      update_post_meta($ride_id, SD_Meta::LEAD_STATUS, 'LEAD_OFFERED');
      self::clear_quote_waiting_push_state($ride_id);
    }

    self::redirect_back($ride_id, $state_ok ? 'quote_presented' : 'quote_present_failed');
  }

  private static function guard_operator_request(int $ride_id, int $quote_id) : bool {
    if (!is_user_logged_in()) return false;

    $nonce = isset($_POST['_wpnonce']) ? (string) wp_unslash($_POST['_wpnonce']) : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      return false;
    }

    if (current_user_can('manage_options')) return true;

    if (class_exists('SD_Module_RolesCaps')) {
      if (
        current_user_can(SD_Module_RolesCaps::CAP_MANAGE_TENANT) ||
        current_user_can(SD_Module_RolesCaps::CAP_DISPATCH) ||
        current_user_can(SD_Module_RolesCaps::CAP_DRIVER)
      ) {
        return true;
      }
    }

    return true;
  }

  private static function current_user_can_access_ride(int $ride_id) : bool {
    if ($ride_id <= 0) return false;
    if (current_user_can('manage_options')) return true;

    $ride_tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    if ($ride_tenant_id <= 0) return false;

    $user_tenant_id = 0;
    if (class_exists('SD_TenantAccess') && method_exists('SD_TenantAccess', 'current_user_tenant_id')) {
      $user_tenant_id = (int) SD_TenantAccess::current_user_tenant_id();
    }
    if ($user_tenant_id <= 0) {
      $user_tenant_id = (int) get_user_meta(get_current_user_id(), SD_Meta::TENANT_ID, true);
    }

    return ($user_tenant_id > 0 && $user_tenant_id === $ride_tenant_id);
  }

  private static function resolve_ride_id_for_quote(int $quote_id, int $fallback_ride_id = 0) : int {
    $ride_id = (int) get_post_meta($quote_id, SD_Meta::RIDE_ID, true);
    if ($ride_id > 0) return $ride_id;
    return $fallback_ride_id > 0 ? $fallback_ride_id : 0;
  }

  private static function get_quote_draft(int $quote_id) : array {
    $raw = (string) get_post_meta($quote_id, SD_Meta::P_QUOTE_DRAFT_JSON, true);
    if ($raw === '') return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
  }

  private static function persist_quote_snapshot_from_draft(int $quote_id, array $draft) : bool {
    $quote = isset($draft['quote']) && is_array($draft['quote']) ? $draft['quote'] : [];
    if (empty($quote)) return false;

    $amount_cents   = isset($quote['total_cents']) ? (int) $quote['total_cents'] : 0;
    $currency       = isset($quote['currency']) ? strtolower((string) $quote['currency']) : 'usd';
    $pickup_eta_min = isset($quote['pickup_eta_min']) ? (int) $quote['pickup_eta_min'] : 0;
    $confidence     = isset($quote['confidence_label']) ? (string) $quote['confidence_label'] : '';

    if ($amount_cents <= 0) return false;
    if ($currency === '') $currency = 'usd';

    update_post_meta($quote_id, self::META_QUOTE_AMOUNT_CENTS, $amount_cents);
    update_post_meta($quote_id, self::META_QUOTE_CURRENCY, $currency);
    update_post_meta($quote_id, self::META_QUOTE_PICKUP_ETA_MIN, $pickup_eta_min);
    update_post_meta($quote_id, self::META_QUOTE_CONFIDENCE, $confidence);

    return true;
  }

  private static function write_tenant_decision_audit(int $quote_id, string $decision, string $note = '') : void {
    update_post_meta($quote_id, SD_Meta::P_QUOTE_TENANT_DECISION, sanitize_key($decision));
    update_post_meta($quote_id, SD_Meta::P_QUOTE_TENANT_DECISION_NOTE, $note);
    update_post_meta($quote_id, SD_Meta::P_QUOTE_TENANT_DECISION_BY, get_current_user_id());
  }

  private static function ride_is_authorization_ready(int $ride_id) : bool {
    $quote_id = self::get_latest_quote_id_for_ride($ride_id);
    if ($quote_id > 0 && class_exists('SD_Module_QuoteStateService')) {
      $quote_state = SD_Module_QuoteStateService::get($quote_id);
      if ($quote_state === 'PAYMENT_PENDING') {
        return true;
      }
    }

    $attempt = self::get_latest_attempt_for_ride($ride_id);
    if (!empty($attempt['status']) && strtoupper((string) $attempt['status']) === 'AUTHORIZED') {
      return true;
    }

    return false;
  }

  private static function get_latest_quote_id_for_ride(int $ride_id) : int {
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

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  private static function get_latest_attempt_for_ride(int $ride_id) : array {
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
    ];
  }

  private static function redirect_back(int $ride_id, string $result) : void {
    $args = [
      'tab'                => 'trip-ops',
      'sd_operator_result' => sanitize_key($result),
    ];

    if ($ride_id > 0) {
      $args['ride_id'] = $ride_id;
    }

    $url = add_query_arg($args, home_url('/operator/trips/'));
    wp_safe_redirect($url, 302);
    exit;
  }

  private static function post_int(string $key) : int {
    return isset($_POST[$key]) ? absint(wp_unslash($_POST[$key])) : 0;
  }

  private static function post_text(string $key) : string {
    return isset($_POST[$key]) ? sanitize_text_field((string) wp_unslash($_POST[$key])) : '';
  }
  
  private static function clear_quote_waiting_push_state(int $ride_id) : void {
  delete_post_meta($ride_id, self::META_LAST_QUOTE_WAITING_PUSH_TS);
  delete_post_meta($ride_id, self::META_LAST_QUOTE_WAITING_QUOTE_ID);
  delete_post_meta($ride_id, self::META_LAST_QUOTE_WAITING_STATUS);
}
}