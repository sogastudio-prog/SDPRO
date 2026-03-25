<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Canon event types for time–space ledger (v1).
 * Keep this intentionally small.
 */
final class SD_TimeSpace_EventType {

  // Driver behavior / competitive occupancy
  public const THIRD_PARTY_STARTED = 'THIRD_PARTY_STARTED';
  public const THIRD_PARTY_ENDED   = 'THIRD_PARTY_ENDED';

  // Lead lifecycle / projection
  public const LEAD_PROJECTED = 'LEAD_PROJECTED';
  public const LEAD_AVAILABLE = 'LEAD_AVAILABLE';

  // Commercial lifecycle
  public const QUOTE_PRESENTED = 'QUOTE_PRESENTED';

  // Financial commitment
  public const AUTHORIZED = 'AUTHORIZED';

  // Operational lifecycle
  public const RIDE_STARTED   = 'RIDE_STARTED';
  public const RIDE_COMPLETED = 'RIDE_COMPLETED';

  public static function all() : array {
    return [
      self::THIRD_PARTY_STARTED,
      self::THIRD_PARTY_ENDED,
      self::LEAD_PROJECTED,
      self::LEAD_AVAILABLE,
      self::QUOTE_PRESENTED,
      self::AUTHORIZED,
      self::RIDE_STARTED,
      self::RIDE_COMPLETED,
    ];
  }
}