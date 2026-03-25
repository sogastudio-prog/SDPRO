<?php
if (!defined('ABSPATH')) { exit; }

final class SD_TimeBlockRepository {

  public static function find_open_blocks(int $tenant_id, int $start_ts, int $end_ts, int $limit = 20) : array {
    $tenant_id = absint($tenant_id);
    $start_ts  = (int) $start_ts;
    $end_ts    = (int) $end_ts;
    $limit     = max(1, min(100, (int) $limit));

    if ($tenant_id <= 0 || $start_ts <= 0 || $end_ts <= $start_ts) {
      return [];
    }

    $q = new WP_Query([
      'post_type'      => SD_Module_TimeBlockCPT::CPT,
      'post_status'    => 'publish',
      'posts_per_page' => $limit,
      'fields'         => 'ids',
      'orderby'        => 'meta_value_num',
      'order'          => 'ASC',
      'meta_key'       => SD_Meta::TIMEBLOCK_START_TS,
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'   => SD_Meta::TENANT_ID,
          'value' => $tenant_id,
          'type'  => 'NUMERIC',
        ],
        [
          'key'   => SD_Meta::TIMEBLOCK_STATUS,
          'value' => 'OPEN',
        ],
        [
          'key'     => SD_Meta::TIMEBLOCK_START_TS,
          'value'   => $end_ts,
          'compare' => '<=',
          'type'    => 'NUMERIC',
        ],
        [
          'key'     => SD_Meta::TIMEBLOCK_END_TS,
          'value'   => $start_ts,
          'compare' => '>=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    return array_map('intval', (array) $q->posts);
  }

  public static function create_block(array $data) : int {
    $tenant_id = absint($data['tenant_id'] ?? 0);
    $start_ts  = (int) ($data['start_ts'] ?? 0);
    $end_ts    = (int) ($data['end_ts'] ?? 0);
    $driver_id = absint($data['driver_id'] ?? 0);
    $capacity  = max(0, (int) ($data['capacity'] ?? max(0, (int) round(($end_ts - $start_ts) / 60))));
    $status    = self::normalize_status((string) ($data['status'] ?? 'OPEN'));
    $title     = trim((string) ($data['title'] ?? ''));

    if ($tenant_id <= 0 || $start_ts <= 0 || $end_ts <= $start_ts) {
      return 0;
    }

    if ($title === '') {
      $title = sprintf(
        'Time Block — %s → %s',
        gmdate('Y-m-d H:i', $start_ts),
        gmdate('H:i', $end_ts)
      );
    }

    $post_id = wp_insert_post([
      'post_type'   => SD_Module_TimeBlockCPT::CPT,
      'post_status' => 'publish',
      'post_title'  => $title,
    ], true);

    if (is_wp_error($post_id) || (int) $post_id <= 0) {
      return 0;
    }

    $post_id = (int) $post_id;

    update_post_meta($post_id, SD_Meta::TENANT_ID, $tenant_id);
    update_post_meta($post_id, SD_Meta::TIMEBLOCK_START_TS, $start_ts);
    update_post_meta($post_id, SD_Meta::TIMEBLOCK_END_TS, $end_ts);
    update_post_meta($post_id, SD_Meta::TIMEBLOCK_CAPACITY, $capacity);
    update_post_meta($post_id, SD_Meta::TIMEBLOCK_SPENT, 0);
    update_post_meta($post_id, SD_Meta::TIMEBLOCK_STATUS, $status);

    if ($driver_id > 0) {
      update_post_meta($post_id, SD_Meta::TIMEBLOCK_DRIVER_ID, $driver_id);
    }

    return $post_id;
  }

  public static function hold_block(int $block_id, int $lead_id) : bool {
    $block_id = absint($block_id);
    $lead_id  = absint($lead_id);

    if ($block_id <= 0 || $lead_id <= 0) {
      return false;
    }

    if (get_post_type($block_id) !== SD_Module_TimeBlockCPT::CPT) {
      return false;
    }

    $status = (string) get_post_meta($block_id, SD_Meta::TIMEBLOCK_STATUS, true);
    if ($status !== 'OPEN') {
      return false;
    }

    update_post_meta($block_id, SD_Meta::TIMEBLOCK_STATUS, 'HELD');
    update_post_meta($block_id, SD_Meta::TIMEBLOCK_LEAD_ID, $lead_id);
    update_post_meta($block_id, SD_Meta::TIMEBLOCK_HELD_AT, time());

    return true;
  }

  public static function release_block(int $block_id) : bool {
    $block_id = absint($block_id);
    if ($block_id <= 0) {
      return false;
    }

    if (get_post_type($block_id) !== SD_Module_TimeBlockCPT::CPT) {
      return false;
    }

    update_post_meta($block_id, SD_Meta::TIMEBLOCK_STATUS, 'OPEN');
    delete_post_meta($block_id, SD_Meta::TIMEBLOCK_LEAD_ID);
    delete_post_meta($block_id, SD_Meta::TIMEBLOCK_RIDE_ID);
    delete_post_meta($block_id, SD_Meta::TIMEBLOCK_HELD_AT);
    delete_post_meta($block_id, SD_Meta::TIMEBLOCK_COMMITTED_AT);

    return true;
  }

  public static function commit_block(int $block_id, int $lead_id, int $ride_id) : bool {
    $block_id = absint($block_id);
    $lead_id  = absint($lead_id);
    $ride_id  = absint($ride_id);

    if ($block_id <= 0 || $lead_id <= 0 || $ride_id <= 0) {
      return false;
    }

    if (get_post_type($block_id) !== SD_Module_TimeBlockCPT::CPT) {
      return false;
    }

    $held_lead_id = (int) get_post_meta($block_id, SD_Meta::TIMEBLOCK_LEAD_ID, true);
    $status       = (string) get_post_meta($block_id, SD_Meta::TIMEBLOCK_STATUS, true);

    if ($status !== 'HELD' || $held_lead_id !== $lead_id) {
      return false;
    }

    update_post_meta($block_id, SD_Meta::TIMEBLOCK_STATUS, 'COMMITTED');
    update_post_meta($block_id, SD_Meta::TIMEBLOCK_RIDE_ID, $ride_id);
    update_post_meta($block_id, SD_Meta::TIMEBLOCK_COMMITTED_AT, time());

    return true;
  }

  public static function find_blocks_for_lead(int $lead_id, array $statuses = []) : array {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) {
      return [];
    }

    $meta_query = [
      [
        'key'   => SD_Meta::TIMEBLOCK_LEAD_ID,
        'value' => $lead_id,
        'type'  => 'NUMERIC',
      ],
    ];

    $statuses = array_values(array_filter(array_map('strval', $statuses)));
    if (!empty($statuses)) {
      $meta_query[] = [
        'key'     => SD_Meta::TIMEBLOCK_STATUS,
        'value'   => $statuses,
        'compare' => 'IN',
      ];
    }

    $q = new WP_Query([
      'post_type'      => SD_Module_TimeBlockCPT::CPT,
      'post_status'    => 'publish',
      'posts_per_page' => 50,
      'fields'         => 'ids',
      'orderby'        => 'meta_value_num',
      'order'          => 'ASC',
      'meta_key'       => SD_Meta::TIMEBLOCK_START_TS,
      'meta_query'     => $meta_query,
    ]);

    return array_map('intval', (array) $q->posts);
  }

  public static function expire_held_blocks(int $older_than_ts) : int {
    $older_than_ts = (int) $older_than_ts;
    if ($older_than_ts <= 0) {
      return 0;
    }

    $q = new WP_Query([
      'post_type'      => SD_Module_TimeBlockCPT::CPT,
      'post_status'    => 'publish',
      'posts_per_page' => 200,
      'fields'         => 'ids',
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'   => SD_Meta::TIMEBLOCK_STATUS,
          'value' => 'HELD',
        ],
        [
          'key'     => SD_Meta::TIMEBLOCK_HELD_AT,
          'value'   => $older_than_ts,
          'compare' => '<=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    $released = 0;
    foreach ((array) $q->posts as $block_id) {
      if (self::release_block((int) $block_id)) {
        $released++;
      }
    }

    return $released;
  }

  private static function normalize_status(string $status) : string {
    $status = strtoupper(trim($status));
    $allowed = ['OPEN', 'HELD', 'COMMITTED', 'EXPIRED'];
    return in_array($status, $allowed, true) ? $status : 'OPEN';
  }
}