<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_RideStateService (v1.2)
 *
 * - Only reader/writer for ride state
 * - Stores state in sd_ride_state meta
 * - Writes audit timestamps
 * - Automatically sets token expiry when entering terminal state
 * - Requires authorized payment before dispatch
 * - Captures authorized funds before marking ride complete
 * - Fires explicit lifecycle actions after successful transitions
 */
final class SD_Module_RideStateService {

  private const POST_TERMINAL_GRACE_DAYS = 7;

  public static function register() : void {
    // Pure service; no hooks required.
  }

  public static function get(int $ride_id) : string {
    if ($ride_id <= 0 || get_post_type($ride_id) !== SD_CPT_Ride::CPT) {
      return SD_Ride_State::QUEUED;
    }

    $state = (string) get_post_meta($ride_id, SD_Meta::RIDE_STATE, true);
    if ($state === '') return SD_Ride_State::QUEUED;

    if (!in_array($state, SD_Ride_State::all(), true)) {
      SD_Util::log('ride_state_invalid', [
        'ride_id' => $ride_id,
        'state'   => $state,
      ]);
      return SD_Ride_State::QUEUED;
    }

    return $state;
  }

  public static function set(int $ride_id, string $to_state, array $ctx = []) : bool {
    $to_state = (string) $to_state;

    if ($ride_id <= 0 || get_post_type($ride_id) !== SD_CPT_Ride::CPT) {
      SD_Util::log('ride_state_set_rejected_not_ride', [
        'ride_id' => $ride_id,
        'to'      => $to_state,
      ]);
      return false;
    }

    if (!in_array($to_state, SD_Ride_State::all(), true)) {
      SD_Util::log('ride_state_set_rejected_invalid', [
        'ride_id' => $ride_id,
        'to'      => $to_state,
      ]);
      return false;
    }

    $from = self::get($ride_id);

    // Idempotent no-op
    if ($from === $to_state) {
      SD_Util::log('ride_state_set_noop', [
        'ride_id' => $ride_id,
        'state'   => $to_state,
        'ctx'     => $ctx,
      ]);
      return true;
    }

    if (!SD_Ride_State::can_transition($from, $to_state)) {
      SD_Util::log('ride_state_set_rejected_transition', [
        'ride_id' => $ride_id,
        'from'    => $from,
        'to'      => $to_state,
      ]);
      return false;
    }

    // Require authorized payment before dispatch begins.
    if ($from === SD_Ride_State::QUEUED && $to_state === SD_Ride_State::DEADHEAD) {
      $attempt_id = self::find_latest_attempt_id_for_ride($ride_id);
      if ($attempt_id <= 0 || !self::attempt_is_authorized($attempt_id)) {
        SD_Util::log('ride_state_set_rejected_missing_authorized_attempt', [
          'ride_id'    => $ride_id,
          'from'       => $from,
          'to'         => $to_state,
          'attempt_id' => $attempt_id,
        ]);
        return false;
      }

      self::sync_lifecycle_for_dispatch($ride_id);
    }

    // Completion gate: capture first, only then mark COMPLETE.
    if ($from === SD_Ride_State::ARRIVED && $to_state === SD_Ride_State::COMPLETE) {
      $capture = self::capture_authorized_attempt_for_ride($ride_id, $ctx);

      if (empty($capture['ok'])) {
        SD_Util::log('ride_state_set_rejected_capture_failed', [
          'ride_id' => $ride_id,
          'from'    => $from,
          'to'      => $to_state,
          'capture' => $capture,
        ]);
        return false;
      }
    }

    update_post_meta($ride_id, SD_Meta::RIDE_STATE, $to_state);
    update_post_meta($ride_id, SD_Meta::P_STATE_UPDATED_AT, time());

    if (SD_Ride_State::is_terminal($to_state)) {
      $expires = time() + (self::POST_TERMINAL_GRACE_DAYS * DAY_IN_SECONDS);
      update_post_meta($ride_id, SD_Meta::P_TOKEN_EXPIRES_AT, $expires);
    }

    if ($to_state === SD_Ride_State::COMPLETE) {
      do_action('sd_ride_completed', $ride_id, $from, $to_state, $ctx);
    }

    do_action('sd_ride_state_changed', $ride_id, $from, $to_state, $ctx);

    SD_Util::log('ride_state_set', [
      'ride_id' => $ride_id,
      'from'    => $from,
      'to'      => $to_state,
      'ctx'     => $ctx,
    ]);

    return true;
  }

