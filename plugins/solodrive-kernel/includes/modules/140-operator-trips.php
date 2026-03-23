<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorQueue (v0.5)
 *
 * Purpose:
 * - Build the tenant-scoped operator queue for Drive Mode
 * - Trigger quote-waiting push once when operator action is required
 * - Provide a lightweight authenticated queue snapshot endpoint for foreground polling
 *
 * Queue doctrine:
 * - Terminal rides never appear
 * - Block-conflicted rides never appear
 * - Quote work ignores reservation horizon
 * - Reserved rides only become ops-visible within 24 hours of sd_service_start_ts
 * - Storefront availability buffer is NOT used here
 */

if (class_exists('SD_Module_OperatorQueue', false)) { return; }

final class SD_Module_OperatorQueue {

  private const META_LAST_QUOTE_WAITING_PUSH_TS   = '_sd_last_quote_waiting_push_ts';
  private const META_LAST_QUOTE_WAITING_QUOTE_ID  = '_sd_last_quote_waiting_quote_id';
  private const META_LAST_QUOTE_WAITING_STATUS    = '_sd_last_quote_waiting_status';

  private const RESERVATION_QUEUE_HORIZON_SECONDS = DAY_IN_SECONDS; // 24 hours

  public static function register() : void {
    add_action('wp_ajax_sd_operator_queue_snapshot', [__CLASS__, 'ajax_queue_snapshot']);
  }

  public static function get_queue(int $tenant_id, int $limit = 7) : array {
    return self::build_for_tenant($tenant_id, $limit);
  }

