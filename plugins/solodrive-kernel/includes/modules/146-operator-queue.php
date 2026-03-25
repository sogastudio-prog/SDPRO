<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorQueue (lead-root refactor)
 *
 * Canon:
 * - sd_lead is the canonical queue root
 * - /trip/<token> resolves to lead
 * - quote / auth attempt / ride are children of lead
 * - ride only exists after successful authorization
 * - operator queue is lead-first, with promoted ride hydrated when present
 *
 * Queue doctrine:
 * - terminal leads / rides never appear
 * - block-conflicted leads never appear
 * - quote work ignores reservation horizon
 * - execution visibility for RESERVED work begins within 24 hours of service start
 * - storefront buffer is NOT used here
 */

if (class_exists('SD_Module_OperatorQueue', false)) {
  return;
}

final class SD_Module_OperatorQueue {

  private const META_LAST_QUOTE_WAITING_PUSH_TS   = '_sd_last_quote_waiting_push_ts';
  private const META_LAST_QUOTE_WAITING_QUOTE_ID  = '_sd_last_quote_waiting_quote_id';
  private const META_LAST_QUOTE_WAITING_STATUS    = '_sd_last_quote_waiting_status';

  private const RESERVATION_QUEUE_HORIZON_SECONDS = DAY_IN_SECONDS; // 24h

  public static function register() : void {
    add_action('wp_ajax_sd_operator_queue_snapshot', [__CLASS__, 'ajax_queue_snapshot']);
  }

  public static function get_queue(int $tenant_id, int $limit = 7) : array {
    return self::build_for_tenant($tenant_id, $limit);
  }

