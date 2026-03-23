<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_RideTimeBlockSync {

  public static function register() : void {
    add_action('sd_ride_created', [__CLASS__, 'handle_ride_created'], 10, 3);
  }

  public static function handle_ride_created(int $ride_id, int $quote_id, int $tenant_id) : void {
    if ($ride_id <= 0 || $tenant_id <= 0) {
      return;
    }

    if (!class_exists('SD_ServiceBlockBuilder') || !method_exists('SD_ServiceBlockBuilder', 'build_from_ride')) {
      return;
    }

    if (!class_exists('SD_TimeBlockRepository')) {
      return;
    }

    $block = SD_ServiceBlockBuilder::build_from_ride($ride_id);

    if (empty($block['tenant_id']) || empty($block['start_ts']) || empty($block['end_ts'])) {
      return;
    }

    $available = SD_TimeBlockRepository::is_available(
      (int) $block['tenant_id'],
      (int) $block['start_ts'],
      (int) $block['end_ts']
    );

    if (!$available) {
      update_post_meta($ride_id, '_sd_block_conflict', 1);
      update_post_meta($ride_id, '_sd_block_conflict_at', time());

      if (class_exists('SD_Util') && method_exists('SD_Util', 'log')) {
        SD_Util::log('ride_time_block_conflict', [
          'ride_id'   => $ride_id,
          'quote_id'  => $quote_id,
          'tenant_id' => $tenant_id,
          'start_ts'  => (int) $block['start_ts'],
          'end_ts'    => (int) $block['end_ts'],
        ]);
      }

      // v1 fail-soft:
      // mark for later alternate handling, but do not fatal the intake flow yet.
      return;
    }

    $block_id = SD_TimeBlockRepository::create_for_ride($ride_id, $block);

    if ($block_id <= 0) {
      if (class_exists('SD_Util') && method_exists('SD_Util', 'log')) {
        SD_Util::log('ride_time_block_create_failed', [
          'ride_id'   => $ride_id,
          'quote_id'  => $quote_id,
          'tenant_id' => $tenant_id,
          'block'     => $block,
        ]);
      }
      return;
    }

    update_post_meta($ride_id, 'sd_service_start_ts', (int) $block['start_ts']);
    update_post_meta($ride_id, 'sd_service_end_ts', (int) $block['end_ts']);
    update_post_meta($ride_id, 'sd_time_block_id', $block_id);

    if (class_exists('SD_Util') && method_exists('SD_Util', 'log')) {
      SD_Util::log('ride_time_block_created', [
        'ride_id'    => $ride_id,
        'quote_id'   => $quote_id,
        'tenant_id'  => $tenant_id,
        'block_id'   => $block_id,
        'start_ts'   => (int) $block['start_ts'],
        'end_ts'     => (int) $block['end_ts'],
      ]);
    }
  }
}

SD_Module_RideTimeBlockSync::register();