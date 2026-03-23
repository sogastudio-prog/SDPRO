<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontRideTriggers {

  public static function register() : void {
    add_action('updated_post_meta', [__CLASS__, 'on_post_meta_updated'], 10, 4);
    add_action('added_post_meta', [__CLASS__, 'on_post_meta_updated'], 10, 4);
  }

  public static function on_post_meta_updated($meta_id, int $post_id, string $meta_key, $meta_value) : void {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'sd_ride') {
      return;
    }

    if (!in_array($meta_key, [
      'state',
      'sd_driver_user_id',
      'sd_tenant_id',
    ], true)) {
      return;
    }

    $tenant_id = (int) get_post_meta($post_id, 'sd_tenant_id', true);
    if ($tenant_id < 1) {
      return;
    }

    SD_StorefrontTriggerBus::trigger_for_tenant(
      $tenant_id,
      'ride_meta_updated',
      [
        'ride_id'   => $post_id,
        'meta_key'  => $meta_key,
      ]
    );
  }
}

SD_StorefrontRideTriggers::register();