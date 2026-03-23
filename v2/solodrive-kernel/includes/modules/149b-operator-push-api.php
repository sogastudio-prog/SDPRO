<?php
if (!defined('ABSPATH')) { exit; }

if (class_exists('SD_Module_OperatorPushApi', false)) { return; }

final class SD_Module_OperatorPushApi {

  private const META_SUBSCRIPTIONS = 'sd_operator_push_subscriptions';
  private const META_PERMISSION    = 'sd_operator_push_permission';
  private const META_LAST_SEEN     = 'sd_operator_pwa_last_seen';

  public static function register() : void {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  public static function register_routes() : void {
    register_rest_route('sd/v1/operator', '/push-subscribe', [
      'methods'             => 'POST',
      'callback'            => [__CLASS__, 'subscribe'],
      'permission_callback' => [__CLASS__, 'can_use_operator_push'],
    ]);

    register_rest_route('sd/v1/operator', '/push-unsubscribe', [
      'methods'             => 'POST',
      'callback'            => [__CLASS__, 'unsubscribe'],
      'permission_callback' => [__CLASS__, 'can_use_operator_push'],
    ]);

    register_rest_route('sd/v1/operator', '/push-status', [
      'methods'             => 'GET',
      'callback'            => [__CLASS__, 'status'],
      'permission_callback' => [__CLASS__, 'can_use_operator_push'],
    ]);

    register_rest_route('sd/v1/operator', '/push-test', [
      'methods'             => 'POST',
      'callback'            => [__CLASS__, 'test_push'],
      'permission_callback' => [__CLASS__, 'can_use_operator_push'],
    ]);
  }

  public static function can_use_operator_push() : bool {
    return is_user_logged_in();
  }

  public static function subscribe(\WP_REST_Request $request) {
    $user_id   = get_current_user_id();
    $tenant_id = (int) get_user_meta($user_id, SD_Meta::TENANT_ID, true);

    $subscription = $request->get_json_params();
    if (empty($subscription['endpoint'])) {
      return new \WP_Error('sd_missing_endpoint', 'Missing subscription endpoint.', ['status' => 400]);
    }

    $device_id = sanitize_text_field($subscription['device_id'] ?? wp_generate_uuid4());
    $endpoint  = (string) $subscription['endpoint'];
    $keys      = (array) ($subscription['keys'] ?? []);

    if (empty($keys['p256dh']) || empty($keys['auth'])) {
      return new \WP_Error('sd_missing_keys', 'Missing subscription keys.', ['status' => 400]);
    }

    $existing = get_user_meta($user_id, self::META_SUBSCRIPTIONS, true);
    $items = is_array($existing) ? $existing : [];

    $updated = false;
    foreach ($items as &$item) {
      if ((string) ($item['endpoint'] ?? '') === $endpoint) {
        $item = [
          'endpoint'   => $endpoint,
          'keys'       => [
            'p256dh' => (string) $keys['p256dh'],
            'auth'   => (string) $keys['auth'],
          ],
          'tenant_id'  => $tenant_id,
          'device_id'  => $device_id,
          'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
          'created_at' => (int) ($item['created_at'] ?? time()),
          'updated_at' => time(),
        ];
        $updated = true;
        break;
      }
    }
    unset($item);

    if (!$updated) {
      $items[] = [
        'endpoint'   => $endpoint,
        'keys'       => [
          'p256dh' => (string) $keys['p256dh'],
          'auth'   => (string) $keys['auth'],
        ],
        'tenant_id'  => $tenant_id,
        'device_id'  => $device_id,
        'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'created_at' => time(),
        'updated_at' => time(),
      ];
    }

    update_user_meta($user_id, self::META_SUBSCRIPTIONS, $items);
    update_user_meta($user_id, self::META_PERMISSION, 'granted');
    update_user_meta($user_id, self::META_LAST_SEEN, time());

    return [
      'ok'       => true,
      'count'    => count($items),
      'tenant_id'=> $tenant_id,
    ];
  }

  public static function unsubscribe(\WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $endpoint = (string) ($request->get_json_params()['endpoint'] ?? '');

    $existing = get_user_meta($user_id, self::META_SUBSCRIPTIONS, true);
    $items = is_array($existing) ? $existing : [];

    $items = array_values(array_filter($items, static function($item) use ($endpoint) {
      return (string) ($item['endpoint'] ?? '') !== $endpoint;
    }));

    update_user_meta($user_id, self::META_SUBSCRIPTIONS, $items);

    return ['ok' => true, 'count' => count($items)];
  }

  public static function status(\WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $items = get_user_meta($user_id, self::META_SUBSCRIPTIONS, true);
    $items = is_array($items) ? $items : [];

    return [
      'ok'                 => true,
      'subscription_count' => count($items),
      'last_seen'          => (int) get_user_meta($user_id, self::META_LAST_SEEN, true),
      'permission'         => (string) get_user_meta($user_id, self::META_PERMISSION, true),
      'has_vapid'          => class_exists('SD_Module_OperatorPushKeys', false) ? SD_Module_OperatorPushKeys::has_keys() : false,
      'vapid_public_key'   => class_exists('SD_Module_OperatorPushKeys', false) ? SD_Module_OperatorPushKeys::public_key() : '',
    ];
  }

  public static function test_push(\WP_REST_Request $request) {
    $user_id = get_current_user_id();

    $payload = [
      'type'               => 'test',
      'title'              => 'SoloDrive test alert',
      'body'               => 'Operator push is working.',
      'url'                => home_url('/operator/trips/'),
      'tag'                => 'sd-test-alert',
      'requireInteraction' => false,
    ];

    $sent = SD_Module_OperatorNotificationService::send_to_user($user_id, $payload);

    return [
      'ok'   => true,
      'sent' => $sent,
    ];
  }
}