<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Quote_State — Canonical quote lifecycle (minimal foundation)
 *
 * This is the only place canonical quote states are defined.
 *
 * Locked intent:
 * - QUOTE_PRESENTED is the only human decision state
 * - PAYMENT_PENDING is the goal state post-authorization
 */
final class SD_Quote_State {

  public const PROPOSED        = 'PROPOSED';
  public const PRESENTED       = 'PRESENTED';
  public const PAYMENT_PENDING = 'PAYMENT_PENDING';

  // Reserved for later expansion (safe to add; do not remove once shipped)
  public const APPROVED        = 'APPROVED';
  public const LEAD_ACCEPTED   = 'LEAD_ACCEPTED';
  public const USER_REJECTED   = 'USER_REJECTED';
  public const USER_TIMEOUT    = 'USER_TIMEOUT';
  public const EXPIRED         = 'EXPIRED';
  public const CANCELLED       = 'CANCELLED';

  public static function all() : array {
    return [
      self::PROPOSED,
      self::PRESENTED,
      self::PAYMENT_PENDING,

      self::APPROVED,
      self::LEAD_ACCEPTED,
      self::USER_REJECTED,
      self::USER_TIMEOUT,
      self::EXPIRED,
      self::CANCELLED,
    ];
  }

  public static function is_terminal(string $state) : bool {
    return in_array($state, [self::EXPIRED, self::CANCELLED], true);
  }

  /**
   * Minimal allowed transitions (tighten later).
   */
  public static function can_transition(string $from, string $to) : bool {

    if ($from === $to) return true;

    $allowed = [
      self::PROPOSED        => [self::PRESENTED, self::CANCELLED, self::EXPIRED],
      self::PRESENTED       => [self::PAYMENT_PENDING, self::USER_REJECTED, self::USER_TIMEOUT, self::CANCELLED, self::EXPIRED],
      self::PAYMENT_PENDING => [self::LEAD_ACCEPTED, self::CANCELLED, self::EXPIRED],

      // Optional expansion paths (kept permissive)
      self::APPROVED        => [self::PRESENTED, self::CANCELLED, self::EXPIRED],
      self::LEAD_ACCEPTED   => [self::CANCELLED],
      self::USER_REJECTED   => [],
      self::USER_TIMEOUT    => [],
      self::EXPIRED         => [],
      self::CANCELLED       => [],
    ];

    return in_array($to, $allowed[$from] ?? [], true);
  }
}

/**
 * SD_Module_QuoteStateService (v1)
 *
 * - Only reader/writer for quote state
 * - Stores state in SD_Meta::QUOTE_STATUS (sd_quote_status)
 * - Writes audit timestamps
 */
final class SD_Module_QuoteStateService {

  public static function register() : void {
    // No hooks required yet; used by admin modules + Stripe lifecycle listener.
  }

  public static function get(int $quote_id) : string {
    $state = (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true);
    if ($state === '') return SD_Quote_State::PROPOSED;

    if (!in_array($state, SD_Quote_State::all(), true)) {
      SD_Util::log('quote_state_invalid', ['quote_id' => $quote_id, 'state' => $state]);
      return SD_Quote_State::PROPOSED;
    }

    return $state;
  }

  public static function set(int $quote_id, string $to_state, array $ctx = []) : bool {
    $to_state = (string) $to_state;

    if (!in_array($to_state, SD_Quote_State::all(), true)) {
      SD_Util::log('quote_state_set_rejected_invalid', ['quote_id' => $quote_id, 'to' => $to_state]);
      return false;
    }

    // Optional guard: ensure this is really a quote
    if (class_exists('SD_Module_QuoteCPT') && get_post_type($quote_id) !== SD_Module_QuoteCPT::CPT) {
      SD_Util::log('quote_state_set_rejected_wrong_type', ['quote_id' => $quote_id]);
      return false;
    }

    $from = self::get($quote_id);

    if ($from !== $to_state && !SD_Quote_State::can_transition($from, $to_state)) {
      SD_Util::log('quote_state_set_rejected_transition', [
        'quote_id' => $quote_id,
        'from'     => $from,
        'to'       => $to_state,
      ]);
      return false;
    }

    update_post_meta($quote_id, SD_Meta::QUOTE_STATUS, $to_state);
    update_post_meta($quote_id, SD_Meta::P_QUOTE_STATUS_UPDATED_AT, (string) time());

    SD_Util::log('quote_state_set', [
      'quote_id' => $quote_id,
      'from'     => $from,
      'to'       => $to_state,
      'ctx'      => $ctx,
    ]);

    return true;
  }
}