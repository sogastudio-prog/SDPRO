<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_StripeWebhook (v1)
 *
 * Purpose:
 * - Receive Stripe webhooks and mark attempts authorized (idempotent).
 *
 * REST:
 * - POST /wp-json/sd/v1/stripe-webhook
 *
 * Verification:
 * - Uses SD_STRIPE_WEBHOOK_SECRET (platform constant).
 *
 * Correlation:
 * - Resolve attempt by stored _sd_stripe_session_id on sd_attempt.
 *
 * Emits:
 * - do_action('sd_stripe_authorized', $attempt_id, $quote_id, $ride_id)
 */

final class SD_Module_StripeWebhook {

  private const ROUTE_NS   = 'sd/v1';
  private const ROUTE_PATH = '/stripe-webhook';

  public static function register() : void {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  public static function register_routes() : void {
    register_rest_route(self::ROUTE_NS, self::ROUTE_PATH, [
      'methods'             => 'POST',
      'permission_callback' => '__return_true',
      'callback'            => [__CLASS__, 'handle_webhook'],
    ]);
  }

  public static function handle_webhook(\WP_REST_Request $req) {

    if (!defined('SD_STRIPE_WEBHOOK_SECRET') || !is_string(SD_STRIPE_WEBHOOK_SECRET) || SD_STRIPE_WEBHOOK_SECRET === '') {
      SD_Util::log('stripe_webhook_missing_secret', []);
      return new \WP_REST_Response(['ok' => false, 'error' => 'webhook_not_configured'], 500);
    }

    if (!class_exists('\\Stripe\\Webhook')) {
      SD_Util::log('stripe_webhook_missing_library', []);
      return new \WP_REST_Response(['ok' => false, 'error' => 'stripe_library_missing'], 501);
    }

    $payload   = $req->get_body();
    $sig_header = (string) ($req->get_header('stripe-signature') ?? '');

    if ($payload === '' || $sig_header === '') {
      SD_Util::log('stripe_webhook_missing_payload_or_sig', []);
      return new \WP_REST_Response(['ok' => false, 'error' => 'bad_request'], 400);
    }

    try {
      $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sig_header,
        SD_STRIPE_WEBHOOK_SECRET
      );
    } catch (\Throwable $e) {
      SD_Util::log('stripe_webhook_verify_failed', ['error' => $e->getMessage()]);
      return new \WP_REST_Response(['ok' => false, 'error' => 'signature_invalid'], 400);
    }

    $event_id = (string) ($event->id ?? '');
    $type     = (string) ($event->type ?? '');

    // We key off checkout.session.completed for foundation
    if ($type !== 'checkout.session.completed') {
      return new \WP_REST_Response(['ok' => true, 'ignored' => true, 'type' => $type], 200);
    }

    $obj = $event->data->object ?? null;
    $session_id = is_object($obj) && isset($obj->id) ? (string) $obj->id : '';
    $pi_id      = is_object($obj) && isset($obj->payment_intent) ? (string) $obj->payment_intent : '';

    if ($session_id === '') {
      SD_Util::log('stripe_webhook_missing_session_id', ['event_id' => $event_id]);
      return new \WP_REST_Response(['ok' => false, 'error' => 'session_missing'], 400);
    }

    $attempt_id = self::find_attempt_by_session_id($session_id);
    if ($attempt_id <= 0) {
      SD_Util::log('stripe_webhook_attempt_not_found', ['session_id' => $session_id, 'event_id' => $event_id]);
      // Return 200 to avoid Stripe retry storms; we cannot correlate.
      return new \WP_REST_Response(['ok' => true, 'unmatched' => true], 200);
    }

    // Idempotency: ignore repeated events
    $last = SD_Module_AttemptService::get_last_stripe_event_id($attempt_id);
    if ($event_id !== '' && $last === $event_id) {
      return new \WP_REST_Response(['ok' => true, 'duplicate' => true], 200);
    }

    if ($event_id !== '') {
      SD_Module_AttemptService::set_last_stripe_event_id($attempt_id, $event_id);
    }

    if ($pi_id !== '') {
      SD_Module_AttemptService::attach_stripe_payment_intent($attempt_id, $pi_id);
    }

    SD_Module_AttemptService::set_status($attempt_id, SD_Module_AttemptService::STATUS_AUTHORIZED, [
      'event_id'    => $event_id,
      'stripe_type' => $type,
    ]);

    $quote_id = SD_Module_AttemptService::get_quote_id($attempt_id);
    $ride_id  = SD_Module_AttemptService::get_ride_id($attempt_id);

    SD_Util::log('stripe_webhook_authorized', [
      'attempt_id' => $attempt_id,
      'quote_id'   => $quote_id,
      'ride_id'    => $ride_id,
      'event_id'   => $event_id,
    ]);

    do_action('sd_stripe_authorized', $attempt_id, $quote_id, $ride_id);

    return new \WP_REST_Response(['ok' => true], 200);
  }

  private static function find_attempt_by_session_id(string $session_id) : int {
    $session_id = trim($session_id);
    if ($session_id === '') return 0;

    $q = new \WP_Query([
      'post_type'      => SD_Module_AttemptCPT::CPT,
      'post_status'    => 'publish',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::P_STRIPE_SESSION_ID,
        'value'   => $session_id,
        'compare' => '=',
      ]],
    ]);

    return !empty($q->posts) ? (int) $q->posts[0] : 0;
  }
}