<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontWaitlistTriggers {

  public static function register() : void {
    add_action('sd/waitlist/created', [__CLASS__, 'on_waitlist_changed'], 10, 3);
    add_action('sd/waitlist/converted', [__CLASS__, 'on_waitlist_changed_converted'], 10, 2);
    add_action('sd/waitlist/expired', [__CLASS__, 'on_waitlist_simple'], 10, 1);
    add_action('sd/waitlist/cancelled', [__CLASS__, 'on_waitlist_simple'], 10, 1);
  }

  public static function on_waitlist_changed(int $entry_id, int $tenant_id, array $payload) : void {
    SD_StorefrontTriggerBus::trigger_for_tenant(
      $tenant_id,
      'waitlist_changed',
      ['entry_id' => $entry_id]
    );
  }

  public static function on_waitlist_changed_converted(int $entry_id, int $ride_id) : void {
    $tenant_id = (int) get_post_meta($entry_id, 'sd_tenant_id', true);
    if ($tenant_id < 1) {
      return;
    }

    SD_StorefrontTriggerBus::trigger_for_tenant(
      $tenant_id,
      'waitlist_changed',
      [
        'entry_id' => $entry_id,
        'ride_id'  => $ride_id,
      ]
    );
  }

  public static function on_waitlist_simple(int $entry_id) : void {
    $tenant_id = (int) get_post_meta($entry_id, 'sd_tenant_id', true);
    if ($tenant_id < 1) {
      return;
    }

    SD_StorefrontTriggerBus::trigger_for_tenant(
      $tenant_id,
      'waitlist_changed',
      ['entry_id' => $entry_id]
    );
  }
}

SD_StorefrontWaitlistTriggers::register();