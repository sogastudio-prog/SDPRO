<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_PaymentsCapture (v2.1)
 *
 * Purpose:
 * - Canonical manual-capture service for authorized Stripe PaymentIntents.
 *
 * Canon:
 * - Capture is invoked explicitly by ride completion flow.
 * - Stripe correlation is attempt-first.
 * - Attempt owns Stripe truth and capture audit.
 * - Quote may receive mirrored summary audit, but is not the payment source of truth.
 * - Application fee is applied at capture time using the quote financial snapshot.
 *
 * Notes:
 * - Foundation captures full authorized amount.
 * - Idempotent: if attempt already captured, returns success.
 */

final class SD_Module_PaymentsCapture {

  private const META_QUOTE_AMOUNT_CENTS   = '_sd_quote_amount_cents';
  private const META_QUOTE_CURRENCY       = '_sd_quote_currency';
  private const META_PLATFORM_FEE_CENTS   = '_sd_platform_fee_cents';
  private const META_OPERATOR_NET_CENTS   = '_sd_operator_net_cents';
  private const META_PLATFORM_FEE_PERCENT = '_sd_platform_fee_percent';

  // Safe fallback policy if quote snapshot is missing fee fields
  private const DEFAULT_PLATFORM_FEE_PERCENT       = 15.0;
  private const PLATFORM_MIN_APPLICATION_FEE_CENTS = 200;

  public static function register() : void {
    if (is_admin()) {
      add_action('admin_post_sd_capture_reconcile', [__CLASS__, 'handle_admin_reconcile']);
    }
  }

  /**
   * Capture the best authorized attempt for a ride.
   *
   * Return shape:
   * [
   *   'ok' => bool,
   *   'message' => string,
   *   'ride_id' => int,
   *   'quote_id' => int,
   *   'attempt_id' => int,
   *   'already' => bool,
   * ]
   */
  public static function capture_for_ride(int $ride_id, array $ctx = []) : array {
    if ($ride_id <= 0 || get_post_type($ride_id) !== SD_CPT_Ride::CPT) {
      return [
        'ok'      => false,
        'message' => 'Invalid ride.',
        'ride_id' => $ride_id,
      ];
    }

    $tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0) {
      SD_Util::log('capture_missing_tenant', ['ride_id' => $ride_id]);
      return [
        'ok'      => false,
        'message' => 'Missing tenant.',
        'ride_id' => $ride_id,
      ];
    }

    $quote_id = self::resolve_quote_id_for_ride($ride_id);
    if ($quote_id <= 0) {
      SD_Util::log('capture_missing_quote', ['ride_id' => $ride_id]);
      return [
        'ok'      => false,
        'message' => 'Missing quote.',
        'ride_id' => $ride_id,
      ];
    }

    $attempt_id = self::resolve_capturable_attempt_id($ride_id, $quote_id);
    if ($attempt_id <= 0) {
      SD_Util::log('capture_missing_attempt', [
        'ride_id'  => $ride_id,
        'quote_id' => $quote_id,
      ]);
      return [
        'ok'         => false,
        'message'    => 'No capturable attempt found.',
        'ride_id'    => $ride_id,
        'quote_id'   => $quote_id,
        'attempt_id' => 0,
      ];
    }

    $result = self::capture_for_attempt($attempt_id, $ctx);
    $result['ride_id']  = $ride_id;
    $result['quote_id'] = $quote_id;

