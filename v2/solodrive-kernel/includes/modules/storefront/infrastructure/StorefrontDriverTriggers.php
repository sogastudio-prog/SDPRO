<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontDriverTriggers {

  public static function register() : void {
    add_action('updated_user_meta', [__CLASS__, 'on_user_meta_updated'], 10, 4);
    add_action('added_user_meta', [__CLASS__, 'on_user_meta_updated'], 10, 4);
  }

  public static function on_user_meta_updated($meta_id, int $user_id, string $meta_key, $meta_value) : void {
    if (!in_array($meta_key, [
      'sd_driver_status',
      'sd_driver_last_ping_ts',
      'sd_tenant_id',
    ], true)) {
      return;
    }

    $user = get_userdata($user_id);
    if (!$user || !in_array('sd_driver', (array) $user->roles, true)) {
      return;
    }

    $tenant_id = (int) get_user_meta($user_id, 'sd_tenant_id', true);
    if ($tenant_id < 1) {
      return;
    }

    SD_StorefrontTriggerBus::trigger_for_tenant(
      $tenant_id,
      'driver_meta_updated',
      [
        'user_id'   => $user_id,
        'meta_key'  => $meta_key,
      ]
    );
  }
}

SD_StorefrontDriverTriggers::register();