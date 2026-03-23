<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_StripeReturn (v1)
 *
 * Purpose:
 * - Provide a canonical return endpoint for Stripe Checkout redirects.
 *
 * Contract:
 * - Stripe success/cancel URLs point to:
 *     /stripe-return/?sd_stripe_return=1&attempt=123&result=success
 *     /stripe-return/?sd_stripe_cancel=1&attempt=123&result=cancel
 *
 * - We resolve: attempt → ride → trip token
 * - Then redirect to: /trip/<token>/?pay=success|cancel|error
 *
 * Rules:
 * - Never include trip token in Stripe metadata.
 * - Never reveal Stripe session/payment_intent ids to the user.
 * - Fail-soft, log, redirect safely.
 */
final class SD_Module_StripeReturn {

  private const QV_RETURN = 'sd_stripe_return';
  private const QV_CANCEL = 'sd_stripe_cancel';
  private const QV_ATTEMPT = 'attempt';
  private const QV_RESULT  = 'result';

  public static function register() : void {
    add_action('init', [__CLASS__, 'add_rewrite']);
    add_filter('query_vars', [__CLASS__, 'query_vars']);
    add_action('template_redirect', [__CLASS__, 'template_redirect'], 1);
  }

  public static function add_rewrite() : void {
    // /stripe-return/ → index.php?sd_stripe_return=1 (or cancel via query arg)
    add_rewrite_rule(
      '^stripe-return/?$',
      'index.php?' . self::QV_RETURN . '=1',
      'top'
    );
  }

  public static function query_vars(array $vars) : array {
    $vars[] = self::QV_RETURN;
    $vars[] = self::QV_CANCEL;
    $vars[] = self::QV_ATTEMPT;
    $vars[] = self::QV_RESULT;
    return $vars;
  }

  public static function template_redirect() : void {

    $is_return = (int) get_query_var(self::QV_RETURN);
    $is_cancel = (int) get_query_var(self::QV_CANCEL);

    if (!$is_return && !$is_cancel) return;

    // Ensure no caching for this transient endpoint
    if (!headers_sent()) {
      nocache_headers();
    }

    $attempt_id = absint((string) get_query_var(self::QV_ATTEMPT));
    $result     = sanitize_key((string) get_query_var(self::QV_RESULT));

    if ($result === '') {
      // StripeCheckout sets result, but default safely.
      $result = $is_cancel ? 'cancel' : 'success';
    }

    if ($attempt_id <= 0) {
      SD_Util::log('stripe_return_missing_attempt', ['result' => $result]);
      self::safe_redirect_with_fallback('error', 0);
      exit;
    }

    if (!class_exists('SD_Module_AttemptCPT') || get_post_type($attempt_id) !== SD_Module_AttemptCPT::CPT) {
      SD_Util::log('stripe_return_attempt_invalid', ['attempt_id' => $attempt_id]);
      self::safe_redirect_with_fallback('error', 0);
      exit;
    }

    // Resolve ride id from attempt (preferred)
    $ride_id = class_exists('SD_Module_AttemptService')
      ? SD_Module_AttemptService::get_ride_id($attempt_id)
      : (int) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_RIDE_ID, true);

    if ($ride_id <= 0) {
      SD_Util::log('stripe_return_missing_ride', ['attempt_id' => $attempt_id]);
      self::safe_redirect_with_fallback('error', 0);
      exit;
    }

    $token = (string) get_post_meta($ride_id, SD_Meta::TRIP_TOKEN, true);
    if ($token === '') {
      SD_Util::log('stripe_return_missing_token', ['attempt_id' => $attempt_id, 'ride_id' => $ride_id]);
      self::safe_redirect_with_fallback('error', 0);
      exit;
    }

    // Redirect to trip surface with banner key
    $pay = ($result === 'success') ? 'success' : 'cancel';

    $trip_url = home_url('/trip/' . rawurlencode($token) . '/');
    $trip_url = add_query_arg(['pay' => $pay], $trip_url);
    
    $quote_id = class_exists('SD_Module_AttemptService')
  ? (int) SD_Module_AttemptService::get_quote_id($attempt_id)
  : (int) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_QUOTE_ID, true);

do_action('sd_stripe_authorized', $attempt_id, $quote_id, $ride_id);

    SD_Util::log('stripe_return_redirect_trip', [
      'attempt_id' => $attempt_id,
      'ride_id'    => $ride_id,
      'pay'        => $pay,
    ]);

    wp_safe_redirect($trip_url, 302);
    exit;
  }

  private static function safe_redirect_with_fallback(string $pay, int $ride_id) : void {

    // If we can resolve a token, send them to /trip; otherwise, home with pay=error.
    if ($ride_id > 0) {
      $token = (string) get_post_meta($ride_id, SD_Meta::TRIP_TOKEN, true);
      if ($token !== '') {
        $trip_url = add_query_arg(['pay' => $pay], home_url('/trip/' . rawurlencode($token) . '/'));
        wp_safe_redirect($trip_url, 302);
        return;
      }
    }

    $url = add_query_arg(['pay' => 'error'], home_url('/'));
    wp_safe_redirect($url, 302);
  }
}