    return $result;
  }

  /**
   * Capture a specific authorized attempt.
   */
  public static function capture_for_attempt(int $attempt_id, array $ctx = []) : array {
    if ($attempt_id <= 0 || get_post_type($attempt_id) !== 'sd_attempt') {
      return [
        'ok'         => false,
        'message'    => 'Invalid attempt.',
        'attempt_id' => $attempt_id,
      ];
    }

    if (!defined('SD_STRIPE_SECRET_KEY') || !is_string(SD_STRIPE_SECRET_KEY) || SD_STRIPE_SECRET_KEY === '') {
      SD_Util::log('capture_missing_platform_secret', ['attempt_id' => $attempt_id]);
      return [
        'ok'         => false,
        'message'    => 'Missing platform Stripe secret.',
        'attempt_id' => $attempt_id,
      ];
    }

    if (!class_exists('\\Stripe\\StripeClient')) {
      SD_Util::log('capture_missing_stripe_library', ['attempt_id' => $attempt_id]);
      return [
        'ok'         => false,
        'message'    => 'Stripe library missing.',
        'attempt_id' => $attempt_id,
      ];
    }

    $ride_id  = (int) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_RIDE_ID, true);
    $quote_id = (int) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_QUOTE_ID, true);

    $tenant_id = $ride_id > 0 ? (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true) : 0;
    if ($tenant_id <= 0) {
      return [
        'ok'         => false,
        'message'    => 'Missing tenant.',
        'attempt_id' => $attempt_id,
        'ride_id'    => $ride_id,
        'quote_id'   => $quote_id,
      ];
    }

    $acct = (string) get_post_meta($tenant_id, SD_Meta::STRIPE_ACCOUNT_ID, true);
    if ($acct === '') {
      SD_Util::log('capture_missing_connected_account', [
        'attempt_id' => $attempt_id,
        'ride_id'    => $ride_id,
        'tenant_id'  => $tenant_id,
      ]);
      return [
        'ok'         => false,
        'message'    => 'Missing connected account.',
        'attempt_id' => $attempt_id,
        'ride_id'    => $ride_id,
        'quote_id'   => $quote_id,
      ];
    }

    $captured_at = (int) get_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURED_AT, true);
    if ($captured_at > 0) {
      SD_Util::log('capture_skip_already_captured', [
        'attempt_id' => $attempt_id,
        'ride_id'    => $ride_id,
        'quote_id'   => $quote_id,
      ]);

      return [
        'ok'         => true,
        'message'    => 'Already captured.',
        'attempt_id' => $attempt_id,
        'ride_id'    => $ride_id,
        'quote_id'   => $quote_id,
        'already'    => true,
      ];
    }

    $status = strtoupper(trim((string) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, true)));
    if (!in_array($status, ['AUTHORIZED', 'AUTHORISED', 'CAPTURE_PENDING'], true)) {
      return [
        'ok'         => false,
        'message'    => 'Attempt is not capturable.',
        'attempt_id' => $attempt_id,
        'ride_id'    => $ride_id,
        'quote_id'   => $quote_id,
        'status'     => $status,
      ];
    }

    $pi_id = trim((string) get_post_meta($attempt_id, SD_Meta::P_STRIPE_PAYMENT_INTENT, true));
    if ($pi_id === '') {
      update_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURE_ERROR, 'Missing payment_intent id');
      return [
        'ok'         => false,
        'message'    => 'Missing payment intent.',
        'attempt_id' => $attempt_id,
        'ride_id'    => $ride_id,
        'quote_id'   => $quote_id,
      ];
    }

    $amount_cents = $quote_id > 0 ? (int) get_post_meta($quote_id, self::META_QUOTE_AMOUNT_CENTS, true) : 0;
    $fee_calc = self::resolve_application_fee($quote_id, $amount_cents);
    $platform_fee_cents = (int) $fee_calc['platform_fee_cents'];
    $operator_net_cents = (int) $fee_calc['operator_net_cents'];
    $platform_fee_percent = (float) $fee_calc['platform_fee_percent'];

    try {
      $stripe = new \Stripe\StripeClient(SD_STRIPE_SECRET_KEY);

      $capture_args = [];
      if ($platform_fee_cents > 0) {
        $capture_args['application_fee_amount'] = $platform_fee_cents;
      }

      $pi = $stripe->paymentIntents->capture($pi_id, $capture_args, [
        'stripe_account' => $acct,
      ]);

      $pi_status = isset($pi->status) ? (string) $pi->status : '';

      update_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURED_AT, time());
      delete_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURE_ERROR);
      update_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, 'CAPTURED');
      update_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS_TS, time());

      update_post_meta($attempt_id, '_sd_stripe_pi_status', $pi_status);
      update_post_meta($attempt_id, '_sd_stripe_pi_id', $pi_id);
      update_post_meta($attempt_id, self::META_PLATFORM_FEE_CENTS, $platform_fee_cents);
      update_post_meta($attempt_id, self::META_OPERATOR_NET_CENTS, $operator_net_cents);
      update_post_meta($attempt_id, self::META_PLATFORM_FEE_PERCENT, $platform_fee_percent);

      if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
        update_post_meta($quote_id, SD_Meta::P_STRIPE_CAPTURED_AT, time());
        delete_post_meta($quote_id, SD_Meta::P_STRIPE_CAPTURE_ERROR);
        update_post_meta($quote_id, '_sd_stripe_pi_status', $pi_status);
        update_post_meta($quote_id, '_sd_stripe_pi_id', $pi_id);
        update_post_meta($quote_id, self::META_PLATFORM_FEE_CENTS, $platform_fee_cents);
        update_post_meta($quote_id, self::META_OPERATOR_NET_CENTS, $operator_net_cents);
        update_post_meta($quote_id, self::META_PLATFORM_FEE_PERCENT, $platform_fee_percent);
      }

      SD_Util::log('capture_success', [
        'attempt_id'           => $attempt_id,
        'ride_id'              => $ride_id,
        'quote_id'             => $quote_id,
        'tenant_id'            => $tenant_id,
        'pi_id'                => 'set',
        'status'               => $pi_status,
        'application_fee_cents'=> $platform_fee_cents,
        'operator_net_cents'   => $operator_net_cents,
        'ctx'                  => $ctx,
      ]);

      return [
        'ok'                  => true,
        'message'             => 'Capture succeeded.',
        'attempt_id'          => $attempt_id,
        'ride_id'             => $ride_id,
        'quote_id'            => $quote_id,
        'application_fee_cents'=> $platform_fee_cents,
        'operator_net_cents'  => $operator_net_cents,
      ];

    } catch (\Throwable $e) {
      $msg = wp_strip_all_tags($e->getMessage());

      update_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURE_ERROR, $msg);
      update_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, 'CAPTURE_FAILED');
      update_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS_TS, time());

      if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
        update_post_meta($quote_id, SD_Meta::P_STRIPE_CAPTURE_ERROR, $msg);
      }

      SD_Util::log('capture_exception', [
        'attempt_id'           => $attempt_id,
        'ride_id'              => $ride_id,
        'quote_id'             => $quote_id,
        'tenant_id'            => $tenant_id,
        'application_fee_cents'=> $platform_fee_cents,
        'error'                => $msg,
      ]);

      return [
        'ok'                  => false,
        'message'             => $msg !== '' ? $msg : 'Capture failed.',
        'attempt_id'          => $attempt_id,
        'ride_id'             => $ride_id,
        'quote_id'            => $quote_id,
        'application_fee_cents'=> $platform_fee_cents,
      ];
    }
  }

  // ---------------------------------------------------------------------------
  // Admin reconcile
  // ---------------------------------------------------------------------------

  public static function handle_admin_reconcile() : void {
    if (!current_user_can('manage_options')) {
      wp_die('Access denied.');
    }

    check_admin_referer('sd_capture_reconcile');

    $meta_query = [[
      'key'     => SD_Meta::RIDE_STATE,
      'value'   => SD_Ride_State::COMPLETE,
      'compare' => '=',
    ]];

    $q = new \WP_Query([
      'post_type'      => SD_CPT_Ride::CPT,
      'posts_per_page' => 50,
      'fields'         => 'ids',
      'meta_query'     => $meta_query,
    ]);

    $scanned  = 0;
    $captured = 0;
    $errors   = 0;

    foreach ($q->posts as $ride_id) {
      $scanned++;

      $result = self::capture_for_ride((int) $ride_id, ['source' => 'admin_reconcile']);
      if (!empty($result['ok'])) $captured++;
      else $errors++;
    }

    SD_Util::log('capture_reconcile_done', [
      'scanned'  => $scanned,
      'captured' => $captured,
      'errors'   => $errors,
    ]);

    $url = admin_url('edit.php?post_type=' . SD_CPT_Ride::CPT);
    $url = add_query_arg([
      'sd_capture_reconcile' => '1',
      'scanned'  => $scanned,
      'captured' => $captured,
      'errors'   => $errors,
    ], $url);

    wp_safe_redirect($url, 302);
    exit;
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private static function resolve_quote_id_for_ride(int $ride_id) : int {
    $qid = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($qid > 0 && get_post_type($qid) === 'sd_quote') {
      return $qid;
    }

    $q = new \WP_Query([
      'post_type'      => 'sd_quote',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::RIDE_ID,
        'value'   => (string) $ride_id,
        'compare' => '=',
      ]],
    ]);

    $qid = !empty($q->posts[0]) ? (int) $q->posts[0] : 0;
    if ($qid > 0) {
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, $qid);
    }

    return $qid;
  }

  private static function resolve_capturable_attempt_id(int $ride_id, int $quote_id) : int {
    $q = new \WP_Query([
      'post_type'      => 'sd_attempt',
      'post_status'    => 'any',
      'posts_per_page' => 5,
      'fields'         => 'ids',
      'orderby'        => 'date',
      'order'          => 'DESC',
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'     => SD_Meta::P_ATTEMPT_RIDE_ID,
          'value'   => (string) $ride_id,
          'compare' => '=',
        ],
        [
          'key'     => SD_Meta::P_ATTEMPT_QUOTE_ID,
          'value'   => (string) $quote_id,
          'compare' => '=',
        ],
      ],
    ]);

    foreach ($q->posts as $attempt_id) {
      $attempt_id = (int) $attempt_id;

      $captured_at = (int) get_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURED_AT, true);
      if ($captured_at > 0) {
        return $attempt_id;
      }

      $status = strtoupper(trim((string) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, true)));
      if (in_array($status, ['AUTHORIZED', 'AUTHORISED', 'CAPTURE_PENDING'], true)) {
        return $attempt_id;
      }
    }

    return 0;
  }

  private static function resolve_application_fee(int $quote_id, int $amount_cents) : array {
    $platform_fee_cents = $quote_id > 0
      ? (int) get_post_meta($quote_id, self::META_PLATFORM_FEE_CENTS, true)
      : 0;

    $platform_fee_percent = $quote_id > 0
      ? (float) get_post_meta($quote_id, self::META_PLATFORM_FEE_PERCENT, true)
      : 0.0;

    if ($platform_fee_percent <= 0) {
      $platform_fee_percent = self::DEFAULT_PLATFORM_FEE_PERCENT;
    }

    if ($amount_cents <= 0) {
      return [
        'platform_fee_cents'   => 0,
        'operator_net_cents'   => 0,
        'platform_fee_percent' => $platform_fee_percent,
      ];
    }

    if ($platform_fee_cents <= 0) {
      $platform_fee_cents = (int) round($amount_cents * ($platform_fee_percent / 100));
      $platform_fee_cents = max($platform_fee_cents, self::PLATFORM_MIN_APPLICATION_FEE_CENTS);
    }

    if ($platform_fee_cents > $amount_cents) {
      $platform_fee_cents = $amount_cents;
    }

    $operator_net_cents = max(0, $amount_cents - $platform_fee_cents);

    return [
      'platform_fee_cents'   => $platform_fee_cents,
      'operator_net_cents'   => $operator_net_cents,
      'platform_fee_percent' => $platform_fee_percent,
    ];
  }
}