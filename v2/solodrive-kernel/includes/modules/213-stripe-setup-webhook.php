<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_StripeSetupWebhook {

  public static function register() : void {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  public static function register_routes() : void {
    register_rest_route('sd/v1/stripe', '/setup-webhook', [
      'methods'             => 'POST',
      'callback'            => [__CLASS__, 'handle'],
      'permission_callback' => '__return_true',
    ]);
  }

  public static function handle(\WP_REST_Request $request) {
    $payload    = $request->get_body();
    $sig_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? (string) $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
    $secret     = defined('SD_STRIPE_SETUP_WEBHOOK_SECRET') ? SD_STRIPE_SETUP_WEBHOOK_SECRET : '';

    if (!class_exists('\\Stripe\\Webhook')) {
      self::log('stripe_setup_webhook_missing_library', []);
      return new \WP_REST_Response(['ok' => false, 'error' => 'stripe_library_missing'], 501);
    }

    try {
      if ($secret !== '') {
        $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $secret);
      } else {
        $event = json_decode($payload);
      }
    } catch (\Throwable $e) {
      self::log('stripe_setup_webhook_invalid', ['error' => $e->getMessage()]);
      return new \WP_REST_Response(['ok' => false], 400);
    }

    $type = (string) ($event->type ?? '');
    if ($type !== 'setup_intent.succeeded') {
      return new \WP_REST_Response(['ok' => true, 'ignored' => true], 200);
    }

    $intent       = $event->data->object ?? null;
    $intent_id    = (string) ($intent->id ?? '');
    $customer_id  = (string) ($intent->customer ?? '');
    $payment_method_id = (string) ($intent->payment_method ?? '');
    $ride_id      = (int) (($intent->metadata->sd_ride_id ?? 0));

    if ($ride_id <= 0) {
      self::log('stripe_setup_webhook_missing_ride', ['setup_intent_id' => $intent_id]);
      return new \WP_REST_Response(['ok' => true], 200);
    }

    update_post_meta($ride_id, 'sd_setup_intent_id', $intent_id);
    update_post_meta($ride_id, 'sd_stripe_customer_id', $customer_id);
    update_post_meta($ride_id, 'sd_payment_method_id', $payment_method_id);

    self::log('stripe_setup_intent_succeeded', [
      'ride_id'           => $ride_id,
      'setup_intent_id'   => $intent_id,
      'customer_id'       => $customer_id,
      'payment_method_id' => $payment_method_id,
    ]);

    return new \WP_REST_Response(['ok' => true], 200);
  }

  private static function log(string $event, array $ctx = []) : void {
    if (class_exists('SD_Util') && method_exists('SD_Util', 'log')) {
      SD_Util::log($event, $ctx);
      return;
    }
    error_log('[solodrive] ' . wp_json_encode([
      'sd'    => true,
      'event' => $event,
      'ts'    => gmdate('c'),
      'ctx'   => $ctx,
    ]));
  }
}

SD_Module_StripeSetupWebhook::register();
