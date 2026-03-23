<?php
if (!defined('ABSPATH')) { exit; }

final class SD_TenantStorefrontPolicyRepository {

  public static function get_policy(int $tenant_id) : SD_StorefrontPolicy {
    // Replace these meta keys with your final canonical tenant meta registry later.
    $raw = [
      'storefront_enabled'          => (bool) get_post_meta($tenant_id, 'sd_storefront_enabled', true),
      'manual_mode'                 => (string) get_post_meta($tenant_id, 'sd_storefront_manual_mode', true),
      'on_demand_enabled'           => self::bool_meta($tenant_id, 'sd_storefront_on_demand_enabled', true),
      'stacked_enabled'             => self::bool_meta($tenant_id, 'sd_storefront_stacked_enabled', false),
      'waitlist_enabled'            => self::bool_meta($tenant_id, 'sd_storefront_waitlist_enabled', false),
      'reservations_enabled'        => self::bool_meta($tenant_id, 'sd_storefront_reservations_enabled', true),
      'max_active_rides_per_driver' => (int) get_post_meta($tenant_id, 'sd_max_active_rides_per_driver', true),
      'max_stacked_rides_per_driver'=> (int) get_post_meta($tenant_id, 'sd_max_stacked_rides_per_driver', true),
      'waitlist_limit'              => (int) get_post_meta($tenant_id, 'sd_waitlist_limit', true),
      'auto_close_if_no_drivers'    => self::bool_meta($tenant_id, 'sd_auto_close_if_no_drivers', false),
      'weekly_hours'                => get_post_meta($tenant_id, 'sd_weekly_hours', true) ?: [],
      'holiday_overrides'           => get_post_meta($tenant_id, 'sd_holiday_overrides', true) ?: [],
      'manual_closures'             => get_post_meta($tenant_id, 'sd_manual_closures', true) ?: [],
      'open_headline'               => (string) get_post_meta($tenant_id, 'sd_open_headline', true),
      'busy_headline'               => (string) get_post_meta($tenant_id, 'sd_busy_headline', true),
      'closed_headline'             => (string) get_post_meta($tenant_id, 'sd_closed_headline', true),
      'resume_message'              => (string) get_post_meta($tenant_id, 'sd_resume_message', true),
      'no_driver_message'           => (string) get_post_meta($tenant_id, 'sd_no_driver_message', true),
      'instant_workflow'            => (string) get_post_meta($tenant_id, 'sd_instant_workflow', true),
      'stacked_workflow'            => (string) get_post_meta($tenant_id, 'sd_stacked_workflow', true),
      'waitlist_workflow'           => (string) get_post_meta($tenant_id, 'sd_waitlist_workflow', true),
      'reservation_workflow'        => (string) get_post_meta($tenant_id, 'sd_reservation_workflow', true),
    ];

    return SD_StorefrontPolicy::from_array($tenant_id, $raw);
  }

  private static function bool_meta(int $tenant_id, string $key, bool $default = false) : bool {
    $raw = get_post_meta($tenant_id, $key, true);
    if ($raw === '') return $default;
    return in_array((string) $raw, ['1', 'true', 'yes', 'on'], true);
  }
}