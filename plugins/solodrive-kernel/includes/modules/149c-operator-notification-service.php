<?php
if (!defined('ABSPATH')) { exit; }

if (class_exists('SD_Module_OperatorNotificationService', false)) { return; }

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class SD_Module_OperatorNotificationService {

  private const META_SUBSCRIPTIONS = 'sd_operator_push_subscriptions';

  public static function send_quote_waiting_notification(int $ride_id, int $quote_id = 0) : int {
    $tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0) return 0;

    $users = get_users([
      'meta_key'   => SD_Meta::TENANT_ID,
      'meta_value' => $tenant_id,
      'fields'     => 'ID',
    ]);

    if (empty($users)) return 0;

    $payload = [
      'type'               => 'quote_waiting',
      'tenant_id'          => $tenant_id,
      'ride_id'            => $ride_id,
      'quote_id'           => $quote_id,
      'title'              => 'Quote needs approval',
      'body'               => 'A new ride request is waiting in trip-ops.',
      'url'                => home_url('/operator/trips/?tab=trip-ops&ride_id=' . $ride_id),
      'tag'                => 'ride-' . $ride_id . '-quote',
      'requireInteraction' => true,
    ];

    $sent = 0;
    foreach ($users as $user_id) {
      $sent += self::send_to_user((int) $user_id, $payload);
    }

    return $sent;
  }

  public static function send_to_user(int $user_id, array $payload) : int {
    $items = get_user_meta($user_id, self::META_SUBSCRIPTIONS, true);
    $items = is_array($items) ? $items : [];
    if (empty($items)) return 0;

    if (!class_exists(WebPush::class) || !class_exists(Subscription::class)) {
      if (class_exists('SD_Util')) {
        SD_Util::log('operator_push_library_missing', [
          'user_id' => $user_id,
        ]);
      }
      return 0;
    }

    $auth = [
      'VAPID' => [
        'subject'    => SD_Module_OperatorPushKeys::subject(),
        'publicKey'  => SD_Module_OperatorPushKeys::public_key(),
        'privateKey' => SD_Module_OperatorPushKeys::private_key(),
      ],
    ];

    if (empty($auth['VAPID']['publicKey']) || empty($auth['VAPID']['privateKey'])) {
      if (class_exists('SD_Util')) {
        SD_Util::log('operator_push_missing_vapid', [
          'user_id' => $user_id,
        ]);
      }
      return 0;
    }

    $webPush = new WebPush($auth);
    $queued = 0;

    foreach ($items as $subscription) {
      $endpoint = (string) ($subscription['endpoint'] ?? '');
      $keys     = (array) ($subscription['keys'] ?? []);

      if ($endpoint === '' || empty($keys['p256dh']) || empty($keys['auth'])) {
        continue;
      }

      try {
        $sub = Subscription::create([
          'endpoint' => $endpoint,
          'publicKey' => (string) $keys['p256dh'],
          'authToken' => (string) $keys['auth'],
        ]);

        $webPush->queueNotification(
          $sub,
          wp_json_encode($payload),
          [
            'TTL'     => 120,
            'urgency' => 'high',
            'topic'   => (string) ($payload['tag'] ?? 'sd-operator'),
          ]
        );

        $queued++;
      } catch (\Throwable $e) {
        if (class_exists('SD_Util')) {
          SD_Util::log('operator_push_queue_failed', [
            'user_id'  => $user_id,
            'endpoint' => $endpoint,
            'error'    => $e->getMessage(),
          ]);
        }
      }
    }

    $sent = 0;
    $invalid = [];

    foreach ($webPush->flush() as $report) {
      $endpoint = $report->getRequest()->getUri()->__toString();

      if ($report->isSuccess()) {
        $sent++;
        continue;
      }

      $invalid[] = $endpoint;

      if (class_exists('SD_Util')) {
        SD_Util::log('operator_push_failed', [
          'user_id'  => $user_id,
          'endpoint' => $endpoint,
          'reason'   => $report->getReason(),
          'expired'  => method_exists($report, 'isSubscriptionExpired') ? $report->isSubscriptionExpired() : null,
        ]);
      }
    }

    if (!empty($invalid)) {
      self::remove_invalid_subscriptions($user_id, $invalid);
    }

    return $sent;
  }

  private static function remove_invalid_subscriptions(int $user_id, array $invalid_endpoints) : void {
    $items = get_user_meta($user_id, self::META_SUBSCRIPTIONS, true);
    $items = is_array($items) ? $items : [];
    if (empty($items)) return;

    $invalid_lookup = array_fill_keys(array_map('strval', $invalid_endpoints), true);

    $filtered = array_values(array_filter($items, static function(array $item) use ($invalid_lookup) : bool {
      $endpoint = (string) ($item['endpoint'] ?? '');
      return !isset($invalid_lookup[$endpoint]);
    }));

    update_user_meta($user_id, self::META_SUBSCRIPTIONS, $filtered);
  }
}