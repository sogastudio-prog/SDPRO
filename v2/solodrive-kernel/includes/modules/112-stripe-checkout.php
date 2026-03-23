<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_StripeCheckout (v1.1)
 *
 * Purpose:
 * - Create a Stripe Checkout Session for a given attempt (attempt-first).
 *
 * REST:
 * - POST /wp-json/sd/v1/checkout
 *
 * Inputs (one of):
 * - attempt_id
 * - trip_token (will resolve ride + latest quote + create attempt)
 * - quote_id (will create attempt)
 *
 * Output:
 * - { ok: true, checkout_url: "https://checkout.stripe.com/..." , attempt_id: 123 }
 *
 * Canon:
 * - Amount/currency are read from canonical quote snapshot JSON first.
 * - Ride/quote linkage is resolved from kernel ride meta, not legacy ad hoc fields.
 */

final class SD_Module_StripeCheckout {

  private const ROUTE_NS   = 'sd/v1';
  private const ROUTE_PATH = '/checkout';

  public static function register() : void {
    add_action('rest_api_init', [__CLASS__, 'register_routes']);
  }

  public static function register_routes() : void {
    register_rest_route(self::ROUTE_NS, self::ROUTE_PATH, [
      'methods'             => 'POST',
      'permission_callback' => '__return_true',
      'callback'            => [__CLASS__, 'handle_create_checkout'],
    ]);
  }

  public static function handle_create_checkout(\WP_REST_Request $req) {

    if (!defined('SD_STRIPE_SECRET_KEY') || !is_string(SD_STRIPE_SECRET_KEY) || SD_STRIPE_SECRET_KEY === '') {
      if (class_exists('SD_Util')) {
        SD_Util::log('stripe_checkout_missing_platform_secret', []);
      }
      return new \WP_REST_Response(['ok' => false, 'error' => 'stripe_not_configured'], 500);
    }

    if (!class_exists('\\Stripe\\StripeClient')) {
      if (class_exists('SD_Util')) {
        SD_Util::log('stripe_checkout_missing_library', []);
      }
      return new \WP_REST_Response(['ok' => false, 'error' => 'stripe_library_missing'], 501);
    }

    $attempt_id = absint((string) $req->get_param('attempt_id'));
    $quote_id   = absint((string) $req->get_param('quote_id'));
    $trip_token = trim((string) $req->get_param('trip_token'));

    // -----------------------------------------------------------------------
    // Resolve or create attempt
    // -----------------------------------------------------------------------
    if ($attempt_id <= 0) {
      if ($quote_id > 0) {
      if (class_exists('SD_Util')) {
  SD_Util::log('stripe_checkout_attempt_create_from_quote', [
    'quote_id' => $quote_id,
  ]);
}
        if ($attempt_id <= 0) {
  if ($quote_id > 0) {
    if (class_exists('SD_Module_AttemptService') && method_exists('SD_Module_AttemptService', 'create_for_quote')) {
      $attempt_id = (int) SD_Module_AttemptService::create_for_quote($quote_id, 'checkout');
    }
  } elseif ($trip_token !== '') {
    $attempt_id = self::create_attempt_from_trip_token($trip_token);
  }
}
      } elseif ($trip_token !== '') {
        $attempt_id = self::create_attempt_from_trip_token($trip_token);
      }
    }

    if ($attempt_id <= 0) {
      if (class_exists('SD_Util')) {
        SD_Util::log('stripe_checkout_attempt_missing', [
          'trip_token_present' => ($trip_token !== ''),
          'quote_id'           => $quote_id,
        ]);
      }
      return new \WP_REST_Response(['ok' => false, 'error' => 'attempt_not_found'], 404);
    }

    if (!class_exists('SD_Module_AttemptCPT') || get_post_type($attempt_id) !== SD_Module_AttemptCPT::CPT) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'attempt_invalid'], 400);
    }

    $tenant_id = (int) SD_Module_AttemptService::get_tenant_id($attempt_id);
    if ($tenant_id <= 0) {
      if (class_exists('SD_Util')) {
        SD_Util::log('stripe_checkout_attempt_missing_tenant', ['attempt_id' => $attempt_id]);
      }
      return new \WP_REST_Response(['ok' => false, 'error' => 'tenant_missing'], 400);
    }

    $current_tenant_id = class_exists('SD_Module_TenantResolver')
      ? (int) SD_Module_TenantResolver::current_tenant_id()
      : 0;

// -----------------------------------------------------------------------
// Tenant safety
// -----------------------------------------------------------------------
// Public /trip/<token> checkout may execute without resolver tenant context.
// In that case, trust the attempt tenant, but verify ride/quote are scoped
// to the same tenant so we never cross-bleed records.