  public static function build_for_tenant(int $tenant_id, int $limit = 7) : array {
    $tenant_id = absint($tenant_id);
    if ($tenant_id <= 0) return [];

    $ride_ids = get_posts([
      'post_type'      => 'sd_ride',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 60,
      'orderby'        => 'modified',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => SD_Meta::TENANT_ID,
          'value'   => $tenant_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    $items = [];

    foreach ($ride_ids as $ride_id) {
      $ride_id  = (int) $ride_id;
      $quote_id = self::latest_quote_id_for_ride($ride_id);

      $item = [
        'tenant_id'           => $tenant_id,
        'ride_id'             => $ride_id,
        'quote_id'            => $quote_id,
        'trip_token'          => (string) get_post_meta($ride_id, SD_Meta::TRIP_TOKEN, true),
        'customer_name'       => (string) get_post_meta($ride_id, SD_Meta::CUSTOMER_NAME, true),
        'customer_phone'      => (string) get_post_meta($ride_id, SD_Meta::CUSTOMER_PHONE, true),
        'pickup_text'         => (string) get_post_meta($ride_id, SD_Meta::PICKUP_TEXT, true),
        'dropoff_text'        => (string) get_post_meta($ride_id, SD_Meta::DROPOFF_TEXT, true),
        'pickup_scheduled_ts' => (int) get_post_meta($ride_id, SD_Meta::PICKUP_SCHEDULED_TS, true),
        'lead_status'         => (string) get_post_meta($ride_id, SD_Meta::LEAD_STATUS, true),
        'ride_state'          => (string) get_post_meta($ride_id, SD_Meta::RIDE_STATE, true),
        'ride_mode'           => (string) get_post_meta($ride_id, SD_Meta::RIDE_MODE, true),
        'service_start_ts'    => (int) get_post_meta($ride_id, SD_Meta::SERVICE_START_TS, true),
        'service_end_ts'      => (int) get_post_meta($ride_id, SD_Meta::SERVICE_END_TS, true),
        'block_conflict'      => (int) get_post_meta($ride_id, SD_Meta::P_BLOCK_CONFLICT, true),
        'quote_status'        => $quote_id > 0 ? (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true) : '',
        'quote_status_ts'     => $quote_id > 0 ? (int) get_post_meta($quote_id, SD_Meta::P_QUOTE_STATUS_UPDATED_AT, true) : 0,
        'ride_state_ts'       => (int) get_post_meta($ride_id, SD_Meta::P_STATE_UPDATED_AT, true),
        'requested_at'        => (int) get_post_time('U', true, $ride_id),
        'updated_at'          => (int) get_post_modified_time('U', true, $ride_id),
      ];

      $item['bucket']            = self::map_bucket_for_ride($item);
      $item['priority']          = self::map_priority($item);
      $item['next_action_label'] = self::map_next_action_label($item);

      if ($item['bucket'] === '') {
        continue;
      }

      if ($item['bucket'] === 'quotes_waiting') {
        self::maybe_trigger_quote_waiting_push($ride_id, $quote_id);
      }

      $items[] = $item;
    }

    usort($items, static function(array $a, array $b) : int {
      if ((int) $a['priority'] !== (int) $b['priority']) {
        return ((int) $a['priority'] < (int) $b['priority']) ? -1 : 1;
      }
      return ((int) $b['updated_at'] <=> (int) $a['updated_at']);
    });

    return array_slice($items, 0, max(1, $limit));
  }

  public static function waiting_quotes_count(int $tenant_id) : int {
    $items = self::build_for_tenant($tenant_id, 50);
    $count = 0;

    foreach ($items as $item) {
      if ((string) ($item['bucket'] ?? '') === 'quotes_waiting') {
        $count++;
      }
    }

    return $count;
  }

  public static function resolve_selected_ride_id(array $queue_items) : int {
    $requested = isset($_GET['ride_id']) ? absint(wp_unslash($_GET['ride_id'])) : 0;
    if ($requested > 0) {
      return $requested;
    }

    foreach ($queue_items as $item) {
      if ((string) ($item['bucket'] ?? '') === 'quotes_waiting') {
        return (int) $item['ride_id'];
      }
    }

    if (!empty($queue_items[0]['ride_id'])) {
      return (int) $queue_items[0]['ride_id'];
    }

    return 0;
  }

  public static function display_bucket_label(string $bucket) : string {
    switch ($bucket) {
      case 'quotes_waiting':  return 'Quotes waiting';
      case 'active_ride':     return 'Active ride';
      case 'rides_queued':    return 'Rides queued';
      case 'rides_scheduled': return 'Scheduled';
      default:                return 'Queue';
    }
  }

  public static function display_status_summary(array $item) : string {
    $parts = [];

    if (!empty($item['quote_status'])) {
      $parts[] = class_exists('SD_Module_OperatorUI', false)
        ? SD_Module_OperatorUI::display_state_label((string) $item['quote_status'])
        : (string) $item['quote_status'];
    }

    if (!empty($item['ride_state'])) {
      $parts[] = class_exists('SD_Module_OperatorUI', false)
        ? SD_Module_OperatorUI::display_state_label((string) $item['ride_state'])
        : (string) $item['ride_state'];
    }

    if (empty($parts)) {
      return 'Open';
    }

    return implode(' • ', $parts);
  }

  public static function snapshot_for_tenant(int $tenant_id, int $limit = 7) : array {
    $items = self::get_queue($tenant_id, $limit);

    $waiting_quotes = 0;
    foreach ($items as $item) {
      if ((string) ($item['bucket'] ?? '') === 'quotes_waiting') {
        $waiting_quotes++;
      }
    }

    return [
      'tenant_id'        => $tenant_id,
      'count'            => count($items),
      'waiting_quotes'   => $waiting_quotes,
      'selected_ride_id' => self::resolve_selected_ride_id($items),
      'signature'        => self::queue_signature($items),
      'items'            => array_map([__CLASS__, 'snapshot_item'], $items),
      'server_ts'        => time(),
    ];
  }

  public static function ajax_queue_snapshot() : void {
    if (!is_user_logged_in()) {
      wp_send_json_error(['message' => 'Authentication required.'], 401);
    }

    check_ajax_referer('sd_operator_queue', 'nonce');

    $tenant_id = 0;
    if (class_exists('SD_TenantAccess', false) && method_exists('SD_TenantAccess', 'current_user_tenant_id')) {
      $tenant_id = (int) SD_TenantAccess::current_user_tenant_id();
    }

    if ($tenant_id <= 0) {
      $tenant_id = (int) get_user_meta(get_current_user_id(), SD_Meta::TENANT_ID, true);
    }

    if ($tenant_id <= 0) {
      wp_send_json_error(['message' => 'Tenant context missing.'], 403);
    }

    $limit = isset($_POST['limit']) ? absint(wp_unslash($_POST['limit'])) : 7;
    $limit = max(1, min(20, $limit));

    wp_send_json_success(self::snapshot_for_tenant($tenant_id, $limit));
  }

  private static function snapshot_item(array $item) : array {
    return [
      'ride_id'           => (int) ($item['ride_id'] ?? 0),
      'quote_id'          => (int) ($item['quote_id'] ?? 0),
      'customer_name'     => (string) ($item['customer_name'] ?? ''),
      'pickup_text'       => (string) ($item['pickup_text'] ?? ''),
      'dropoff_text'      => (string) ($item['dropoff_text'] ?? ''),
      'bucket'            => (string) ($item['bucket'] ?? ''),
      'bucket_label'      => self::display_bucket_label((string) ($item['bucket'] ?? '')),
      'status_summary'    => self::display_status_summary($item),
      'next_action_label' => (string) ($item['next_action_label'] ?? 'Open'),
      'updated_at'        => (int) ($item['updated_at'] ?? 0),
      'requested_at'      => (int) ($item['requested_at'] ?? 0),
    ];
  }

  private static function queue_signature(array $items) : string {
    $parts = [];

    foreach ($items as $item) {
      $parts[] = implode(':', [
        (int) ($item['ride_id'] ?? 0),
        (int) ($item['quote_id'] ?? 0),
        (string) ($item['bucket'] ?? ''),
        (string) ($item['quote_status'] ?? ''),
        (string) ($item['ride_state'] ?? ''),
        (int) ($item['updated_at'] ?? 0),
      ]);
    }

    return md5(implode('|', $parts));
  }

  private static function map_bucket_for_ride(array $item) : string {
    $ride_state   = (string) ($item['ride_state'] ?? '');
    $quote_status = (string) ($item['quote_status'] ?? '');
    $scheduled_ts = (int) ($item['pickup_scheduled_ts'] ?? 0);

    // -------------------------------------------------------------------------
    // HARD EXCLUSIONS (never show in queue)
    // -------------------------------------------------------------------------
    if (in_array($ride_state, ['RIDE_COMPLETE', 'RIDE_CANCELLED'], true)) {
      return '';
    }

    if (in_array($quote_status, ['CANCELLED', 'EXPIRED', 'USER_REJECTED', 'LEAD_REJECTED', 'SUPERSEDED'], true)) {
      return '';
    }

    if (!empty($item['block_conflict'])) {
      return '';
    }

    // -------------------------------------------------------------------------
    // Revenue-first: quote work ALWAYS visible (ignore reservation horizon)
    // -------------------------------------------------------------------------
    if (in_array($quote_status, ['PROPOSED', 'APPROVED', 'PRESENTED'], true)) {
      return 'quotes_waiting';
    }

    // -------------------------------------------------------------------------
    // Execution gating: reserved rides only enter ops queue within 24h
    // -------------------------------------------------------------------------
    $is_execution_context = (
      $ride_state === 'RIDE_QUEUED' ||
      $quote_status === 'PAYMENT_PENDING' ||
      in_array($ride_state, ['RIDE_DEADHEAD', 'RIDE_ARRIVED', 'RIDE_WAITING', 'RIDE_INPROGRESS'], true)
    );

    if ($is_execution_context && !self::reservation_is_queue_active($item)) {
      return '';
    }

    // -------------------------------------------------------------------------
    // Active execution
    // -------------------------------------------------------------------------
    if (in_array($ride_state, ['RIDE_DEADHEAD', 'RIDE_ARRIVED', 'RIDE_WAITING', 'RIDE_INPROGRESS'], true)) {
      return 'active_ride';
    }

    // -------------------------------------------------------------------------
    // Scheduled (future within window)
    // -------------------------------------------------------------------------
    if ($scheduled_ts > 0) {
      $now = time();
      if ($scheduled_ts >= $now && $scheduled_ts <= ($now + 4 * HOUR_IN_SECONDS)) {
        return 'rides_scheduled';
      }
    }

    if (!empty($item['service_start_ts'])) {
      $now = time();
      $service_start_ts = (int) $item['service_start_ts'];
      if ($service_start_ts >= $now && $service_start_ts <= ($now + 4 * HOUR_IN_SECONDS)) {
        return 'rides_scheduled';
      }
    }

    // -------------------------------------------------------------------------
    // Queued / authorized
    // -------------------------------------------------------------------------
    if ($ride_state === 'RIDE_QUEUED' || $quote_status === 'PAYMENT_PENDING') {
      return 'rides_queued';
    }

    return '';
  }

  private static function reservation_is_queue_active(array $item) : bool {
    $ride_mode        = (string) ($item['ride_mode'] ?? '');
    $service_start_ts = (int) ($item['service_start_ts'] ?? 0);

    if ($ride_mode !== 'RESERVED') {
      return true;
    }

    if ($service_start_ts <= 0) {
      return false;
    }

    return time() >= ($service_start_ts - self::RESERVATION_QUEUE_HORIZON_SECONDS);
  }

  private static function map_priority(array $item) : int {
    switch ((string) ($item['bucket'] ?? '')) {
      case 'active_ride':
        return 10;
      case 'quotes_waiting':
        return 20;
      case 'rides_queued':
        return 30;
      case 'rides_scheduled':
        return 40;
      default:
        return 999;
    }
  }

  private static function map_next_action_label(array $item) : string {
    switch ((string) ($item['bucket'] ?? '')) {
      case 'quotes_waiting':
        return 'Approve quote';
      case 'active_ride':
        return 'Resume active ride';
      case 'rides_queued':
        return 'Open trip-ops';
      case 'rides_scheduled':
        return 'Review scheduled ride';
      default:
        return 'Open';
    }
  }

  private static function maybe_trigger_quote_waiting_push(int $ride_id, int $quote_id) : void {
    if ($ride_id <= 0 || $quote_id <= 0) return;
    if (!class_exists('SD_Module_OperatorNotificationService', false)) return;
    if (!method_exists('SD_Module_OperatorNotificationService', 'send_quote_waiting_notification')) return;

    $current_status = (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true);
    if (!in_array($current_status, ['PROPOSED', 'APPROVED'], true)) {
      return;
    }

    $last_quote_id = (int) get_post_meta($ride_id, self::META_LAST_QUOTE_WAITING_QUOTE_ID, true);
    $last_status   = (string) get_post_meta($ride_id, self::META_LAST_QUOTE_WAITING_STATUS, true);

    $is_new_entry = ($last_quote_id !== $quote_id) || ($last_status !== $current_status);

    if (!$is_new_entry) {
      return;
    }

    $sent = (int) SD_Module_OperatorNotificationService::send_quote_waiting_notification($ride_id, $quote_id);

    if ($sent > 0) {
      update_post_meta($ride_id, self::META_LAST_QUOTE_WAITING_PUSH_TS, time());
      update_post_meta($ride_id, self::META_LAST_QUOTE_WAITING_QUOTE_ID, $quote_id);
      update_post_meta($ride_id, self::META_LAST_QUOTE_WAITING_STATUS, $current_status);
    }
  }

  private static function latest_quote_id_for_ride(int $ride_id) : int {
    $quote_id = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
      return $quote_id;
    }

    $ids = get_posts([
      'post_type'      => 'sd_quote',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => SD_Meta::RIDE_ID,
          'value'   => $ride_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    $quote_id = !empty($ids[0]) ? (int) $ids[0] : 0;

    if ($quote_id > 0) {
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, $quote_id);
    }

    return $quote_id;
  }
}