<?php
if (!defined('ABSPATH')) { exit; }

final class SD_TimeBlockRepository {

  public static function create_for_ride(int $ride_id, array $block) : int {

    $tenant_id = (int) ($block['tenant_id'] ?? 0);
    if ($tenant_id < 1) return 0;

    $post_id = wp_insert_post([
      'post_type'   => SD_CPT_TimeBlock::CPT,
      'post_status' => 'publish',
      'post_title'  => 'Ride Block #' . $ride_id,
    ]);

    if (!$post_id) return 0;

    update_post_meta($post_id, SD_Meta::BLOCK_TENANT_ID, $tenant_id);
    update_post_meta($post_id, SD_Meta::BLOCK_RIDE_ID, $ride_id);
    update_post_meta($post_id, SD_Meta::BLOCK_TYPE, 'ASSIGNED_RIDE');
    update_post_meta($post_id, SD_Meta::BLOCK_STATUS, 'ACTIVE');
    update_post_meta($post_id, SD_Meta::BLOCK_START_TS, (int)$block['start_ts']);
    update_post_meta($post_id, SD_Meta::BLOCK_END_TS, (int)$block['end_ts']);

    return (int)$post_id;
  }

  public static function is_available(int $tenant_id, int $start_ts, int $end_ts) : bool {

    if ($tenant_id < 1) return false;

    $q = new WP_Query([
      'post_type' => SD_CPT_TimeBlock::CPT,
      'post_status' => 'publish',
      'meta_query' => [
        [
          'key' => SD_Meta::BLOCK_TENANT_ID,
          'value' => $tenant_id,
        ],
        [
          'relation' => 'AND',
          [
            'key' => SD_Meta::BLOCK_START_TS,
            'value' => $end_ts,
            'compare' => '<',
            'type' => 'NUMERIC',
          ],
          [
            'key' => SD_Meta::BLOCK_END_TS,
            'value' => $start_ts,
            'compare' => '>',
            'type' => 'NUMERIC',
          ],
        ],
      ],
      'posts_per_page' => 1,
      'fields' => 'ids',
    ]);

    return empty($q->posts);
  }
}