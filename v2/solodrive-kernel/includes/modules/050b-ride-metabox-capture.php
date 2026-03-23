<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_Admin_RideCaptureMetabox (v1.1)
 *
 * Purpose:
 * - Read-only admin payment/capture snapshot for a ride.
 * - Provide explicit "Capture now" action for manual admin intervention.
 *
 * Canon:
 * - Read-only by default; explicit button required for action.
 * - Payment truth is attempt-first.
 * - Quote mirrors fee/net for admin convenience.
 */

final class SD_Module_Admin_RideCaptureMetabox {

  private const NONCE_ACTION = 'sd_ride_capture_admin';

  public static function register() : void {
    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
    add_action('admin_post_sd_ride_capture_now', [__CLASS__, 'handle_capture_now']);
  }

  public static function add_metabox() : void {
    add_meta_box(
      'sd_ride_capture_box',
      'Ride Payment / Capture',
      [__CLASS__, 'render_metabox'],
      SD_CPT_Ride::CPT,
      'side',
      'default'
    );
  }

  public static function render_metabox(\WP_Post $post) : void {
    $ride_id = (int) $post->ID;
    if ($ride_id <= 0) {
      echo '<p>Ride not found.</p>';
      return;
    }

    $ride_state = (string) get_post_meta($ride_id, SD_Meta::RIDE_STATE, true);
    $quote_id   = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);

    if ($quote_id <= 0) {
      $quote_id = self::resolve_quote_id_for_ride($ride_id);
    }

    $quote_status       = $quote_id > 0 ? (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true) : '';
    $quote_amount_cents = $quote_id > 0 ? (int) get_post_meta($quote_id, '_sd_quote_amount_cents', true) : 0;
    $quote_currency     = $quote_id > 0 ? (string) get_post_meta($quote_id, '_sd_quote_currency', true) : 'usd';
    $platform_fee_cents = $quote_id > 0 ? (int) get_post_meta($quote_id, '_sd_platform_fee_cents', true) : 0;
    $operator_net_cents = $quote_id > 0 ? (int) get_post_meta($quote_id, '_sd_operator_net_cents', true) : 0;
    $fee_percent        = $quote_id > 0 ? (float) get_post_meta($quote_id, '_sd_platform_fee_percent', true) : 0.0;

    $attempt = self::resolve_latest_attempt_for_ride($ride_id, $quote_id);

    $attempt_id       = (int) ($attempt['attempt_id'] ?? 0);
    $attempt_status   = (string) ($attempt['status'] ?? '');
    $authorized_at    = (int) ($attempt['authorized_at'] ?? 0);
    $captured_at      = (int) ($attempt['captured_at'] ?? 0);
    $capture_error    = (string) ($attempt['capture_error'] ?? '');
    $payment_intent   = (string) ($attempt['payment_intent'] ?? '');
    $pi_status        = (string) ($attempt['pi_status'] ?? '');

    $can_capture = (
      $attempt_id > 0 &&
      $captured_at <= 0 &&
      in_array(strtoupper($attempt_status), ['AUTHORIZED', 'AUTHORISED', 'CAPTURE_PENDING'], true)
    );

    self::render_notice();

