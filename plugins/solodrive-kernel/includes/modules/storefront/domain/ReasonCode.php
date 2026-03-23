<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontReasonCode {
  public const OPEN_INSTANT                     = 'OPEN_INSTANT';
  public const OPEN_STACK_ONLY                  = 'OPEN_STACK_ONLY';
  public const CAPACITY_REACHED_WAITLIST_OPEN   = 'CAPACITY_REACHED_WAITLIST_OPEN';
  public const CAPACITY_REACHED_RESERVE_ONLY    = 'CAPACITY_REACHED_RESERVE_ONLY';
  public const CLOSED_HOURS                     = 'CLOSED_HOURS';
  public const NO_DRIVERS_ONLINE                = 'NO_DRIVERS_ONLINE';
  public const MANUAL_BUSY                      = 'MANUAL_BUSY';
  public const MANUAL_CLOSED                    = 'MANUAL_CLOSED';
  public const TENANT_DISABLED                  = 'TENANT_DISABLED';
  public const OUT_OF_SERVICE_AREA              = 'OUT_OF_SERVICE_AREA';

  public static function all() : array {
    return [
      self::OPEN_INSTANT,
      self::OPEN_STACK_ONLY,
      self::CAPACITY_REACHED_WAITLIST_OPEN,
      self::CAPACITY_REACHED_RESERVE_ONLY,
      self::CLOSED_HOURS,
      self::NO_DRIVERS_ONLINE,
      self::MANUAL_BUSY,
      self::MANUAL_CLOSED,
      self::TENANT_DISABLED,
      self::OUT_OF_SERVICE_AREA,
    ];
  }
}