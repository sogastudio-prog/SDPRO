<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_Admin_CaptureAuditTimeline
 *
 * Purpose:
 * - Read-only admin metabox on sd_ride
 * - Show capture-relevant audit timeline in one place
 *
 * Canon:
 * - Ride is the operational record / admin viewing surface
 * - Attempt is the payment source of truth
 * - Quote carries the financial snapshot
 * - This panel is READ-ONLY
 */

final class SD_Module_Admin_CaptureAuditTimeline {

  public static function register() : void {
    if (!is_admin()) return;
    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
  }

  public static function add_metabox() : void {
    add_meta_box(
      'sd_capture_audit_timeline',
      'Capture Audit Timeline',
      [__CLASS__, 'render_metabox'],
      SD_CPT_Ride::CPT,
      'normal',
      'default'
    );
  }

  public static function render_metabox(\WP_Post $post) : void {
    $ride_id = (int) $post->ID;
    if ($ride_id <= 0 || get_post_type($ride_id) !== SD_CPT_Ride::CPT) {
      echo '<p>Invalid ride.</p>';
      return;
    }

    $quote_id   = self::resolve_quote_id($ride_id);
    $attempt_id = self::resolve_attempt_id($ride_id, $quote_id);

    $ride_state = (string) get_post_meta($ride_id, SD_Meta::RIDE_STATE, true);
    $state_ts   = (int) get_post_meta($ride_id, SD_Meta::P_STATE_UPDATED_AT, true);

    $attempt_status   = $attempt_id > 0 ? (string) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, true) : '';
    $authorized_at    = $attempt_id > 0 ? (int) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_AUTHORIZED_AT, true) : 0;
    $captured_at      = $attempt_id > 0 ? (int) get_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURED_AT, true) : 0;
    $capture_error    = $attempt_id > 0 ? (string) get_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURE_ERROR, true) : '';
    $payment_intent   = $attempt_id > 0 ? (string) get_post_meta($attempt_id, SD_Meta::P_STRIPE_PAYMENT_INTENT, true) : '';

    $amount_cents         = $quote_id > 0 ? (int) get_post_meta($quote_id, '_sd_quote_amount_cents', true) : 0;
    $currency             = $quote_id > 0 ? (string) get_post_meta($quote_id, '_sd_quote_currency', true) : 'usd';
    $platform_fee_cents   = $quote_id > 0 ? (int) get_post_meta($quote_id, '_sd_platform_fee_cents', true) : 0;
    $operator_net_cents   = $quote_id > 0 ? (int) get_post_meta($quote_id, '_sd_operator_net_cents', true) : 0;
    $platform_fee_percent = $quote_id > 0 ? (float) get_post_meta($quote_id, '_sd_platform_fee_percent', true) : 0.0;

    $capture_attempted_at = (int) get_post_meta($ride_id, '_sd_capture_attempted_at', true);
    $capture_last_result  = (string) get_post_meta($ride_id, '_sd_capture_last_result', true);

    echo '<div class="sd-capture-audit">';

    echo '<p class="description" style="margin-bottom:12px;">Read-only timeline of payment authorization and capture for this ride.</p>';

    echo '<table class="widefat striped" style="max-width:100%;">';
    echo '<tbody>';

    self::row('Ride ID', (string) $ride_id);
    self::row('Quote ID', $quote_id > 0 ? ('#' . $quote_id) : '—');
    self::row('Attempt ID', $attempt_id > 0 ? ('#' . $attempt_id) : '—');
    self::row('Ride state', self::label_or_dash($ride_state));
    self::row('Attempt status', self::label_or_dash($attempt_status));
    self::row('PaymentIntent', $payment_intent !== '' ? esc_html($payment_intent) : '—');

    echo '</tbody>';
    echo '</table>';

    echo '<h4 style="margin:16px 0 8px;">Timeline</h4>';
    echo '<table class="widefat striped" style="max-width:100%;">';
    echo '<tbody>';

    self::row('Authorized at', self::format_ts($authorized_at));
    self::row('Capture attempted at', self::format_ts($capture_attempted_at));
    self::row('Capture result', self::capture_result_label($captured_at, $capture_error, $attempt_status, $capture_last_result));
    self::row('Captured at', self::format_ts($captured_at));

    echo '</tbody>';
    echo '</table>';

    echo '<h4 style="margin:16px 0 8px;">Financial Snapshot</h4>';
    echo '<table class="widefat striped" style="max-width:100%;">';
    echo '<tbody>';

    self::row('Amount', self::format_money($amount_cents, $currency));
    self::row('Application fee', self::format_money($platform_fee_cents, $currency));
    self::row('Operator net', self::format_money($operator_net_cents, $currency));
    self::row('Platform fee %', $platform_fee_percent > 0 ? rtrim(rtrim(number_format($platform_fee_percent, 2), '0'), '.') . '%' : '—');

    echo '</tbody>';
    echo '</table>';

    if ($capture_error !== '') {
      echo '<div style="margin-top:12px;padding:10px 12px;border:1px solid #fecaca;background:#fef2f2;border-radius:6px;">';
      echo '<strong>Capture error:</strong> ' . esc_html($capture_error);
      echo '</div>';
    }

    echo '</div>';
  }

  private static function resolve_quote_id(int $ride_id) : int {
    $quote_id = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
      return $quote_id;
    }

    $quote_id = (int) get_post_meta($ride_id, '_sd_latest_quote_id', true);
    if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, (string) $quote_id);
      return $quote_id;
    }

    $ids = get_posts([
      'post_type'      => 'sd_quote',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'ID',
      'order'          => 'ASC',
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::RIDE_ID,
        'value'   => $ride_id,
        'compare' => '=',
        'type'    => 'NUMERIC',
      ]],
    ]);

    $quote_id = !empty($ids[0]) ? (int) $ids[0] : 0;
    if ($quote_id > 0) {
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, (string) $quote_id);
      update_post_meta($ride_id, '_sd_latest_quote_id', (string) $quote_id);
    }

    return $quote_id;
  }

  private static function resolve_attempt_id(int $ride_id, int $quote_id) : int {
    $attempt_id = (int) get_post_meta($ride_id, '_sd_authorized_attempt_id', true);
    if ($attempt_id > 0 && get_post_type($attempt_id) === 'sd_attempt') {
      return $attempt_id;
    }

    $meta_query = [
      [
        'key'     => SD_Meta::P_ATTEMPT_RIDE_ID,
        'value'   => $ride_id,
        'compare' => '=',
        'type'    => 'NUMERIC',
      ],
    ];

    if ($quote_id > 0) {
      $meta_query[] = [
        'key'     => SD_Meta::P_ATTEMPT_QUOTE_ID,
        'value'   => $quote_id,
        'compare' => '=',
        'type'    => 'NUMERIC',
      ];
    }

    $ids = get_posts([
      'post_type'      => 'sd_attempt',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => $meta_query,
    ]);

    $attempt_id = !empty($ids[0]) ? (int) $ids[0] : 0;

    if ($attempt_id > 0) {
      update_post_meta($ride_id, '_sd_authorized_attempt_id', (string) $attempt_id);
    }

    return $attempt_id;
  }

  private static function infer_capture_attempted_at(
    int $attempt_id,
    int $captured_at,
    string $capture_error,
    int $state_ts,
    string $ride_state
  ) : int {
    if ($captured_at > 0) return $captured_at;
    if ($capture_error !== '') return $state_ts;
    if ($attempt_id > 0 && $ride_state === SD_Ride_State::COMPLETE) return $state_ts;
    return 0;
  }

  private static function capture_result_label(int $captured_at, string $capture_error, string $attempt_status, string $capture_last_result = '') : string {
    if ($capture_last_result !== '') {
      return esc_html($capture_last_result);
    }

    if ($captured_at > 0) return 'Captured';
    if ($capture_error !== '') return 'Failed';
    if (in_array(strtoupper(trim($attempt_status)), ['AUTHORIZED', 'AUTHORISED', 'CAPTURE_PENDING'], true)) {
      return 'Pending / not yet completed';
    }
    return '—';
  }

  private static function row(string $label, string $value) : void {
    echo '<tr>';
    echo '<th style="width:220px;text-align:left;">' . esc_html($label) . '</th>';
    echo '<td>' . $value . '</td>';
    echo '</tr>';
  }

  private static function format_ts(int $ts) : string {
    if ($ts <= 0) return '—';
    return esc_html(wp_date('M j, Y g:i:s a', $ts)) . ' <span style="opacity:.7;">(' . esc_html(human_time_diff($ts, time()) . ' ago') . ')</span>';
  }

  private static function format_money(int $amount_cents, string $currency = 'usd') : string {
    $currency = $currency !== '' ? strtoupper($currency) : 'USD';
    if ($amount_cents <= 0) {
      return '— ' . esc_html($currency);
    }
    return esc_html('$' . number_format($amount_cents / 100, 2) . ' ' . $currency);
  }

  private static function label_or_dash(string $value) : string {
    $value = trim($value);
    return $value !== '' ? esc_html($value) : '—';
  }
}