  public static function is_token_expired(int $ride_id) : bool {
    $expires = (int) get_post_meta($ride_id, SD_Meta::P_TOKEN_EXPIRES_AT, true);
    if ($expires <= 0) return false;
    return time() > $expires;
  }

  // ---------------------------------------------------------------------------
  // Attempt / payment helpers
  // ---------------------------------------------------------------------------

  private static function find_latest_attempt_id_for_ride(int $ride_id) : int {
    $q = new \WP_Query([
      'no_found_rows'  => true,
      'post_type'      => 'sd_attempt',
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::P_ATTEMPT_RIDE_ID,
        'value'   => (string) $ride_id,
        'compare' => '=',
      ]],
    ]);

    return !empty($q->posts[0]) ? (int) $q->posts[0] : 0;
  }

  private static function attempt_is_authorized(int $attempt_id) : bool {
    if ($attempt_id <= 0 || get_post_type($attempt_id) !== 'sd_attempt') {
      return false;
    }

    $status = strtoupper(trim((string) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, true)));
    if ($status === '') return false;

    return in_array($status, [
      'AUTHORIZED',
      'AUTHORISED',
      'CAPTURE_PENDING',
    ], true);
  }

  private static function attempt_is_captured(int $attempt_id) : bool {
    if ($attempt_id <= 0) return false;

    $captured_at = (int) get_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURED_AT, true);
    if ($captured_at > 0) return true;

    $status = strtoupper(trim((string) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, true)));
    return in_array($status, ['CAPTURED', 'SUCCEEDED'], true);
  }

  private static function capture_authorized_attempt_for_ride(int $ride_id, array $ctx = []) : array {
    $attempt_id = self::find_latest_attempt_id_for_ride($ride_id);

    $quote_id = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($quote_id <= 0) {
      $quote_id = (int) get_post_meta($ride_id, '_sd_latest_quote_id', true);
    }

    $amount_cents = 0;
    if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
      $amount_cents = (int) get_post_meta($quote_id, '_sd_quote_amount_cents', true);
    }

    $attempted_at = time();

    update_post_meta($ride_id, '_sd_capture_attempted_at', $attempted_at);
    update_post_meta($ride_id, '_sd_capture_last_result', 'STARTED');

    SD_Util::log('capture_boundary_enter', [
      'ride_id'      => $ride_id,
      'quote_id'     => $quote_id,
      'attempt_id'   => $attempt_id,
      'amount_cents' => $amount_cents,
      'ts'           => $attempted_at,
      'ctx'          => $ctx,
    ]);

    if ($attempt_id <= 0) {
      $result = [
        'ok'      => false,
        'message' => 'No payment attempt found for ride.',
      ];

      update_post_meta($ride_id, '_sd_capture_last_result', 'FAILED_NO_ATTEMPT');

      SD_Util::log('capture_boundary_result', [
        'ride_id'      => $ride_id,
        'quote_id'     => $quote_id,
        'attempt_id'   => 0,
        'amount_cents' => $amount_cents,
        'ts'           => time(),
        'result'       => $result,
      ]);

      return $result;
    }

    if (self::attempt_is_captured($attempt_id)) {
      $result = [
        'ok'         => true,
        'message'    => 'Payment already captured.',
        'attempt_id' => $attempt_id,
        'already'    => true,
      ];

      update_post_meta($ride_id, '_sd_capture_last_result', 'ALREADY_CAPTURED');

      SD_Util::log('capture_boundary_result', [
        'ride_id'      => $ride_id,
        'quote_id'     => $quote_id,
        'attempt_id'   => $attempt_id,
        'amount_cents' => $amount_cents,
        'ts'           => time(),
        'result'       => $result,
      ]);

      return $result;
    }

    if (!self::attempt_is_authorized($attempt_id)) {
      $result = [
        'ok'         => false,
        'message'    => 'Payment attempt is not in authorized state.',
        'attempt_id' => $attempt_id,
      ];

      update_post_meta($ride_id, '_sd_capture_last_result', 'FAILED_NOT_AUTHORIZED');

      SD_Util::log('capture_boundary_result', [
        'ride_id'      => $ride_id,
        'quote_id'     => $quote_id,
        'attempt_id'   => $attempt_id,
        'amount_cents' => $amount_cents,
        'ts'           => time(),
        'result'       => $result,
      ]);

      return $result;
    }

    if (class_exists('SD_Module_PaymentsCapture') && method_exists('SD_Module_PaymentsCapture', 'capture_for_attempt')) {
      $result = SD_Module_PaymentsCapture::capture_for_attempt($attempt_id, $ctx);
      $result = is_array($result)
        ? $result
        : ['ok' => false, 'message' => 'Invalid capture result from capture_for_attempt'];

      update_post_meta(
        $ride_id,
        '_sd_capture_last_result',
        !empty($result['ok']) ? 'SUCCESS' : 'FAILED_CAPTURE_SERVICE'
      );

      SD_Util::log('capture_boundary_result', [
        'ride_id'      => $ride_id,
        'quote_id'     => $quote_id,
        'attempt_id'   => $attempt_id,
        'amount_cents' => $amount_cents,
        'ts'           => time(),
        'result'       => $result,
      ]);

      return $result;
    }

    if (class_exists('SD_Module_PaymentsCapture') && method_exists('SD_Module_PaymentsCapture', 'capture_for_ride')) {
      $result = SD_Module_PaymentsCapture::capture_for_ride($ride_id, $ctx);
      $result = is_array($result)
        ? $result
        : ['ok' => false, 'message' => 'Invalid capture result from capture_for_ride'];

      update_post_meta(
        $ride_id,
        '_sd_capture_last_result',
        !empty($result['ok']) ? 'SUCCESS' : 'FAILED_CAPTURE_SERVICE'
      );

      SD_Util::log('capture_boundary_result', [
        'ride_id'      => $ride_id,
        'quote_id'     => $quote_id,
        'attempt_id'   => $attempt_id,
        'amount_cents' => $amount_cents,
        'ts'           => time(),
        'result'       => $result,
      ]);

      return $result;
    }

    $result = [
      'ok'         => false,
      'message'    => 'No capture service available.',
      'attempt_id' => $attempt_id,
    ];

    update_post_meta($ride_id, '_sd_capture_last_result', 'FAILED_NO_CAPTURE_SERVICE');

    SD_Util::log('capture_boundary_result', [
      'ride_id'      => $ride_id,
      'quote_id'     => $quote_id,
      'attempt_id'   => $attempt_id,
      'amount_cents' => $amount_cents,
      'ts'           => time(),
      'result'       => $result,
    ]);

    return $result;
  }

  // ---------------------------------------------------------------------------
  // Lifecycle sync
  // ---------------------------------------------------------------------------

  private static function sync_lifecycle_for_dispatch(int $ride_id) : void {
    $quote_id = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);

    // Lead should no longer be in offer mode once dispatch begins.
    update_post_meta($ride_id, SD_Meta::LEAD_STATUS, 'LEAD_PROMOTED');

    if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
      update_post_meta($quote_id, SD_Meta::QUOTE_STATUS, 'PAYMENT_PENDING');
      update_post_meta($quote_id, SD_Meta::P_QUOTE_STATUS_UPDATED_AT, time());
    }
  }
}