$current_tenant_id = class_exists('SD_Module_TenantResolver')
  ? (int) SD_Module_TenantResolver::current_tenant_id()
  : 0;

$attempt_quote_id = (int) SD_Module_AttemptService::get_quote_id($attempt_id);
$attempt_ride_id  = (int) SD_Module_AttemptService::get_ride_id($attempt_id);

$quote_tenant_id = $attempt_quote_id > 0
  ? (int) get_post_meta($attempt_quote_id, SD_Meta::TENANT_ID, true)
  : 0;

$ride_tenant_id = $attempt_ride_id > 0
  ? (int) get_post_meta($attempt_ride_id, SD_Meta::TENANT_ID, true)
  : 0;

// If resolver tenant exists, it must match.
if ($current_tenant_id > 0 && $current_tenant_id !== $tenant_id) {
  if (class_exists('SD_Util')) {
    SD_Util::log('stripe_checkout_tenant_mismatch', [
      'attempt_id'        => $attempt_id,
      'attempt_tenant_id' => $tenant_id,
      'current_tenant_id' => $current_tenant_id,
      'quote_tenant_id'   => $quote_tenant_id,
      'ride_tenant_id'    => $ride_tenant_id,
    ]);
  }
  return new \WP_REST_Response(['ok' => false, 'error' => 'tenant_mismatch'], 403);
}

// Attempt, quote, and ride must all agree on tenant.
if ($quote_tenant_id > 0 && $quote_tenant_id !== $tenant_id) {
  if (class_exists('SD_Util')) {
    SD_Util::log('stripe_checkout_quote_tenant_mismatch', [
      'attempt_id'        => $attempt_id,
      'attempt_tenant_id' => $tenant_id,
      'quote_id'          => $attempt_quote_id,
      'quote_tenant_id'   => $quote_tenant_id,
    ]);
  }
  return new \WP_REST_Response(['ok' => false, 'error' => 'tenant_mismatch'], 403);
}

