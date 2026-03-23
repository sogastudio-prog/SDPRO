<?php
if (!defined('ABSPATH')) { exit; }

final class SD_WaitlistGateway {

  public const CPT = 'sd_waitlist_entry';

  private const OPEN_STATUSES = [
    'waiting',
    'notified',
  ];

  public static function count_open_entries(int $tenant_id) : int {
    return count(self::get_open_entry_ids($tenant_id));
  }

  /**
   * @return array<int>
   */
  public static function get_open_entry_ids(int $tenant_id) : array {
    $ids = get_posts([
      'post_type'      => self::CPT,
      'post_status'    => 'any',
      'posts_per_page' => 500,
      'fields'         => 'ids',
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'   => 'sd_tenant_id',
          'value' => (string) $tenant_id,
        ],
        [
          'key'     => 'sd_waitlist_status',
          'value'   => self::OPEN_STATUSES,
          'compare' => 'IN',
        ],
      ],
    ]);

    return is_array($ids) ? array_map('intval', $ids) : [];
  }

  /**
   * @return array<int,array<string,mixed>>
   */
  public static function get_open_entries(int $tenant_id, int $limit = 50) : array {
    $ids = get_posts([
      'post_type'      => self::CPT,
      'post_status'    => 'any',
      'posts_per_page' => $limit,
      'orderby'        => 'date',
      'order'          => 'ASC',
      'fields'         => 'ids',
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'   => 'sd_tenant_id',
          'value' => (string) $tenant_id,
        ],
        [
          'key'     => 'sd_waitlist_status',
          'value'   => self::OPEN_STATUSES,
          'compare' => 'IN',
        ],
      ],
    ]);

    $rows = [];

    foreach ($ids as $entry_id) {
      $entry_id = (int) $entry_id;

      $rows[] = [
        'entry_id'          => $entry_id,
        'tenant_id'         => (int) get_post_meta($entry_id, 'sd_tenant_id', true),
        'pickup'            => (string) get_post_meta($entry_id, 'sd_pickup_text', true),
        'dropoff'           => (string) get_post_meta($entry_id, 'sd_dropoff_text', true),
        'contact'           => (string) get_post_meta($entry_id, 'sd_contact', true),
        'requested_at'      => (int) get_post_meta($entry_id, 'sd_requested_at', true),
        'status'            => (string) get_post_meta($entry_id, 'sd_waitlist_status', true),
        'converted_ride_id' => (int) get_post_meta($entry_id, 'sd_converted_ride_id', true),
      ];
    }

    return $rows;
  }

  /**
   * @return array<string,mixed>
   */
  public static function create_entry(int $tenant_id, array $payload) : array {
    $post_id = wp_insert_post([
      'post_type'   => self::CPT,
      'post_status' => 'publish',
      'post_title'  => self::build_title($payload),
    ], true);

    if (is_wp_error($post_id)) {
      return [
        'ok'      => false,
        'message' => $post_id->get_error_message(),
      ];
    }

    update_post_meta($post_id, 'sd_tenant_id', $tenant_id);
    update_post_meta($post_id, 'sd_pickup_text', (string) ($payload['pickup'] ?? ''));
    update_post_meta($post_id, 'sd_dropoff_text', (string) ($payload['dropoff'] ?? ''));
    update_post_meta($post_id, 'sd_contact', (string) ($payload['contact'] ?? ''));
    update_post_meta($post_id, 'sd_requested_at', time());
    update_post_meta($post_id, 'sd_waitlist_status', 'waiting');
    update_post_meta($post_id, 'sd_converted_ride_id', 0);

    return [
      'ok'       => true,
      'entry_id' => (int) $post_id,
    ];
  }

  public static function mark_converted(int $entry_id, int $ride_id) : void {
    update_post_meta($entry_id, 'sd_waitlist_status', 'converted');
    update_post_meta($entry_id, 'sd_converted_ride_id', $ride_id);
  }

  public static function mark_expired(int $entry_id) : void {
    update_post_meta($entry_id, 'sd_waitlist_status', 'expired');
  }

  public static function mark_cancelled(int $entry_id) : void {
    update_post_meta($entry_id, 'sd_waitlist_status', 'cancelled');
  }

  private static function build_title(array $payload) : string {
    $contact = trim((string) ($payload['contact'] ?? ''));
    $pickup  = trim((string) ($payload['pickup'] ?? ''));

    if ($contact !== '' && $pickup !== '') {
      return sprintf('Waitlist — %s — %s', $contact, $pickup);
    }

    if ($contact !== '') {
      return sprintf('Waitlist — %s', $contact);
    }

    return 'Waitlist Entry';
  }
}