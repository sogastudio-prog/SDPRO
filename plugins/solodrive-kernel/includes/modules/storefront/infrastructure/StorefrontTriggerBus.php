<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontTriggerBus {

  private const LOCK_TTL = 15; // seconds

  /**
   * @param array<string,mixed> $context
   */
  public static function trigger_for_tenant(int $tenant_id, string $reason, array $context = []) : void {
    if ($tenant_id < 1) {
      return;
    }

    if (!self::acquire_lock($tenant_id, $reason)) {
      return;
    }

    do_action('sd/storefront/watch_tenant', $tenant_id, $reason, $context);
  }

  private static function acquire_lock(int $tenant_id, string $reason) : bool {
    $key = 'sd_storefront_watch_lock_' . $tenant_id . '_' . md5($reason);
    $now = time();
    $exp = (int) get_transient($key);

    if ($exp > $now) {
      return false;
    }

    set_transient($key, $now + self::LOCK_TTL, self::LOCK_TTL);
    return true;
  }
}