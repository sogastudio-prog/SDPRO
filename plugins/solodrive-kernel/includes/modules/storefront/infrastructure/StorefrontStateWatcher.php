<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontStateWatcher {

  private const OPTION_KEY_PREFIX = 'sd_storefront_state_snapshot_';

  public static function register() : void {
    add_action('sd/storefront/watch_tenant', [__CLASS__, 'watch_tenant'], 10, 3);
  }

  /**
   * @param array<string,mixed> $context
   */
  public static function watch_tenant(int $tenant_id, string $reason = 'unknown', array $context = []) : void {
    if ($tenant_id < 1) {
      return;
    }

    $policy   = SD_TenantStorefrontPolicyRepository::get_policy($tenant_id);
    $ctx      = SD_StorefrontOperationalContextService::resolve($tenant_id, $policy);
    $decision = SD_StorefrontDecisionEngine::decide($policy, $ctx);

    $current = [
      'tenant_id'                  => $tenant_id,
      'public_state'               => $decision->public_state,
      'availability_mode'          => $decision->availability_mode,
      'reason_code'                => $decision->reason_code,
      'online_drivers'             => (int) $ctx->online_drivers,
      'active_rides'               => (int) $ctx->active_rides,
      'instant_capacity_remaining' => (int) $ctx->instant_capacity_remaining,
      'stack_slots_remaining'      => (int) $ctx->stack_slots_remaining,
      'waitlist_count'             => (int) $ctx->waitlist_count,
      'within_service_hours'       => (bool) $ctx->within_service_hours,
      'manual_override'            => (string) $ctx->manual_override,
      'trigger_reason'             => $reason,
      'trigger_context'            => $context,
      'ts'                         => time(),
    ];

    $previous = self::get_previous_snapshot($tenant_id);
    self::store_snapshot($tenant_id, $current);

    do_action('sd/storefront/watcher_ran', $tenant_id, $reason, $context, $previous, $current);

    if (!$previous) {
      do_action('sd/storefront/initialized', $tenant_id, $current);
      return;
    }

    if (self::changed($previous, $current, 'public_state')
      || self::changed($previous, $current, 'availability_mode')
      || self::changed($previous, $current, 'reason_code')) {
      do_action('sd/storefront/state_changed', $tenant_id, $previous, $current);
    }

    if ((int) $previous['instant_capacity_remaining'] < 1 && (int) $current['instant_capacity_remaining'] > 0) {
      do_action('sd/storefront/instant_capacity_restored', $tenant_id, $previous, $current);

      if ((int) $current['waitlist_count'] > 0) {
        $result = SD_WaitlistConversionService::convert_up_to_capacity($tenant_id, $policy);
        do_action('sd/storefront/waitlist_conversion_attempted', $tenant_id, $result, $previous, $current);
      }
    }

    if ((int) $previous['waitlist_count'] < 1 && (int) $current['waitlist_count'] > 0) {
      do_action('sd/storefront/waitlist_opened', $tenant_id, $previous, $current);
    }

    if ((int) $previous['online_drivers'] < 1 && (int) $current['online_drivers'] > 0) {
      do_action('sd/storefront/drivers_online', $tenant_id, $previous, $current);
    }

    if ((int) $previous['online_drivers'] > 0 && (int) $current['online_drivers'] < 1) {
      do_action('sd/storefront/drivers_offline', $tenant_id, $previous, $current);
    }
  }

  private static function get_previous_snapshot(int $tenant_id) : ?array {
    $raw = get_option(self::OPTION_KEY_PREFIX . $tenant_id, '');
    if (!is_string($raw) || $raw === '') {
      return null;
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
  }

  private static function store_snapshot(int $tenant_id, array $snapshot) : void {
    update_option(self::OPTION_KEY_PREFIX . $tenant_id, wp_json_encode($snapshot), false);
  }

  private static function changed(array $previous, array $current, string $key) : bool {
    return ($previous[$key] ?? null) !== ($current[$key] ?? null);
  }
}

SD_StorefrontStateWatcher::register();