    echo '<table class="widefat striped" style="margin-bottom:12px;">';
    echo '<tbody>';
    echo '<tr><th style="width:42%;">Ride state</th><td>' . esc_html(self::display_state($ride_state)) . '</td></tr>';
    echo '<tr><th>Quote</th><td>' . ($quote_id > 0 ? '#' . (int) $quote_id : '—') . '</td></tr>';
    echo '<tr><th>Quote status</th><td>' . esc_html(self::display_state($quote_status)) . '</td></tr>';
    echo '<tr><th>Quote total</th><td>' . esc_html(self::format_money($quote_amount_cents, $quote_currency)) . '</td></tr>';
    echo '<tr><th>Platform fee</th><td>' . esc_html(self::format_money($platform_fee_cents, $quote_currency)) . '</td></tr>';
    echo '<tr><th>Operator net</th><td>' . esc_html(self::format_money($operator_net_cents, $quote_currency)) . '</td></tr>';
    echo '<tr><th>Fee percent</th><td>' . ($fee_percent > 0 ? esc_html(number_format($fee_percent, 2) . '%') : '—') . '</td></tr>';
    echo '<tr><th>Attempt</th><td>' . ($attempt_id > 0 ? '#' . (int) $attempt_id : '—') . '</td></tr>';
    echo '<tr><th>Attempt status</th><td>' . esc_html(self::display_state($attempt_status)) . '</td></tr>';
    echo '<tr><th>Authorized</th><td>' . esc_html(self::format_time($authorized_at)) . '</td></tr>';
    echo '<tr><th>Captured</th><td>' . esc_html(self::format_time($captured_at)) . '</td></tr>';
    echo '<tr><th>PI status</th><td>' . esc_html($pi_status !== '' ? $pi_status : '—') . '</td></tr>';
    echo '<tr><th>PaymentIntent</th><td>' . esc_html($payment_intent !== '' ? self::mask_pi($payment_intent) : '—') . '</td></tr>';
    echo '<tr><th>Capture error</th><td>' . esc_html($capture_error !== '' ? $capture_error : '—') . '</td></tr>';
    echo '</tbody>';
    echo '</table>';

    echo '<div style="display:flex;flex-direction:column;gap:10px;">';

    if ($can_capture) {
      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;">';
      wp_nonce_field(self::NONCE_ACTION, '_wpnonce');
      echo '<input type="hidden" name="action" value="sd_ride_capture_now">';
      echo '<input type="hidden" name="ride_id" value="' . (int) $ride_id . '">';
      submit_button('Capture now', 'primary', '', false);
      echo '</form>';
    } elseif ($captured_at > 0) {
      echo '<p style="margin:0;"><em>Payment already captured.</em></p>';
    } else {
      echo '<p style="margin:0;"><em>No capturable authorized attempt found.</em></p>';
    }

