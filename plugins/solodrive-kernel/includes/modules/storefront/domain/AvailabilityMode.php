<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontAvailabilityMode {
  public const INSTANT      = 'instant';
  public const STACKED_ASAP = 'stacked_asap';
  public const WAITLIST     = 'waitlist';
  public const RESERVE_ONLY = 'reserve_only';
  public const UNAVAILABLE  = 'unavailable';

  public static function all() : array {
    return [
      self::INSTANT,
      self::STACKED_ASAP,
      self::WAITLIST,
      self::RESERVE_ONLY,
      self::UNAVAILABLE,
    ];
  }

  public static function is_valid(string $value) : bool {
    return in_array($value, self::all(), true);
  }
}