  public static function build_for_tenant(int $tenant_id, int $limit = 7) : array {
    $tenant_id = absint($tenant_id);
    if ($tenant_id <= 0) {
      return [];
    }

    $lead_ids = get_posts([
      'post_type'      => 'sd_lead',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 80,
      'orderby'        => 'modified',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => self::meta_key('TENANT_ID', 'sd_tenant_id'),
          'value'   => $tenant_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    $items = [];

    foreach ($lead_ids as $lead_id) {
      $lead_id   = (int) $lead_id;
      $quote_id  = self::latest_quote_id_for_lead($lead_id);
      $attempt_id = self::latest_attempt_id_for_lead($lead_id);
      $ride_id   = self::latest_ride_id_for_lead($lead_id);

      $quote_status   = $quote_id > 0 ? (string) get_post_meta($quote_id, self::meta_key('QUOTE_STATUS', 'sog_quote_status'), true) : '';
      $attempt_status = $attempt_id > 0 ? (string) get_post_meta($attempt_id, self::attempt_status_meta_key(), true) : '';
      $ride_state     = $ride_id > 0 ? (string) get_post_meta($ride_id, self::meta_key('RIDE_STATE', 'state'), true) : '';

      $service_start_ts = self::resolve_service_start_ts($lead_id, $quote_id, $ride_id);
      $service_end_ts   = self::resolve_service_end_ts($lead_id, $quote_id, $ride_id);
      $pickup_ts        = self::resolve_pickup_ts($lead_id, $quote_id, $ride_id);

      $item = [
        'tenant_id'           => $tenant_id,
        'lead_id'             => $lead_id,
        'quote_id'            => $quote_id,
        'attempt_id'          => $attempt_id,
        'ride_id'             => $ride_id,
        'trip_token'          => (string) get_post_meta($lead_id, self::meta_key('TRIP_TOKEN', 'sog_trip_token'), true),
        'customer_name'       => self::resolve_string($lead_id, $ride_id, ['CUSTOMER_NAME', 'customer_name']),
        'customer_phone'      => self::resolve_string($lead_id, $ride_id, ['CUSTOMER_PHONE', 'customer_phone']),
        'pickup_text'         => self::resolve_string($lead_id, $ride_id, ['PICKUP_TEXT', 'pickup_text']),
        'dropoff_text'        => self::resolve_string($lead_id, $ride_id, ['DROPOFF_TEXT', 'dropoff_text']),
        'pickup_scheduled_ts' => $pickup_ts,
        'lead_status'         => (string) get_post_meta($lead_id, self::meta_key('LEAD_STATUS', 'sog_lead_status'), true),
        'attempt_status'      => $attempt_status,
        'ride_state'          => $ride_state,
        'ride_mode'           => self::resolve_ride_mode($lead_id, $quote_id, $ride_id),
        'service_start_ts'    => $service_start_ts,
        'service_end_ts'      => $service_end_ts,
        'block_conflict'      => (int) self::resolve_meta_int([$lead_id, $ride_id], [
          self::meta_key('P_BLOCK_CONFLICT', '_sd_block_conflict'),
          'sd_block_conflict',
        ]),
        'quote_status'        => $quote_status,
        'quote_status_ts'     => $quote_id > 0 ? (int) self::resolve_meta_int([$quote_id], [
          self::meta_key('P_QUOTE_STATUS_UPDATED_AT', '_sd_quote_status_updated_at'),
          '_sd_quote_status_updated_at',
        ]) : 0,
        'attempt_status_ts'   => $attempt_id > 0 ? (int) self::resolve_meta_int([$attempt_id], [
          self::attempt_status_updated_meta_key(),
          '_sd_attempt_status_updated_at',
        ]) : 0,
        'ride_state_ts'       => $ride_id > 0 ? (int) self::resolve_meta_int([$ride_id], [
          self::meta_key('P_STATE_UPDATED_AT', '_sd_state_updated_at'),
          '_sd_state_updated_at',
        ]) : 0,
        'requested_at'        => (int) get_post_time('U', true, $lead_id),
        'updated_at'          => self::resolve_updated_at($lead_id, $quote_id, $attempt_id, $ride_id),
      ];

      $item['bucket']            = self::map_bucket_for_lead($item);
      $item['priority']          = self::map_priority($item);
      $item['next_action_label'] = self::map_next_action_label($item);

      if ($item['bucket'] === '') {
        continue;
      }

      if ($item['bucket'] === 'quotes_waiting') {
        self::maybe_trigger_quote_waiting_push($lead_id, $quote_id);
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

  public static function resolve_selected_lead_id(array $queue_items) : int {
    $requested = isset($_GET['lead_id']) ? absint(wp_unslash($_GET['lead_id'])) : 0;
    if ($requested > 0) {
      return $requested;
    }

    // Backward-compat: if older surfaces still pass ride_id, map it back to lead.
    $legacy_ride_id = isset($_GET['ride_id']) ? absint(wp_unslash($_GET['ride_id'])) : 0;
    if ($legacy_ride_id > 0) {
      $mapped = self::lead_id_for_ride($legacy_ride_id);
      if ($mapped > 0) {
        return $mapped;
      }
    }

    foreach ($queue_items as $item) {
      if ((string) ($item['bucket'] ?? '') === 'quotes_waiting') {
        return (int) $item['lead_id'];
      }
    }

    if (!empty($queue_items[0]['lead_id'])) {
      return (int) $queue_items[0]['lead_id'];
    }

    return 0;
  }

  public static function display_bucket_label(string $bucket) : string {
    switch ($bucket) {
      case 'quotes_waiting':   return 'Quotes waiting';
      case 'auth_waiting':     return 'Authorization waiting';
      case 'active_ride':      return 'Active ride';
      case 'rides_queued':     return 'Rides queued';
      case 'rides_scheduled':  return 'Scheduled';
      default:                 return 'Queue';
    }
  }

  public static function display_status_summary(array $item) : string {
    $parts = [];

    if (!empty($item['lead_status'])) {
      $parts[] = self::display_state_label((string) $item['lead_status']);
    }
    if (!empty($item['quote_status'])) {
      $parts[] = self::display_state_label((string) $item['quote_status']);
    }
    if (!empty($item['attempt_status'])) {
      $parts[] = self::display_state_label((string) $item['attempt_status']);
    }
    if (!empty($item['ride_state'])) {
      $parts[] = self::display_state_label((string) $item['ride_state']);
    }

    if (empty($parts)) {
      return 'Open';
    }

    return implode(' • ', array_values(array_unique($parts)));
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
      'tenant_id'         => $tenant_id,
      'count'             => count($items),
      'waiting_quotes'    => $waiting_quotes,
      'selected_lead_id'  => self::resolve_selected_lead_id($items),
      'signature'         => self::queue_signature($items),
      'items'             => array_map([__CLASS__, 'snapshot_item'], $items),
      'server_ts'         => time(),
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
      $tenant_id = (int) get_user_meta(get_current_user_id(), self::meta_key('TENANT_ID', 'sd_tenant_id'), true);
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
      'lead_id'            => (int) ($item['lead_id'] ?? 0),
      'quote_id'           => (int) ($item['quote_id'] ?? 0),
      'attempt_id'         => (int) ($item['attempt_id'] ?? 0),
      'ride_id'            => (int) ($item['ride_id'] ?? 0),
      'trip_token'         => (string) ($item['trip_token'] ?? ''),
      'customer_name'      => (string) ($item['customer_name'] ?? ''),
      'pickup_text'        => (string) ($item['pickup_text'] ?? ''),
      'dropoff_text'       => (string) ($item['dropoff_text'] ?? ''),
      'bucket'             => (string) ($item['bucket'] ?? ''),
      'bucket_label'       => self::display_bucket_label((string) ($item['bucket'] ?? '')),
      'status_summary'     => self::display_status_summary($item),
      'next_action_label'  => (string) ($item['next_action_label'] ?? 'Open'),
      'updated_at'         => (int) ($item['updated_at'] ?? 0),
      'requested_at'       => (int) ($item['requested_at'] ?? 0),
    ];
  }

  private static function queue_signature(array $items) : string {
    $parts = [];

    foreach ($items as $item) {
      $parts[] = implode(':', [
        (int) ($item['lead_id'] ?? 0),
        (int) ($item['quote_id'] ?? 0),
        (int) ($item['attempt_id'] ?? 0),
        (int) ($item['ride_id'] ?? 0),
        (string) ($item['bucket'] ?? ''),
        (string) ($item['lead_status'] ?? ''),
        (string) ($item['quote_status'] ?? ''),
        (string) ($item['attempt_status'] ?? ''),
        (string) ($item['ride_state'] ?? ''),
        (int) ($item['updated_at'] ?? 0),
      ]);
    }

    return md5(implode('|', $parts));
  }

  private static function map_bucket_for_lead(array $item) : string {
    $lead_status    = (string) ($item['lead_status'] ?? '');
    $quote_status   = (string) ($item['quote_status'] ?? '');
    $attempt_status = (string) ($item['attempt_status'] ?? '');
    $ride_state     = (string) ($item['ride_state'] ?? '');
    $scheduled_ts   = (int) ($item['pickup_scheduled_ts'] ?? 0);

    // -------------------------------------------------------------------------
    // HARD EXCLUSIONS
    // -------------------------------------------------------------------------
    if (in_array($lead_status, ['LEAD_DECLINED', 'LEAD_EXPIRED', 'LEAD_AUTH_FAILED'], true)) {
      return '';
    }

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
    // QUOTE WORK
    // -------------------------------------------------------------------------
    if (in_array($quote_status, ['PROPOSED', 'APPROVED', 'PRESENTED'], true)) {
      return 'quotes_waiting';
    }

    if ($lead_status === 'LEAD_WAITING_QUOTE') {
      return 'quotes_waiting';
    }

    // -------------------------------------------------------------------------
    // AUTH GATE
    // -------------------------------------------------------------------------
    if ($ride_state === '' && in_array($quote_status, ['LEAD_ACCEPTED', 'PAYMENT_PENDING'], true) && !in_array($attempt_status, ['AUTHORIZED', 'SUCCEEDED'], true)) {
      return 'auth_waiting';
    }

    // -------------------------------------------------------------------------
    // EXECUTION GATING
    // -------------------------------------------------------------------------
    $is_execution_context = (
      $ride_state === 'RIDE_QUEUED' ||
      in_array($ride_state, ['RIDE_DEADHEAD', 'RIDE_ARRIVED', 'RIDE_WAITING', 'RIDE_INPROGRESS'], true)
    );

    if ($is_execution_context && !self::reservation_is_queue_active($item)) {
      return '';
    }

    // -------------------------------------------------------------------------
    // ACTIVE EXECUTION
    // -------------------------------------------------------------------------
    if (in_array($ride_state, ['RIDE_DEADHEAD', 'RIDE_ARRIVED', 'RIDE_WAITING', 'RIDE_INPROGRESS'], true)) {
      return 'active_ride';
    }

    // -------------------------------------------------------------------------
    // SCHEDULED
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
    // QUEUED / PROMOTED
    // -------------------------------------------------------------------------
    if ($ride_state === 'RIDE_QUEUED' || $lead_status === 'LEAD_PROMOTED') {
      return 'rides_queued';
    }

    return '';
  }

  private static function reservation_is_queue_active(array $item) : bool {
    $ride_mode         = (string) ($item['ride_mode'] ?? '');
    $service_start_ts  = (int) ($item['service_start_ts'] ?? 0);

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
      case 'auth_waiting':
        return 25;
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
        return 'Review quote';
      case 'auth_waiting':
        return 'Review authorization';
      case 'active_ride':
        return 'Resume active ride';
      case 'rides_queued':
        return 'Open lead ops';
      case 'rides_scheduled':
        return 'Review scheduled ride';
      default:
        return 'Open';
    }
  }

  private static function maybe_trigger_quote_waiting_push(int $lead_id, int $quote_id) : void {
    if ($lead_id <= 0 || $quote_id <= 0) {
      return;
    }
    if (!class_exists('SD_Module_OperatorNotificationService', false)) {
      return;
    }
    if (!method_exists('SD_Module_OperatorNotificationService', 'send_quote_waiting_notification')) {
      return;
    }

    $current_status = (string) get_post_meta($quote_id, self::meta_key('QUOTE_STATUS', 'sog_quote_status'), true);
    if (!in_array($current_status, ['PROPOSED', 'APPROVED', 'PRESENTED'], true)) {
      return;
    }

    $last_quote_id = (int) get_post_meta($lead_id, self::META_LAST_QUOTE_WAITING_QUOTE_ID, true);
    $last_status   = (string) get_post_meta($lead_id, self::META_LAST_QUOTE_WAITING_STATUS, true);

    $is_new_entry = ($last_quote_id !== $quote_id) || ($last_status !== $current_status);
    if (!$is_new_entry) {
      return;
    }

    // Prefer lead-root notification method if present; fall back to legacy ride-root signature.
    $sent = 0;

    if (method_exists('SD_Module_OperatorNotificationService', 'send_quote_waiting_notification_for_lead')) {
      $sent = (int) SD_Module_OperatorNotificationService::send_quote_waiting_notification_for_lead($lead_id, $quote_id);
    } else {
      $ride_id = self::latest_ride_id_for_lead($lead_id);
      $sent = (int) SD_Module_OperatorNotificationService::send_quote_waiting_notification($ride_id, $quote_id);
    }

    if ($sent > 0) {
      update_post_meta($lead_id, self::META_LAST_QUOTE_WAITING_PUSH_TS, time());
      update_post_meta($lead_id, self::META_LAST_QUOTE_WAITING_QUOTE_ID, $quote_id);
      update_post_meta($lead_id, self::META_LAST_QUOTE_WAITING_STATUS, $current_status);
    }
  }

  private static function latest_quote_id_for_lead(int $lead_id) : int {
    if ($lead_id <= 0) {
      return 0;
    }

    $pointer_keys = [
      'sd_current_quote_id',
      'sd_active_quote_id',
      self::meta_key('QUOTE_ID', 'sd_quote_id'),
    ];

    foreach ($pointer_keys as $key) {
      if (!$key) { continue; }
      $quote_id = (int) get_post_meta($lead_id, $key, true);
      if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
        return $quote_id;
      }
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
          'key'     => self::lead_child_meta_key(),
          'value'   => $lead_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  private static function latest_attempt_id_for_lead(int $lead_id) : int {
    if ($lead_id <= 0) {
      return 0;
    }

    $pointer_keys = [
      'sd_current_attempt_id',
      'sd_active_attempt_id',
    ];

    foreach ($pointer_keys as $key) {
      $attempt_id = (int) get_post_meta($lead_id, $key, true);
      if ($attempt_id > 0 && get_post_type($attempt_id) === 'sd_attempt') {
        return $attempt_id;
      }
    }

    $ids = get_posts([
      'post_type'      => 'sd_attempt',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => self::lead_child_meta_key(),
          'value'   => $lead_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  private static function latest_ride_id_for_lead(int $lead_id) : int {
    if ($lead_id <= 0) {
      return 0;
    }

    $pointer_keys = [
      'sd_promoted_ride_id',
      'sd_active_ride_id',
      self::meta_key('RIDE_ID', 'sd_ride_id'),
    ];

    foreach ($pointer_keys as $key) {
      if (!$key) { continue; }
      $ride_id = (int) get_post_meta($lead_id, $key, true);
      if ($ride_id > 0 && get_post_type($ride_id) === 'sd_ride') {
        return $ride_id;
      }
    }

    $ids = get_posts([
      'post_type'      => 'sd_ride',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => self::lead_child_meta_key(),
          'value'   => $lead_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  private static function lead_id_for_ride(int $ride_id) : int {
    if ($ride_id <= 0) {
      return 0;
    }

    $lead_id = (int) get_post_meta($ride_id, self::lead_child_meta_key(), true);
    if ($lead_id > 0) {
      return $lead_id;
    }

    $token = (string) get_post_meta($ride_id, self::meta_key('TRIP_TOKEN', 'sog_trip_token'), true);
    if ($token === '') {
      return 0;
    }

    $ids = get_posts([
      'post_type'      => 'sd_lead',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => self::meta_key('TRIP_TOKEN', 'sog_trip_token'),
          'value'   => $token,
          'compare' => '=',
        ],
      ],
    ]);

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  private static function resolve_service_start_ts(int $lead_id, int $quote_id, int $ride_id) : int {
    return (int) self::resolve_meta_int([$ride_id, $quote_id, $lead_id], [
      self::meta_key('SERVICE_START_TS', 'sd_service_start_ts'),
      'sd_service_start_ts',
      self::meta_key('PICKUP_SCHEDULED_TS', 'pickup_scheduled_ts'),
      'pickup_scheduled_ts',
    ]);
  }

  private static function resolve_service_end_ts(int $lead_id, int $quote_id, int $ride_id) : int {
    return (int) self::resolve_meta_int([$ride_id, $quote_id, $lead_id], [
      self::meta_key('SERVICE_END_TS', 'sd_service_end_ts'),
      'sd_service_end_ts',
    ]);
  }

  private static function resolve_pickup_ts(int $lead_id, int $quote_id, int $ride_id) : int {
    return (int) self::resolve_meta_int([$ride_id, $quote_id, $lead_id], [
      self::meta_key('PICKUP_SCHEDULED_TS', 'pickup_scheduled_ts'),
      'pickup_scheduled_ts',
      self::meta_key('SERVICE_START_TS', 'sd_service_start_ts'),
      'sd_service_start_ts',
    ]);
  }

  private static function resolve_ride_mode(int $lead_id, int $quote_id, int $ride_id) : string {
    $value = self::resolve_meta_string([$ride_id, $quote_id, $lead_id], [
      self::meta_key('RIDE_MODE', 'ride_mode'),
      'ride_mode',
      'sd_service_type',
    ]);

    return $value !== '' ? $value : 'ONDEMAND';
  }

  private static function resolve_updated_at(int $lead_id, int $quote_id, int $attempt_id, int $ride_id) : int {
    $times = array_filter([
      (int) get_post_modified_time('U', true, $lead_id),
      $quote_id > 0 ? (int) get_post_modified_time('U', true, $quote_id) : 0,
      $attempt_id > 0 ? (int) get_post_modified_time('U', true, $attempt_id) : 0,
      $ride_id > 0 ? (int) get_post_modified_time('U', true, $ride_id) : 0,
    ]);

    if (empty($times)) {
      return time();
    }

    return max($times);
  }

  private static function resolve_string(int $lead_id, int $ride_id, array $keys) : string {
    $resolved_keys = [];
    foreach ($keys as $key) {
      if (defined('SD_Meta::' . $key)) {
        $resolved_keys[] = constant('SD_Meta::' . $key);
      }
      $resolved_keys[] = $key;
    }

    return self::resolve_meta_string([$lead_id, $ride_id], $resolved_keys);
  }

  private static function resolve_meta_int(array $post_ids, array $keys) : int {
    foreach ($post_ids as $post_id) {
      $post_id = (int) $post_id;
      if ($post_id <= 0) { continue; }

      foreach ($keys as $key) {
        if (!$key) { continue; }
        $value = get_post_meta($post_id, $key, true);
        if ($value !== '' && $value !== null) {
          return (int) $value;
        }
      }
    }

    return 0;
  }

  private static function resolve_meta_string(array $post_ids, array $keys) : string {
    foreach ($post_ids as $post_id) {
      $post_id = (int) $post_id;
      if ($post_id <= 0) { continue; }

      foreach ($keys as $key) {
        if (!$key) { continue; }
        $value = get_post_meta($post_id, $key, true);
        if (is_string($value) && $value !== '') {
          return $value;
        }
      }
    }

    return '';
  }

  private static function display_state_label(string $state) : string {
    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'display_state_label')) {
      return (string) SD_Module_OperatorUI::display_state_label($state);
    }

    $state = trim($state);
    if ($state === '') {
      return '';
    }

    return ucwords(strtolower(str_replace(['_', '-'], ' ', $state)));
  }

  private static function meta_key(string $const_name, string $fallback) : string {
    if (class_exists('SD_Meta', false)) {
      $const = 'SD_Meta::' . $const_name;
      if (defined($const)) {
        $value = constant($const);
        if (is_string($value) && $value !== '') {
          return $value;
        }
      }
    }

    return $fallback;
  }

  private static function lead_child_meta_key() : string {
    return 'sd_lead_id';
  }

  private static function attempt_status_meta_key() : string {
    return 'sd_attempt_status';
  }

  private static function attempt_status_updated_meta_key() : string {
    return '_sd_attempt_status_updated_at';
  }
}