if ($ride_tenant_id > 0 && $ride_tenant_id !== $tenant_id) {
  if (class_exists('SD_Util')) {
    SD_Util::log('stripe_checkout_ride_tenant_mismatch', [
      'attempt_id'        => $attempt_id,
      'attempt_tenant_id' => $tenant_id,
      'ride_id'           => $attempt_ride_id,
      'ride_tenant_id'    => $ride_tenant_id,
    ]);
  }
  return new \WP_REST_Response(['ok' => false, 'error' => 'tenant_mismatch'], 403);
}

    $acct = (string) get_post_meta($tenant_id, SD_Meta::STRIPE_ACCOUNT_ID, true);
    if ($acct === '') {
      if (class_exists('SD_Util')) {
        SD_Util::log('stripe_checkout_missing_connected_account', [
          'tenant_id'  => $tenant_id,
          'attempt_id' => $attempt_id,
        ]);
      }
      return new \WP_REST_Response(['ok' => false, 'error' => 'tenant_stripe_not_configured'], 400);
    }

    $quote_id = (int) SD_Module_AttemptService::get_quote_id($attempt_id);
    $ride_id  = (int) SD_Module_AttemptService::get_ride_id($attempt_id);

    if ($quote_id <= 0) {
      return new \WP_REST_Response(['ok' => false, 'error' => 'quote_missing'], 400);
    }

    // -----------------------------------------------------------------------
    // Canonical quote snapshot -> amount/currency
    // -----------------------------------------------------------------------
    $draft = self::quote_draft($quote_id);

    $amount_cents = (int) ($draft['quote']['total_cents'] ?? 0);
    $currency     = strtolower((string) ($draft['quote']['currency'] ?? 'usd'));
    if ($currency === '') {
      $currency = 'usd';
    }

    if ($amount_cents <= 0) {
      if (class_exists('SD_Util')) {
        SD_Util::log('stripe_checkout_quote_missing_amount', [
          'quote_id'      => $quote_id,
          'attempt_id'    => $attempt_id,
          'draft_present' => !empty($draft),
        ]);
      }
      return new \WP_REST_Response(['ok' => false, 'error' => 'quote_missing_amount'], 400);
    }

    $success_url = add_query_arg([
      'sd_stripe_return' => '1',
      'attempt'          => (string) $attempt_id,
      'result'           => 'success',
    ], home_url('/stripe-return/'));

    $cancel_url = add_query_arg([
      'sd_stripe_cancel' => '1',
      'attempt'          => (string) $attempt_id,
      'result'           => 'cancel',
    ], home_url('/stripe-return/'));

    try {
      $stripe = new \Stripe\StripeClient(SD_STRIPE_SECRET_KEY);

      $session = $stripe->checkout->sessions->create([
        'mode'        => 'payment',
        'success_url' => $success_url,
        'cancel_url'  => $cancel_url,
        'line_items'  => [[
          'quantity'   => 1,
          'price_data' => [
            'currency'    => $currency,
            'unit_amount' => $amount_cents,
            'product_data' => [
              'name' => 'Ride Authorization',
            ],
          ],
        ]],
        'payment_intent_data' => [
          'capture_method' => 'manual',
          'metadata'       => [
            'sd_attempt_id' => (string) $attempt_id,
            'sd_quote_id'   => (string) $quote_id,
            'sd_ride_id'    => (string) $ride_id,
          ],
        ],
      ], [
        'stripe_account' => $acct,
      ]);

      if (!isset($session->id) || !isset($session->url)) {
        if (class_exists('SD_Util')) {
          SD_Util::log('stripe_checkout_session_missing_fields', ['attempt_id' => $attempt_id]);
        }
        SD_Module_AttemptService::set_error($attempt_id, 'Checkout session missing id/url');
        SD_Module_AttemptService::set_status($attempt_id, SD_Module_AttemptService::STATUS_FAILED, ['where' => 'create_session']);
        return new \WP_REST_Response(['ok' => false, 'error' => 'stripe_session_failed'], 502);
      }

      SD_Module_AttemptService::attach_stripe_session($attempt_id, (string) $session->id);
      SD_Module_AttemptService::set_status($attempt_id, SD_Module_AttemptService::STATUS_CREATED, ['where' => 'create_session']);

      if (class_exists('SD_Util')) {
        SD_Util::log('stripe_checkout_created', [
          'attempt_id' => $attempt_id,
          'quote_id'   => $quote_id,
          'ride_id'    => $ride_id,
          'tenant_id'  => $tenant_id,
          'amount'     => $amount_cents,
          'currency'   => $currency,
        ]);
      }

      return new \WP_REST_Response([
        'ok'           => true,
        'attempt_id'   => $attempt_id,
        'checkout_url' => (string) $session->url,
      ], 200);

    } catch (\Throwable $e) {
      if (class_exists('SD_Util')) {
        SD_Util::log('stripe_checkout_exception', [
          'attempt_id' => $attempt_id,
          'error'      => $e->getMessage(),
        ]);
      }
      SD_Module_AttemptService::set_error($attempt_id, $e->getMessage(), ['where' => 'create_session']);
      SD_Module_AttemptService::set_status($attempt_id, SD_Module_AttemptService::STATUS_FAILED, ['where' => 'exception']);
      return new \WP_REST_Response(['ok' => false, 'error' => 'stripe_exception'], 502);
    }
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private static function quote_draft(int $quote_id) : array {
    if ($quote_id <= 0) {
      return [];
    }

    $raw = (string) get_post_meta($quote_id, SD_Meta::P_QUOTE_DRAFT_JSON, true);
    if ($raw === '') {
      return [];
    }

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
  }

  private static function create_attempt_from_trip_token(string $token) : int {
    $token = trim($token);
    if ($token === '') {
      return 0;
    }

    $tenant_id = class_exists('SD_Module_TenantResolver')
      ? (int) SD_Module_TenantResolver::current_tenant_id()
      : 0;

    if ($tenant_id <= 0) {
      return 0;
    }

    $ride = new \WP_Query([
      'post_type'      => SD_Module_RideCPT::CPT,
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'     => SD_Meta::TRIP_TOKEN,
          'value'   => $token,
          'compare' => '=',
        ],
        [
          'key'     => SD_Meta::TENANT_ID,
          'value'   => (string) $tenant_id,
          'compare' => '=',
        ],
      ],
    ]);

    if (empty($ride->posts)) {
      return 0;
    }

    $ride_id = (int) $ride->posts[0];

    // Canon ride -> quote linkage
    $quote_id = 0;

    if (defined('SD_Meta::QUOTE_ID')) {
      $quote_id = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    }

    if ($quote_id <= 0) {
      $quote_id = (int) get_post_meta($ride_id, '_sd_latest_quote_id', true);
    }

    if ($quote_id <= 0) {
      return 0;
    }

    return (int) SD_Module_AttemptService::create_for_quote(
      $quote_id,
      'checkout_trip_token',
      ['ride_id' => $ride_id]
    );
  }
}

SD_Module_StripeCheckout::register();