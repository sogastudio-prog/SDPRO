<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Quote_State {

  public const DRAFT            = 'DRAFT';
  public const PENDING_OPERATOR = 'PENDING_OPERATOR';
  public const APPROVED         = 'APPROVED';
  public const PRESENTED        = 'PRESENTED';
  public const ACCEPTED         = 'ACCEPTED';
  public const REJECTED         = 'REJECTED';
  public const EXPIRED          = 'EXPIRED';
  public const SUPERSEDED       = 'SUPERSEDED';
  public const CANCELLED        = 'CANCELLED';

  public static function all() : array {
    return [
      self::DRAFT,
      self::PENDING_OPERATOR,
      self::APPROVED,
      self::PRESENTED,
      self::ACCEPTED,
      self::REJECTED,
      self::EXPIRED,
      self::SUPERSEDED,
      self::CANCELLED,
    ];
  }

  public static function can_transition(string $from, string $to) : bool {
    if ($from === $to) return true;

    $allowed = [
      self::DRAFT            => [self::PENDING_OPERATOR, self::CANCELLED, self::EXPIRED],
      self::PENDING_OPERATOR => [self::APPROVED, self::CANCELLED, self::EXPIRED],
      self::APPROVED         => [self::PRESENTED, self::CANCELLED, self::EXPIRED],
      self::PRESENTED        => [self::ACCEPTED, self::REJECTED, self::EXPIRED, self::CANCELLED],
      self::ACCEPTED         => [self::CANCELLED],
      self::REJECTED         => [],
      self::EXPIRED          => [],
      self::SUPERSEDED       => [],
      self::CANCELLED        => [],
    ];

    return in_array($to, $allowed[$from] ?? [], true);
  }
}

final class SD_Module_QuoteStateService {

  public static function register() : void {
    // Library-style service.
  }

  public static function get(int $quote_id) : string {
    $state = (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true);
    if ($state === '') {
      return SD_Quote_State::DRAFT;
    }

    if (!in_array($state, SD_Quote_State::all(), true)) {
      return SD_Quote_State::DRAFT;
    }

    return $state;
  }

  public static function set(int $quote_id, string $to_state, array $ctx = []) : bool {
    $quote_id  = absint($quote_id);
    $to_state  = strtoupper(trim((string) $to_state));

    if ($quote_id <= 0) return false;
    if (get_post_type($quote_id) !== SD_Module_QuoteCPT::CPT) return false;
    if (!in_array($to_state, SD_Quote_State::all(), true)) return false;

    $from = self::get($quote_id);
    if ($from !== $to_state && !SD_Quote_State::can_transition($from, $to_state)) {
      self::log('quote_state_transition_rejected', [
        'quote_id' => $quote_id,
        'from'     => $from,
        'to'       => $to_state,
      ]);
      return false;
    }

    update_post_meta($quote_id, SD_Meta::QUOTE_STATUS, $to_state);
    update_post_meta($quote_id, SD_Meta::P_QUOTE_STATUS_UPDATED_AT, time());

    if ($to_state === SD_Quote_State::APPROVED) {
      update_post_meta($quote_id, SD_Meta::P_QUOTE_APPROVED_AT, time());
    } elseif ($to_state === SD_Quote_State::PRESENTED) {
      update_post_meta($quote_id, SD_Meta::P_QUOTE_PRESENTED_AT, time());
    } elseif ($to_state === SD_Quote_State::ACCEPTED) {
      update_post_meta($quote_id, SD_Meta::P_QUOTE_ACCEPTED_AT, time());
    } elseif ($to_state === SD_Quote_State::REJECTED) {
      update_post_meta($quote_id, SD_Meta::P_QUOTE_REJECTED_AT, time());
    }

    $lead_id = (int) get_post_meta($quote_id, SD_Meta::LEAD_ID, true);
    if ($lead_id > 0) {
      if (in_array($to_state, [SD_Quote_State::DRAFT, SD_Quote_State::PENDING_OPERATOR], true)) {
        update_post_meta($lead_id, SD_Meta::LEAD_STATUS, 'LEAD_QUOTING');
      } elseif (in_array($to_state, [SD_Quote_State::APPROVED, SD_Quote_State::PRESENTED], true)) {
        update_post_meta($lead_id, SD_Meta::LEAD_STATUS, 'LEAD_QUOTED');
      } elseif ($to_state === SD_Quote_State::ACCEPTED) {
        update_post_meta($lead_id, SD_Meta::LEAD_STATUS, 'LEAD_AUTH_PENDING');
      } elseif (in_array($to_state, [SD_Quote_State::REJECTED, SD_Quote_State::EXPIRED, SD_Quote_State::CANCELLED], true)) {
        update_post_meta($lead_id, SD_Meta::LEAD_STATUS, 'LEAD_DECLINED');
      }

      update_post_meta($lead_id, SD_Meta::P_STATE_UPDATED_AT, time());
    }

    self::log('quote_state_set', [
      'quote_id' => $quote_id,
      'from'     => $from,
      'to'       => $to_state,
      'ctx'      => $ctx,
    ]);

    return true;
  }

  private static function log(string $event, array $ctx = []) : void {
    if (class_exists('SD_Util')) {
      SD_Util::log($event, $ctx);
    }
  }
}