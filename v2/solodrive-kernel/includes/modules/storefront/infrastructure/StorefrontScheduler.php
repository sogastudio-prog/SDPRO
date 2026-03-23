<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontScheduler {

  private const CRON_HOOK = 'sd_storefront_sweep';

  public static function register() : void {
    add_filter('cron_schedules', [__CLASS__, 'add_schedule']);
    add_action('init', [__CLASS__, 'ensure_scheduled']);
    add_action(self::CRON_HOOK, [__CLASS__, 'run_sweep']);
  }

  public static function add_schedule(array $schedules) : array {
    if (!isset($schedules['sd_every_five_minutes'])) {
      $schedules['sd_every_five_minutes'] = [
        'interval' => 300,
        'display'  => 'SoloDrive Every Five Minutes',
      ];
    }

    return $schedules;
  }

  public static function ensure_scheduled() : void {
    if (!wp_next_scheduled(self::CRON_HOOK)) {
      wp_schedule_event(time() + 60, 'sd_every_five_minutes', self::CRON_HOOK);
    }
  }

  public static function run_sweep() : void {
    $tenant_ids = self::active_tenant_ids();

    foreach ($tenant_ids as $tenant_id) {
      SD_StorefrontTriggerBus::trigger_for_tenant(
        (int) $tenant_id,
        'scheduled_sweep',
        []
      );
    }
  }

  /**
   * @return array<int>
   */
  private static function active_tenant_ids() : array {
    $ids = get_posts([
      'post_type'      => 'sd_tenant',
      'post_status'    => 'publish',
      'posts_per_page' => 500,
      'fields'         => 'ids',
    ]);

    return is_array($ids) ? array_map('intval', $ids) : [];
  }
}

SD_StorefrontScheduler::register();