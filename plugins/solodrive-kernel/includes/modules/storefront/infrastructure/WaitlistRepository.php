<?php
if (!defined('ABSPATH')) { exit; }

final class SD_WaitlistRepository {

  public const CPT = 'sd_waitlist_entry';

  public const META_TENANT_ID          = 'sd_tenant_id';
  public const META_STATUS             = 'sd_waitlist_status';
  public const META_PICKUP_TEXT        = 'sd_pickup_text';
  public const META_DROPOFF_TEXT       = 'sd_dropoff_text';
  public const META_CONTACT            = 'sd_contact';
  public const META_REQUESTED_AT       = 'sd_requested_at';
  public const META_CONVERTED_RIDE_ID  = 'sd_converted_ride_id';
  public const META_NOTIFIED_AT        = 'sd_notified_at';
  public const META_EXPIRED_AT         = 'sd_expired_at';
  public const META_CANCELLED_AT       = 'sd_cancelled_at';
  public const META_STORE_SNAPSHOT     = 'sd_storefront_decision_snapshot';

  /**
   * @return array<int,SD_WaitlistEntry>
   */
  public static function find_open_by_tenant(int $tenant_id, int $limit = 50) : array {
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
          'key'   => self::META_TENANT_ID,
          'value' => (string) $tenant_id,
        ],
        [
          'key'     => self::META_STATUS,
          'value'   => [
            SD_WaitlistEntry::STATUS_WAITING,
            SD_WaitlistEntry::STATUS_NOTIFIED,
          ],
          'compare' => 'IN',
        ],
      ],
    ]);

    $rows = [];
    foreach ($ids as $entry_id) {
      $entry = self::find_by_id((int) $entry_id);
      if ($entry) {
        $rows[] = $entry;
      }
    }

    return $rows;
  }

  public static function count_open_by_tenant(int $tenant_id) : int {
    return count(self::find_open_by_tenant($tenant_id, 500));
  }

  public static function find_oldest_convertible(int $tenant_id) : ?SD_WaitlistEntry {
    $rows = self::find_open_by_tenant($tenant_id, 1);
    return $rows[0] ?? null;
  }

  public static function find_by_id(int $entry_id) : ?SD_WaitlistEntry {
    if ($entry_id < 1) {
      return null;
    }

    $post = get_post($entry_id);
    if (!$post || $post->post_type !== self::CPT) {
      return null;
    }

    return new SD_WaitlistEntry(
      $entry_id,
      (int) get_post_meta($entry_id, self::META_TENANT_ID, true),
      (string) get_post_meta($entry_id, self::META_STATUS, true) ?: SD_WaitlistEntry::STATUS_WAITING,
      (string) get_post_meta($entry_id, self::META_PICKUP_TEXT, true),
      (string) get_post_meta($entry_id, self::META_DROPOFF_TEXT, true),
      (string) get_post_meta($entry_id, self::META_CONTACT, true),
      (int) get_post_meta($entry_id, self::META_REQUESTED_AT, true),
      (int) get_post_meta($entry_id, self::META_CONVERTED_RIDE_ID, true),
      [
        'notified_at' => (int) get_post_meta($entry_id, self::META_NOTIFIED_AT, true),
        'expired_at'  => (int) get_post_meta($entry_id, self::META_EXPIRED_AT, true),
        'cancelled_at'=> (int) get_post_meta($entry_id, self::META_CANCELLED_AT, true),
      ]
    );
  }

  /**
   * @param array<string,mixed> $payload
   * @return array<string,mixed>
   */
  public static function create(int $tenant_id, array $payload) : array {
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

    update_post_meta($post_id, self::META_TENANT_ID, $tenant_id);
    update_post_meta($post_id, self::META_STATUS, SD_WaitlistEntry::STATUS_WAITING);
    update_post_meta($post_id, self::META_PICKUP_TEXT, (string) ($payload['pickup_text'] ?? $payload['pickup'] ?? ''));
    update_post_meta($post_id, self::META_DROPOFF_TEXT, (string) ($payload['dropoff_text'] ?? $payload['dropoff'] ?? ''));
    update_post_meta($post_id, self::META_CONTACT, (string) ($payload['contact'] ?? ''));
    update_post_meta($post_id, self::META_REQUESTED_AT, time());

    if (!empty($payload['storefront_decision_snapshot'])) {
      update_post_meta($post_id, self::META_STORE_SNAPSHOT, wp_json_encode($payload['storefront_decision_snapshot']));
    }

    do_action('sd/waitlist/created', (int) $post_id, $tenant_id, $payload);

    return [
      'ok'       => true,
      'entry_id' => (int) $post_id,
    ];
  }

  public static function mark_notified(int $entry_id) : void {
    update_post_meta($entry_id, self::META_STATUS, SD_WaitlistEntry::STATUS_NOTIFIED);
    update_post_meta($entry_id, self::META_NOTIFIED_AT, time());

    do_action('sd/waitlist/notified', $entry_id);
  }

  public static function mark_converted(int $entry_id, int $ride_id) : void {
    update_post_meta($entry_id, self::META_STATUS, SD_WaitlistEntry::STATUS_CONVERTED);
    update_post_meta($entry_id, self::META_CONVERTED_RIDE_ID, $ride_id);

    do_action('sd/waitlist/converted', $entry_id, $ride_id);
  }

  public static function mark_expired(int $entry_id) : void {
    update_post_meta($entry_id, self::META_STATUS, SD_WaitlistEntry::STATUS_EXPIRED);
    update_post_meta($entry_id, self::META_EXPIRED_AT, time());

    do_action('sd/waitlist/expired', $entry_id);
  }

  public static function mark_cancelled(int $entry_id) : void {
    update_post_meta($entry_id, self::META_STATUS, SD_WaitlistEntry::STATUS_CANCELLED);
    update_post_meta($entry_id, self::META_CANCELLED_AT, time());

    do_action('sd/waitlist/cancelled', $entry_id);
  }

  /**
   * @param array<string,mixed> $payload
   */
  private static function build_title(array $payload) : string {
    $contact = trim((string) ($payload['contact'] ?? ''));
    $pickup  = trim((string) ($payload['pickup_text'] ?? $payload['pickup'] ?? ''));

    if ($contact !== '' && $pickup !== '') {
      return sprintf('Waitlist — %s — %s', $contact, $pickup);
    }

    if ($contact !== '') {
      return sprintf('Waitlist — %s', $contact);
    }

    return 'Waitlist Entry';
  }
}