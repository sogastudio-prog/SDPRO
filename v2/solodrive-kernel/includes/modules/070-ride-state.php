<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Ride_State — Canonical execution lifecycle
 *
 * This is the *only* place canonical ride states are defined.
 */
final class SD_Ride_State {

  // Execution lifecycle
  public const QUEUED     = 'RIDE_QUEUED';
  public const DEADHEAD   = 'RIDE_DEADHEAD';
  public const WAITING    = 'RIDE_WAITING';
  public const INPROGRESS = 'RIDE_INPROGRESS';
  public const ARRIVED    = 'RIDE_ARRIVED';
  public const COMPLETE   = 'RIDE_COMPLETE';
  public const CANCELLED  = 'RIDE_CANCELLED';

  public static function all() : array {
    return [
      self::QUEUED,
      self::DEADHEAD,
      self::WAITING,
      self::INPROGRESS,
      self::ARRIVED,
      self::COMPLETE,
      self::CANCELLED,
    ];
  }

  public static function is_terminal(string $state) : bool {
    return in_array($state, [self::COMPLETE, self::CANCELLED], true);
  }

  /**
   * Minimal allowed transitions (fail-soft: service may allow same-state).
   * Tighten later if you want a strict governor.
   */
  public static function can_transition(string $from, string $to) : bool {

    if ($from === $to) return true;

    $allowed = [
      self::QUEUED     => [self::DEADHEAD, self::WAITING, self::CANCELLED],
      self::DEADHEAD   => [self::WAITING, self::ARRIVED, self::CANCELLED],
      self::WAITING    => [self::INPROGRESS, self::CANCELLED],
      self::INPROGRESS => [self::ARRIVED, self::COMPLETE, self::CANCELLED],
      self::ARRIVED    => [self::INPROGRESS, self::COMPLETE, self::CANCELLED],
      self::COMPLETE   => [],
      self::CANCELLED  => [],
    ];

    return in_array($to, $allowed[$from] ?? [], true);
  }
}