    echo '</div>';
  }

  public static function handle_capture_now() : void {
    if (!current_user_can('edit_posts')) {
      wp_die('Access denied.');
    }

    check_admin_referer(self::NONCE_ACTION);

    $ride_id = isset($_POST['ride_id']) ? absint(wp_unslash($_POST['ride_id'])) : 0;
    if ($ride_id <= 0 || get_post_type($ride_id) !== SD_CPT_Ride::CPT) {
      self::redirect_back(0, 'invalid');
    }

    if (!class_exists('SD_Module_PaymentsCapture') || !method_exists('SD_Module_PaymentsCapture', 'capture_for_ride')) {
      self::redirect_back($ride_id, 'service_missing');
    }

    $result = SD_Module_PaymentsCapture::capture_for_ride($ride_id, [
      'source' => 'admin_metabox_capture_now',
      'user'   => get_current_user_id(),
    ]);

    $ok = !empty($result['ok']);

    if (class_exists('SD_Util')) {
      SD_Util::log('ride_capture_now', [
        'ride_id'               => $ride_id,
        'ok'                    => $ok ? 1 : 0,
        'user_id'               => get_current_user_id(),
        'quote_id'              => (int) ($result['quote_id'] ?? 0),
        'attempt_id'            => (int) ($result['attempt_id'] ?? 0),
        'application_fee_cents' => (int) ($result['application_fee_cents'] ?? 0),
        'operator_net_cents'    => (int) ($result['operator_net_cents'] ?? 0),
        'message'               => (string) ($result['message'] ?? ''),
      ]);
    }

    self::redirect_back($ride_id, $ok ? 'ok' : 'failed');
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private static function resolve_quote_id_for_ride(int $ride_id) : int {
    $quote_id = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
      return $quote_id;
    }

    $q = new \WP_Query([
      'post_type'      => 'sd_quote',
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::RIDE_ID,
        'value'   => (string) $ride_id,
        'compare' => '=',
      ]],
    ]);

    $quote_id = !empty($q->posts[0]) ? (int) $q->posts[0] : 0;
    if ($quote_id > 0) {
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, $quote_id);
    }

    return $quote_id;
  }

  private static function resolve_latest_attempt_for_ride(int $ride_id, int $quote_id = 0) : array {
    $meta_query = [
      [
        'key'     => SD_Meta::P_ATTEMPT_RIDE_ID,
        'value'   => (string) $ride_id,
        'compare' => '=',
      ],
    ];

    if ($quote_id > 0) {
      $meta_query[] = [
        'key'     => SD_Meta::P_ATTEMPT_QUOTE_ID,
        'value'   => (string) $quote_id,
        'compare' => '=',
      ];
    }

    $q = new \WP_Query([
      'post_type'      => 'sd_attempt',
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'orderby'        => 'date',
      'order'          => 'DESC',
      'meta_query'     => $meta_query,
    ]);

    $attempt_id = !empty($q->posts[0]) ? (int) $q->posts[0] : 0;
    if ($attempt_id <= 0) {
      return [];
    }

    return [
      'attempt_id'    => $attempt_id,
      'status'        => (string) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, true),
      'authorized_at' => (int) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_AUTHORIZED_AT, true),
      'captured_at'   => (int) get_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURED_AT, true),
      'capture_error' => (string) get_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURE_ERROR, true),
      'payment_intent'=> (string) get_post_meta($attempt_id, SD_Meta::P_STRIPE_PAYMENT_INTENT, true),
      'pi_status'     => (string) get_post_meta($attempt_id, '_sd_stripe_pi_status', true),
    ];
  }

  private static function render_notice() : void {
    if (!isset($_GET['sd_capture_now'])) {
      return;
    }

    $code = sanitize_key((string) wp_unslash($_GET['sd_capture_now']));

    if ($code === 'ok') {
      echo '<div class="notice notice-success inline"><p>Capture completed.</p></div>';
      return;
    }

    if ($code === 'failed') {
      echo '<div class="notice notice-error inline"><p>Capture failed. Review attempt status and capture error.</p></div>';
      return;
    }

    if ($code === 'service_missing') {
      echo '<div class="notice notice-error inline"><p>Capture service is unavailable.</p></div>';
      return;
    }

    if ($code === 'invalid') {
      echo '<div class="notice notice-error inline"><p>Invalid ride.</p></div>';
    }
  }

  private static function redirect_back(int $ride_id, string $result) : void {
    $url = $ride_id > 0
      ? admin_url('post.php?post=' . $ride_id . '&action=edit')
      : admin_url('edit.php?post_type=' . SD_CPT_Ride::CPT);

    $url = add_query_arg(['sd_capture_now' => sanitize_key($result)], $url);
    wp_safe_redirect($url, 302);
    exit;
  }

  private static function format_time(int $ts) : string {
    if ($ts <= 0) return '—';
    return wp_date('Y-m-d H:i:s', $ts);
  }

  private static function format_money(int $amount_cents, string $currency = 'usd') : string {
    if ($amount_cents <= 0) {
      return '— ' . strtoupper($currency !== '' ? $currency : 'usd');
    }

    return '$' . number_format($amount_cents / 100, 2) . ' ' . strtoupper($currency !== '' ? $currency : 'usd');
  }

  private static function mask_pi(string $pi_id) : string {
    $pi_id = trim($pi_id);
    if ($pi_id === '') return '—';
    if (strlen($pi_id) <= 10) return $pi_id;
    return substr($pi_id, 0, 7) . '…' . substr($pi_id, -4);
  }

  private static function display_state(string $state) : string {
    $state = trim($state);
    if ($state === '') return '—';

    $map = [
      'PROPOSED'        => 'Proposed',
      'APPROVED'        => 'Approved',
      'PRESENTED'       => 'Presented',
      'PAYMENT_PENDING' => 'Authorized',
      'LEAD_ACCEPTED'   => 'Lead accepted',
      'AUTHORIZED'      => 'Authorized',
      'CAPTURE_PENDING' => 'Capture pending',
      'CAPTURED'        => 'Captured',
      'CAPTURE_FAILED'  => 'Capture failed',
      'RIDE_QUEUED'     => 'Queued',
      'RIDE_DEADHEAD'   => 'En route to pickup',
      'RIDE_WAITING'    => 'Waiting at pickup',
      'RIDE_INPROGRESS' => 'Trip in progress',
      'RIDE_ARRIVED'    => 'Arrived at destination',
      'RIDE_COMPLETE'   => 'Completed',
      'RIDE_CANCELLED'  => 'Cancelled',
    ];

    return $map[$state] ?? $state;
  }
}

SD_Module_Admin_RideCaptureMetabox::register();