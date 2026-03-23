<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_TripSurface (v0.7)
 *
 * Purpose:
 * - Public /trip/<token> surface.
 * - Reads ride + linked quote + latest attempt.
 * - Renders PRESENTED quotes with rider CTA: "Authorize Payment".
 * - Renders a premium rider-facing trip tracker for all later states.
 * - Exposes a public payload for lightweight polling via REST.
 *
 * UX direction:
 * - Reduce rider-facing redundancy on mobile.
 * - Use the header card as the primary summary surface.
 * - Drop quote id from public rider UI.
 * - Use dropoff as the trip title.
 * - Show quote card above trip status when the rider needs to act.
 * - Surface pickup ETA in the header during en-route / arrived phases.
 * - Use a lifecycle-driven green update card for payment secured / pickup updates.
 */

if (class_exists('SD_Module_TripSurface', false)) { return; }

final class SD_Module_TripSurface {

  private const QV_TRIP_TOKEN = 'sd_trip_token';
  private const NONCE_ACTION  = 'sd_trip_begin_checkout';

  public static function register() : void {
    add_action('init', [__CLASS__, 'register_rewrites']);
    add_filter('query_vars', [__CLASS__, 'query_vars']);
    add_action('template_redirect', [__CLASS__, 'template_redirect']);

    add_action('admin_post_nopriv_sd_trip_begin_checkout', [__CLASS__, 'handle_begin_checkout']);
    add_action('admin_post_sd_trip_begin_checkout',        [__CLASS__, 'handle_begin_checkout']);
  }

  public static function register_rewrites() : void {
    add_rewrite_rule('^trip/([^/]+)/?$', 'index.php?' . self::QV_TRIP_TOKEN . '=$matches[1]', 'top');
  }

  public static function query_vars($vars) {
    $vars[] = self::QV_TRIP_TOKEN;
    return $vars;
  }

  public static function template_redirect() : void {
    $token = trim((string) get_query_var(self::QV_TRIP_TOKEN));

    if ($token === '') {
      $token = self::token_from_request_path();
    }

    if ($token === '') return;

    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow', true);

    self::render_trip_surface($token);
    exit;
  }

  // ---------------------------------------------------------------------------
  // Route render
  // ---------------------------------------------------------------------------

  private static function render_trip_surface(string $token) : void {
    status_header(200);

    $lead_id = self::lead_id_from_token($token);
    if ($lead_id <= 0) {
      self::render_shell('Trip', self::styles() . self::render_notice_card('Trip not found.'));
      return;
    }

    $lead     = self::read_lead_context($lead_id, $token);
    $ride_id  = (int) ($lead['promoted_ride_id'] ?? 0);

    if ($ride_id <= 0) {
      self::render_lead_surface($lead);
      return;
    }

    $ride     = self::read_ride_context($ride_id, $token);
    $quote_id = self::resolve_quote_id_for_ride($ride_id);
    $quote    = self::read_quote_context($quote_id, $ride_id);
    $attempt  = self::read_attempt_context($ride_id, $quote_id);
    $exec     = class_exists('SD_Module_OperatorExecutionIntel')
      ? SD_Module_OperatorExecutionIntel::read_for_ride($ride_id)
      : [];
    $display  = self::build_display_model($ride, $quote, $attempt, $exec);

    $pickup  = self::short_city_address((string) ($ride['pickup_text'] ?? ''));
    $dropoff = self::short_city_address((string) ($ride['dropoff_text'] ?? ''));
    $state_body = self::render_state_body($ride, $quote, $attempt, $display);
    $show_quote_first = ((string) ($quote['status'] ?? '') === 'PRESENTED');
    $live_update_card = self::render_live_update_card($ride, $quote, $display, $exec);

    $html  = self::styles();
    $html .= '<div class="sd-trip-wrap">';

    $html .= '<div class="sd-trip-card sd-trip-hero">';
    $html .= '<div class="sd-trip-hero-top">';
    $html .=   '<div class="sd-trip-hero-route">';
    $html .=     '<span id="sd-trip-route-sub" class="sd-trip-route-sub">' . esc_html($pickup !== '' ? ($pickup . ' →') : '') . '</span>';
    $html .=   '</div>';
    $html .=   '<div id="sd-trip-hero-ride" class="sd-trip-hero-ride">Ride #' . (int) $ride_id . '</div>';
    $html .= '</div>';

    $html .= '<div id="sd-trip-hero-destination" class="sd-trip-hero-destination">';
    $html .= esc_html($dropoff !== '' ? $dropoff : 'Your Trip');
    $html .= '</div>';

    if (!empty($display['hero_eta_line'])) {
      $html .= '<div id="sd-trip-hero-eta" class="sd-trip-hero-eta">' . esc_html((string) $display['hero_eta_line']) . '</div>';
    } else {
      $html .= '<div id="sd-trip-hero-eta" class="sd-trip-hero-eta" style="display:none"></div>';
    }

    $html .= '<div id="sd-trip-headline" class="sd-trip-status-headline">' . esc_html((string) $display['headline']) . '</div>';
    $html .= '<div id="sd-trip-subheadline" class="sd-trip-sub sd-trip-sub-strong">' . esc_html((string) $display['subheadline']) . '</div>';
    $html .= '</div>';

if (isset($_GET['pay'])) {
  $pay = sanitize_key((string) wp_unslash($_GET['pay']));
  if ($pay === 'cancel') {
    $html .= self::render_banner('Payment was cancelled. Your quote is still available if it has not expired.', 'warn');
  } elseif ($pay === 'conflict') {
    $html .= self::render_banner('This trip can no longer be confirmed because it conflicts with an existing scheduled commitment.', 'error');
  } elseif ($pay === 'error') {
    $html .= self::render_banner('Unable to start checkout right now. Please try again.', 'error');
  }
}

    $html .= '<div id="sd-trip-live-update">' . $live_update_card . '</div>';
    $html .= '<div id="sd-trip-live-map-wrap">' . self::render_live_map_card($ride, $exec) . '</div>';
    $html .= '<div id="sd-trip-debug-wrap">' . self::render_live_map_debug_card() . '</div>';

    if ($show_quote_first && $state_body !== '') {
      $html .= '<div id="sd-trip-state-body">';
      $html .= $state_body;
      $html .= '</div>';
    } else {
      $html .= '<div id="sd-trip-state-body"></div>';
    }

    $html .= self::render_timeline_card(
      $display['timeline'],
      (string) ($display['current_step'] ?? '')
    );

    if (!$show_quote_first && $state_body !== '') {
      $html .= '<div id="sd-trip-state-body-secondary">';
      $html .= $state_body;
      $html .= '</div>';
    } else {
      $html .= '<div id="sd-trip-state-body-secondary"></div>';
    }

    $html .= self::polling_js((string) $ride['token']);
    $html .= '</div>';

    self::render_shell('Trip', $html);
  }

  private static function render_state_body(array $ride, array $quote, array $attempt, array $display) : string {
  $quote_status = (string) ($quote['status'] ?? '');
  $ride_state   = (string) ($ride['ride_state'] ?? '');
  $ride_id      = (int) ($ride['ride_id'] ?? 0);

  if ($quote_status === 'PRESENTED') {
    if (self::ride_has_block_conflict($ride_id)) {
      return self::render_notice_card('This trip can no longer be confirmed because it conflicts with an existing scheduled commitment.');
    }
    return self::render_presented_quote($ride, $quote);
  }

  if ($quote_status === 'USER_REJECTED') {
    return self::render_notice_card('This quote was declined.');
  }

  if ($quote_status === 'USER_TIMEOUT') {
    return self::render_notice_card('This quote timed out.');
  }

  if (in_array($quote_status, ['EXPIRED', 'CANCELLED', 'SUPERSEDED', 'LEAD_REJECTED'], true)) {
    return self::render_notice_card('This quote is no longer available.');
  }

  if ($ride_state === 'RIDE_CANCELLED') {
    return self::render_notice_card('This trip was cancelled.');
  }

  return '';
}

  private static function render_presented_quote(array $ride, array $quote) : string {
    $amount_cents   = (int) ($quote['total_cents'] ?? 0);
    $currency       = (string) ($quote['currency'] ?? 'usd');
    $pickup_eta_min = (int) ($quote['pickup_eta_min'] ?? 0);

    $html  = '<div class="sd-trip-card sd-trip-card-alert">';
    $html .= '  <div class="sd-trip-quote-box">';
    $html .= '    <div class="sd-trip-quote-line"><strong>Total:</strong> ' . esc_html(self::format_money($amount_cents, $currency)) . '</div>';
    if ($pickup_eta_min > 0) {
      $html .= '    <div class="sd-trip-quote-line"><strong>Pickup ETA:</strong> ' . esc_html((string) $pickup_eta_min) . ' min</div>';
    }
    $html .= '  </div>';

    $html .= '  <div class="sd-trip-reassure">Authorize payment to confirm this trip. Payment will be securely authorized now and captured when the trip is complete.</div>';

    $html .= '  <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="sd-trip-form">';
    $html .=        wp_nonce_field(self::NONCE_ACTION, '_wpnonce', true, false);
    $html .= '    <input type="hidden" name="action" value="sd_trip_begin_checkout">';
    $html .= '    <input type="hidden" name="ride_id" value="' . (int) $ride['ride_id'] . '">';
    $html .= '    <input type="hidden" name="quote_id" value="' . (int) $quote['quote_id'] . '">';
    $html .= '    <input type="hidden" name="trip_token" value="' . esc_attr((string) $ride['token']) . '">';
    $html .= '    <button type="submit" class="sd-trip-btn sd-trip-btn-primary">Authorize Payment</button>';
    $html .= '  </form>';
    $html .= '</div>';

    return $html;
  }

  private static function render_live_update_card(array $ride, array $quote, array $display, array $exec = []) : string {
    $ride_state      = (string) ($ride['ride_state'] ?? '');
    $quote_status    = (string) ($quote['status'] ?? '');
    $eta_pickup_min  = (int) ($exec['eta_to_pickup_min'] ?? 0);
    $dist_pickup_m   = (int) ($exec['distance_to_pickup_m'] ?? 0);
    $eta_dropoff_min = (int) ($exec['eta_to_dropoff_min'] ?? 0);
    $dist_dropoff_m  = (int) ($exec['distance_to_dropoff_m'] ?? 0);

    if ($ride_state === 'RIDE_DEADHEAD') {
      $html  = '<div class="sd-trip-banner is-ok sd-trip-banner-metrics">';
      $html .= '  <div class="sd-trip-banner-metrics-title">Pickup updates</div>';
      $html .= '  <div class="sd-trip-banner-metrics-row">';
      $html .= '    <span><strong>ETA:</strong> ' . esc_html($eta_pickup_min > 0 ? ($eta_pickup_min . ' min') : '—') . '</span>';
      $html .= '    <span><strong>Distance:</strong> ' . esc_html($dist_pickup_m > 0 ? (number_format($dist_pickup_m) . ' m') : '—') . '</span>';
      $html .= '  </div>';
      $html .= '</div>';
      return $html;
    }

    if ($quote_status === 'PAYMENT_PENDING' && $ride_state === 'RIDE_QUEUED') {
      return '<div class="sd-trip-banner is-ok">Keep this page open for updates.</div>';
    }

    if ($ride_state === 'RIDE_WAITING') {
      return
        '<div class="sd-trip-banner is-ok sd-trip-banner-metrics">' .
          '<div class="sd-trip-banner-metrics-title">Driver has arrived</div>' .
          '<div class="sd-trip-banner-metrics-row">' .
            '<span><strong>Timer:</strong> <span id="sd-trip-arrival-timer">00:00</span></span>' .
          '</div>' .
        '</div>';
    }

    if ($ride_state === 'RIDE_INPROGRESS') {
      $html  = '<div class="sd-trip-banner is-ok sd-trip-banner-metrics">';
      $html .= '  <div class="sd-trip-banner-metrics-title">Trip metrics</div>';
      $html .= '  <div class="sd-trip-banner-metrics-row">';
      $html .= '    <span><strong>ETA:</strong> ' . esc_html($eta_dropoff_min > 0 ? ($eta_dropoff_min . ' min') : '—') . '</span>';
      $html .= '    <span><strong>Distance:</strong> ' . esc_html($dist_dropoff_m > 0 ? (number_format($dist_dropoff_m) . ' m') : '—') . '</span>';
      $html .= '  </div>';
      $html .= '</div>';
      return $html;
    }

    return '';
  }

  private static function render_timeline_card(array $timeline, string $current_step = '') : string {
    if ($current_step === 'complete') {
      return
        '<div class="sd-trip-card" id="sd-trip-status-card">' .
          '<div class="sd-trip-card-head">' .
            '<h2>Trip Status</h2>' .
            '<div class="sd-trip-sub">Trip completed.</div>' .
          '</div>' .
        '</div>';
    }

    if ($current_step === 'cancelled') {
      return
        '<div class="sd-trip-card" id="sd-trip-status-card">' .
          '<div class="sd-trip-card-head">' .
            '<h2>Trip Status</h2>' .
            '<div class="sd-trip-sub">Trip cancelled.</div>' .
          '</div>' .
        '</div>';
    }

    $html  = '<div class="sd-trip-card" id="sd-trip-status-card">';
    $html .= '  <div class="sd-trip-card-head">';
    $html .= '    <h2>Trip Status</h2>';
    $html .= '    <div class="sd-trip-sub">We will keep this page updated as your trip progresses.</div>';
    $html .= '  </div>';
    $html .= '  <div id="sd-trip-timeline" class="sd-trip-timeline">';

    foreach ($timeline as $step) {
      $classes = 'sd-trip-step';
      if (!empty($step['state']) && $step['state'] === 'complete') $classes .= ' is-complete';
      if (!empty($step['state']) && $step['state'] === 'current')  $classes .= ' is-current';

      $html .= '    <div class="' . esc_attr($classes) . '">';
      $html .= '      <div class="sd-trip-step-dot"></div>';
      $html .= '      <div class="sd-trip-step-copy">';
      $html .= '        <div class="sd-trip-step-label">' . esc_html((string) ($step['label'] ?? '')) . '</div>';
      if (!empty($step['note'])) {
        $html .= '        <div class="sd-trip-step-note">' . esc_html((string) ($step['note'] ?? '')) . '</div>';
      }
      $html .= '      </div>';
      $html .= '    </div>';
    }

    $html .= '  </div>';
    $html .= '</div>';

    return $html;
  }

  // ---------------------------------------------------------------------------
  // Checkout launcher
  // ---------------------------------------------------------------------------

  public static function handle_begin_checkout() : void {
    $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_POST['_wpnonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_die('Invalid request.');
    }

    $ride_id  = isset($_POST['ride_id']) ? absint(wp_unslash($_POST['ride_id'])) : 0;
    $quote_id = isset($_POST['quote_id']) ? absint(wp_unslash($_POST['quote_id'])) : 0;
    $token    = isset($_POST['trip_token']) ? sanitize_text_field((string) wp_unslash($_POST['trip_token'])) : '';

    if ($ride_id <= 0 || $quote_id <= 0 || $token === '') {
      self::redirect_trip_with_flag($token, 'error');
    }

    $resolved_ride_id = self::ride_id_from_token($token);
    if ($resolved_ride_id <= 0 || $resolved_ride_id !== $ride_id) {
      self::redirect_trip_with_flag($token, 'error');
    }

    if ((int) get_post_meta($quote_id, SD_Meta::RIDE_ID, true) !== $ride_id) {
  self::redirect_trip_with_flag($token, 'error');
}

if (self::ride_has_block_conflict($ride_id)) {
  if (class_exists('SD_Util')) {
    SD_Util::log('trip_surface_checkout_block_conflict', [
      'ride_id'  => $ride_id,
      'quote_id' => $quote_id,
      'token'    => $token,
    ]);
  }
  self::redirect_trip_with_flag($token, 'conflict');
}

$quote_status = (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true);
if ($quote_status !== 'PRESENTED') {
  self::redirect_trip_with_flag($token, 'error');
}

    $endpoint = rest_url('sd/v1/checkout');

    $resp = wp_remote_post($endpoint, [
      'timeout' => 20,
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode([
        'quote_id'   => $quote_id,
        'trip_token' => $token,
      ]),
    ]);

    if (is_wp_error($resp)) {
      if (class_exists('SD_Util')) {
        SD_Util::log('trip_surface_checkout_http_wp_error', [
          'ride_id'   => $ride_id,
          'quote_id'  => $quote_id,
          'token'     => $token,
          'error'     => $resp->get_error_message(),
        ]);
      }
      self::redirect_trip_with_flag($token, 'error');
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = (string) wp_remote_retrieve_body($resp);
    $json = json_decode($raw, true);

    if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['ok']) || empty($json['checkout_url'])) {
      if (class_exists('SD_Util')) {
        SD_Util::log('trip_surface_checkout_http_fail', [
          'ride_id'   => $ride_id,
          'quote_id'  => $quote_id,
          'token'     => $token,
          'http_code' => $code,
          'body'      => $raw,
        ]);
      }
      self::redirect_trip_with_flag($token, 'error');
    }

    $checkout_url = trim((string) $json['checkout_url']);
    if ($checkout_url === '') {
      self::redirect_trip_with_flag($token, 'error');
    }

    $host = (string) wp_parse_url($checkout_url, PHP_URL_HOST);
    if ($host === '' || !in_array($host, ['checkout.stripe.com'], true)) {
      if (class_exists('SD_Util')) {
        SD_Util::log('trip_surface_checkout_unexpected_host', [
          'ride_id'      => $ride_id,
          'quote_id'     => $quote_id,
          'token'        => $token,
          'checkout_url' => $checkout_url,
          'host'         => $host,
        ]);
      }
      self::redirect_trip_with_flag($token, 'error');
    }

    wp_redirect($checkout_url);
    exit;
  }

  // ---------------------------------------------------------------------------
  // Public API payload
  // ---------------------------------------------------------------------------

  public static function public_trip_status_payload(string $token) : array {
  $token = trim($token);
  if ($token === '') {
    return [
      'ok' => false,
      'message' => 'Missing token.',
    ];
  }

  $ride_id = self::ride_id_from_token($token);
  if ($ride_id <= 0) {
    return [
      'ok' => false,
      'message' => 'Trip not found.',
    ];
  }

  $ride     = self::read_ride_context($ride_id, $token);
  $quote_id = self::resolve_quote_id_for_ride($ride_id);
  $quote    = self::read_quote_context($quote_id, $ride_id);
  $attempt  = self::read_attempt_context($ride_id, $quote_id);
  $exec     = class_exists('SD_Module_OperatorExecutionIntel')
    ? SD_Module_OperatorExecutionIntel::read_for_ride($ride_id)
    : [];
  $display  = self::build_display_model($ride, $quote, $attempt, $exec);

  return [
    'ok'               => true,
    'ride_id'          => $ride_id,
    'headline'         => (string) $display['headline'],
    'subheadline'      => (string) $display['subheadline'],
    'body_message'     => (string) $display['body_message'],
    'timeline'         => is_array($display['timeline']) ? $display['timeline'] : [],
    'lead_status'      => (string) ($ride['lead_status'] ?? ''),
    'ride_state'       => (string) ($ride['ride_state'] ?? ''),
    'quote_status'     => (string) ($quote['status'] ?? ''),
    'pickup_text'      => (string) ($ride['pickup_text'] ?? ''),
    'dropoff_text'     => (string) ($ride['dropoff_text'] ?? ''),
    'trip_title'       => self::trip_title($ride),
    'route_text'       => self::route_text($ride),
    'hero_meta'        => self::hero_meta_bits($ride),
    'hero_eta_line'    => (string) ($display['hero_eta_line'] ?? ''),
    'state_body_html'  => self::render_state_body($ride, $quote, $attempt, $display),
    'live_update_html' => self::render_live_update_card($ride, $quote, $display, $exec),
    'live_map_html'    => self::render_live_map_card($ride, $exec),
    'pickup_lat'       => (float) ($exec['pickup_lat'] ?? 0),
    'pickup_lng'       => (float) ($exec['pickup_lng'] ?? 0),
    'driver_lat'       => (float) ($exec['operator_lat'] ?? 0),
    'driver_lng'       => (float) ($exec['operator_lng'] ?? 0),
    'driver_ts'        => (int) ($exec['operator_ts'] ?? 0),
    'arrived_at_ts'    => (int) ($exec['phase_ts'] ?? 0),
    'current_step'     => (string) ($display['current_step'] ?? ''),
    'show_quote_first' => ((string) ($quote['status'] ?? '') === 'PRESENTED'),
    'debug' => [
      'server_time'          => time(),
      'ride_state'           => (string) ($ride['ride_state'] ?? ''),
      'quote_status'         => (string) ($quote['status'] ?? ''),
      'exec_phase'           => (string) ($exec['phase'] ?? ''),
      'pickup_lat'           => (float) ($exec['pickup_lat'] ?? 0),
      'pickup_lng'           => (float) ($exec['pickup_lng'] ?? 0),
      'driver_lat'           => (float) ($exec['operator_lat'] ?? 0),
      'driver_lng'           => (float) ($exec['operator_lng'] ?? 0),
      'driver_ts'            => (int) ($exec['operator_ts'] ?? 0),
      'driver_accuracy_m'    => (float) ($exec['operator_accuracy_m'] ?? 0),
      'eta_to_pickup_min'    => (int) ($exec['eta_to_pickup_min'] ?? 0),
      'distance_to_pickup_m' => (int) ($exec['distance_to_pickup_m'] ?? 0),
    ],
  ];
}

  // ---------------------------------------------------------------------------
  // Data readers
  // ---------------------------------------------------------------------------

  private static function lead_id_from_token(string $token) : int {
    $token = trim($token);
    if ($token === '') return 0;

    $ids = get_posts([
      'post_type'      => 'sd_lead',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'     => SD_Meta::TRIP_TOKEN,
          'value'   => $token,
          'compare' => '=',
        ],
      ],
    ]);

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  private static function read_lead_context(int $lead_id, string $token) : array {
    $tenant_id = (int) get_post_meta($lead_id, SD_Meta::TENANT_ID, true);

    return [
      'lead_id'           => $lead_id,
      'tenant_id'         => $tenant_id,
      'token'             => $token,
      'pickup_text'       => (string) get_post_meta($lead_id, SD_Meta::PICKUP_TEXT, true),
      'dropoff_text'      => (string) get_post_meta($lead_id, SD_Meta::DROPOFF_TEXT, true),
      'lead_status'       => (string) get_post_meta($lead_id, SD_Meta::LEAD_STATUS, true),
      'request_mode'      => (string) get_post_meta($lead_id, SD_Meta::REQUEST_MODE, true),
      'requested_ts'      => (int) get_post_meta($lead_id, SD_Meta::REQUESTED_TS, true),
      'promoted_ride_id'  => (int) get_post_meta($lead_id, SD_Meta::PROMOTED_RIDE_ID, true),
      'availability'      => (string) get_post_meta($lead_id, SD_Meta::AVAILABILITY_STATUS, true),
    ];
  }

  private static function render_lead_surface(array $lead) : void {
    $pickup  = self::short_city_address((string) ($lead['pickup_text'] ?? ''));
    $dropoff = self::short_city_address((string) ($lead['dropoff_text'] ?? ''));
    $headline = 'Request received';
    $sub = 'Your request is in progress. Keep this page open for updates.';
    $status = (string) ($lead['lead_status'] ?? '');

    if ($status === 'LEAD_UNAVAILABLE') {
      $headline = 'Currently unavailable';
      $sub = 'We captured your request but cannot service it right now.';
    } elseif ($status === 'LEAD_PENDING_AVAILABILITY') {
      $headline = 'Checking availability';
      $sub = 'We are evaluating timing and availability now.';
    } elseif ($status === 'LEAD_QUOTING') {
      $headline = 'Preparing your quote';
      $sub = 'We are building your trip details and pricing now.';
    } elseif ($status === 'LEAD_QUOTED' || $status === 'LEAD_AUTH_PENDING') {
      $headline = 'Next step coming soon';
      $sub = 'Your request is ready for the next step.';
    }

    $html  = self::styles();
    $html .= '<div class="sd-trip-wrap">';
    $html .= '<div class="sd-trip-card sd-trip-hero">';
    $html .= '<div class="sd-trip-hero-top">';
    $html .= '<div class="sd-trip-hero-route">';
    $html .= '<span class="sd-trip-route-sub">' . esc_html($pickup !== '' ? ($pickup . ' →') : '') . '</span>';
    $html .= '</div>';
    $html .= '<div class="sd-trip-hero-ride">Lead #' . (int) ($lead['lead_id'] ?? 0) . '</div>';
    $html .= '</div>';
    $html .= '<div class="sd-trip-hero-destination">' . esc_html($dropoff !== '' ? $dropoff : 'Your Request') . '</div>';
    $html .= '<div class="sd-trip-status-headline">' . esc_html($headline) . '</div>';
    $html .= '<div class="sd-trip-sub sd-trip-sub-strong">' . esc_html($sub) . '</div>';
    $html .= '</div>';
    $html .= '<div class="sd-trip-card">';
    $html .= '<div class="sd-trip-section-title">Request details</div>';
    $html .= '<div class="sd-trip-route-line"><strong>Pickup:</strong> ' . esc_html((string) ($lead['pickup_text'] ?? '—')) . '</div>';
    $html .= '<div class="sd-trip-route-line"><strong>Dropoff:</strong> ' . esc_html((string) ($lead['dropoff_text'] ?? '—')) . '</div>';
    $html .= '<div class="sd-trip-route-line"><strong>Status:</strong> ' . esc_html($status !== '' ? $status : 'LEAD_CAPTURED') . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    self::render_shell('Trip', $html);
  }

  private static function resolve_quote_id_for_ride(int $ride_id) : int {
    $from_ride = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($from_ride > 0 && get_post_type($from_ride) === 'sd_quote') {
      return $from_ride;
    }

    $latest = (int) get_post_meta($ride_id, '_sd_latest_quote_id', true);
    if ($latest > 0 && get_post_type($latest) === 'sd_quote') {
      return $latest;
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
          'key'     => SD_Meta::RIDE_ID,
          'value'   => $ride_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  private static function read_ride_context(int $ride_id, string $token) : array {
    $tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);

    return [
      'ride_id'      => $ride_id,
      'tenant_id'    => $tenant_id,
      'token'        => $token,
      'pickup_text'  => (string) get_post_meta($ride_id, SD_Meta::PICKUP_TEXT, true),
      'dropoff_text' => (string) get_post_meta($ride_id, SD_Meta::DROPOFF_TEXT, true),
      'lead_status'  => (string) get_post_meta($ride_id, SD_Meta::LEAD_STATUS, true),
      'ride_state'   => (string) get_post_meta($ride_id, SD_Meta::RIDE_STATE, true),
      'scheduled_ts' => (int) get_post_meta($ride_id, SD_Meta::PICKUP_SCHEDULED_TS, true),
    ];
  }

  private static function read_quote_context(int $quote_id, int $ride_id) : array {
    if ($quote_id <= 0) {
      return [
        'quote_id'       => 0,
        'ride_id'        => $ride_id,
        'status'         => '',
        'draft'          => [],
        'total_cents'    => 0,
        'currency'       => 'usd',
        'pickup_eta_min' => 0,
      ];
    }

    $draft = self::parse_quote_draft_payload($quote_id);

    return [
      'quote_id'       => $quote_id,
      'ride_id'        => $ride_id,
      'status'         => (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true),
      'draft'          => $draft,
      'total_cents'    => (int) ($draft['quote']['total_cents'] ?? 0),
      'currency'       => (string) ($draft['quote']['currency'] ?? 'usd'),
      'pickup_eta_min' => (int) ($draft['quote']['pickup_eta_min'] ?? 0),
    ];
  }

  private static function read_attempt_context(int $ride_id, int $quote_id) : array {
    if ($ride_id <= 0 || $quote_id <= 0) {
      return [
        'attempt_id'    => 0,
        'status'        => '',
        'authorized_at' => 0,
        'captured_at'   => 0,
      ];
    }

    $ids = get_posts([
      'post_type'      => 'sd_attempt',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'     => SD_Meta::P_ATTEMPT_RIDE_ID,
          'value'   => $ride_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
        [
          'key'     => SD_Meta::P_ATTEMPT_QUOTE_ID,
          'value'   => $quote_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    $attempt_id = !empty($ids[0]) ? (int) $ids[0] : 0;
    if ($attempt_id <= 0) {
      return [
        'attempt_id'    => 0,
        'status'        => '',
        'authorized_at' => 0,
        'captured_at'   => 0,
      ];
    }

    return [
      'attempt_id'    => $attempt_id,
      'status'        => (string) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_STATUS, true),
      'authorized_at' => (int) get_post_meta($attempt_id, SD_Meta::P_ATTEMPT_AUTHORIZED_AT, true),
      'captured_at'   => (int) get_post_meta($attempt_id, SD_Meta::P_STRIPE_CAPTURED_AT, true),
    ];
  }

  private static function parse_quote_draft_payload(int $quote_id) : array {
    if ($quote_id <= 0) return [];

    $raw = (string) get_post_meta($quote_id, SD_Meta::P_QUOTE_DRAFT_JSON, true);
    if ($raw === '') return [];

    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  private static function trip_title(array $ride) : string {
    $dropoff = trim((string) ($ride['dropoff_text'] ?? ''));
    if ($dropoff !== '') return $dropoff;
    return 'Your Trip';
  }

  private static function route_text(array $ride) : string {
    $pickup  = trim((string) ($ride['pickup_text'] ?? ''));
    $dropoff = trim((string) ($ride['dropoff_text'] ?? ''));

    if ($pickup !== '' && $dropoff !== '') {
      return $pickup . ' → ' . $dropoff;
    }

    if ($pickup !== '') return $pickup;
    if ($dropoff !== '') return $dropoff;

    return '';
  }

  private static function hero_meta_bits(array $ride) : array {
    $bits = [];

    if (!empty($ride['ride_id'])) {
      $bits[] = 'Ride #' . (int) $ride['ride_id'];
    }

    if (!empty($ride['scheduled_ts']) && (int) $ride['scheduled_ts'] > 0) {
      $bits[] = 'Scheduled ' . wp_date('M j, g:i a', (int) $ride['scheduled_ts']);
    }

    return $bits;
  }

  private static function tenant_setting(int $tenant_id, string $key, $default = '') {
    if ($tenant_id <= 0 || $key === '') return $default;
    $value = get_post_meta($tenant_id, $key, true);
    return ($value !== '' && $value !== null) ? $value : $default;
  }

  private static function tenant_experience_context(int $tenant_id) : array {
    $tone = strtolower((string) self::tenant_setting($tenant_id, 'sd_tenant_service_tone', 'professional'));
    if (!in_array($tone, ['friendly', 'professional', 'luxury'], true)) {
      $tone = 'professional';
    }

    $driver_display_name = trim((string) self::tenant_setting($tenant_id, 'sd_tenant_driver_display_name', ''));
    $brand_name = trim((string) self::tenant_setting($tenant_id, 'sd_tenant_brand_name', ''));

    return [
      'tone'                => $tone,
      'driver_display_name' => $driver_display_name,
      'brand_name'          => $brand_name,
    ];
  }

  private static function tone_copy(string $tone, string $key, string $fallback = '') : string {
    $map = [
      'friendly' => [
        'driver_arriving_headline' => 'Driver arriving',
        'driver_arriving_sub'      => 'Your driver is here.',
        'trip_inprogress_headline' => 'Trip in progress',
        'trip_inprogress_sub'      => 'Your trip is underway.',
      ],
      'professional' => [
        'driver_arriving_headline' => 'Driver arriving',
        'driver_arriving_sub'      => 'Your driver has arrived or is waiting at pickup.',
        'trip_inprogress_headline' => 'Trip in progress',
        'trip_inprogress_sub'      => 'Your trip is underway.',
      ],
      'luxury' => [
        'driver_arriving_headline' => 'Chauffeur arriving',
        'driver_arriving_sub'      => 'Your chauffeur has arrived.',
        'trip_inprogress_headline' => 'Trip in progress',
        'trip_inprogress_sub'      => 'Your ride is underway.',
      ],
    ];

    return $map[$tone][$key] ?? $fallback;
  }

  private static function build_display_model(array $ride, array $quote, array $attempt, array $exec = []) : array {
    $lead_status    = (string) ($ride['lead_status'] ?? '');
    $ride_state     = (string) ($ride['ride_state'] ?? '');
    $quote_status   = (string) ($quote['status'] ?? '');
    $tenant_id      = (int) ($ride['tenant_id'] ?? 0);
    $eta_pickup_min = (int) ($exec['eta_to_pickup_min'] ?? 0);

    $xp   = self::tenant_experience_context($tenant_id);
    $tone = (string) ($xp['tone'] ?? 'professional');

    $headline      = 'Trip status pending';
    $subheadline   = 'We will keep this page updated as your trip progresses.';
    $current_step  = 'request';
    $hero_eta_line = '';

    if ($quote_status === 'PRESENTED') {
      $headline = 'Quote ready';
      $subheadline = 'Review your quote and authorize payment to confirm your trip request.';
      $current_step = 'payment';
    } elseif (in_array($quote_status, ['PROPOSED', 'APPROVED'], true) || in_array($lead_status, ['LEAD_CAPTURED', 'LEAD_WAITING_QUOTE'], true)) {
      $headline = 'Request received';
      $subheadline = 'Your operator is reviewing this trip request now.';
      $current_step = 'request';
    } elseif ($quote_status === 'LEAD_ACCEPTED') {
      $headline = 'Preparing payment';
      $subheadline = 'Your secure payment session is being prepared.';
      $current_step = 'payment';
    } elseif ($quote_status === 'PAYMENT_PENDING' && $ride_state === 'RIDE_QUEUED') {
      $headline = 'Payment secured';
      $subheadline = 'Your ride is ready for dispatch.';
      $current_step = 'secured';
    } elseif ($ride_state === 'RIDE_DEADHEAD') {
      $headline = 'Your driver is on the way.';
      $subheadline = 'Please stay available for pickup.';
      $current_step = 'enroute';

      if ($eta_pickup_min > 0) {
        $hero_eta_line = 'Driver arriving in ' . $eta_pickup_min . ' min';
      }
    } elseif ($ride_state === 'RIDE_WAITING') {
      $headline = self::tone_copy($tone, 'driver_arriving_headline', 'Driver arriving');
      $subheadline = self::tone_copy($tone, 'driver_arriving_sub', 'Your driver has arrived or is waiting at pickup.');
      $current_step = 'arriving';
      $hero_eta_line = 'Driver has arrived at pickup';
    } elseif ($ride_state === 'RIDE_INPROGRESS') {
      $headline = self::tone_copy($tone, 'trip_inprogress_headline', 'Trip in progress');
      $subheadline = self::tone_copy($tone, 'trip_inprogress_sub', 'Your trip is underway.');
      $current_step = 'inprogress';
    } elseif ($ride_state === 'RIDE_ARRIVED') {
      $headline = 'Arrived at destination';
      $subheadline = 'You have arrived at your destination.';
      $current_step = 'complete';
    } elseif ($ride_state === 'RIDE_COMPLETE') {
      $headline = 'Trip completed';
      $subheadline = 'Your ride is complete.';
      $current_step = 'complete';
    } elseif ($ride_state === 'RIDE_CANCELLED') {
      $headline = 'Trip cancelled';
      $subheadline = 'This trip was cancelled.';
      $current_step = 'cancelled';
    }

    return [
      'headline'      => $headline,
      'subheadline'   => $subheadline,
      'body_message'  => $subheadline,
      'current_step'  => $current_step,
      'hero_eta_line' => $hero_eta_line,
      'timeline'      => self::timeline_steps($current_step, $tone),
    ];
  }

  private static function timeline_steps(string $current_step, string $tone = 'professional') : array {
    $order = [
      'request'    => 1,
      'payment'    => 2,
      'secured'    => 3,
      'enroute'    => 4,
      'arriving'   => 5,
      'inprogress' => 6,
      'complete'   => 7,
      'cancelled'  => 99,
    ];

    $current_rank = isset($order[$current_step]) ? (int) $order[$current_step] : 1;

    $enroute_label  = ($tone === 'luxury') ? 'Chauffeur en route' : 'Driver en route';
    $arriving_label = ($tone === 'luxury') ? 'Chauffeur arriving' : 'Driver arriving';

    $enroute_note = ($tone === 'luxury')
      ? 'Your chauffeur is on the way to pickup.'
      : 'Your driver is on the way to pickup.';

    $arriving_note = ($tone === 'luxury')
      ? 'Your chauffeur has arrived or is waiting at pickup.'
      : 'Your driver has arrived or is waiting at pickup.';

    if ($tone === 'friendly') {
      $enroute_note  = 'Your driver is on the way!';
      $arriving_note = 'Your driver is here.';
    }

    $steps = [
      ['key' => 'request',    'label' => 'Request received', 'note' => 'Your trip request has been received.'],
      ['key' => 'payment',    'label' => 'Payment review',   'note' => 'Review and authorize your trip payment.'],
      ['key' => 'secured',    'label' => 'Payment secured',  'note' => 'Payment is authorized and your trip is ready.'],
      ['key' => 'enroute',    'label' => $enroute_label,     'note' => $enroute_note],
      ['key' => 'arriving',   'label' => $arriving_label,    'note' => $arriving_note],
      ['key' => 'inprogress', 'label' => 'Trip in progress', 'note' => 'Your trip is underway.'],
      ['key' => 'complete',   'label' => 'Trip completed',   'note' => 'Your ride is complete.'],
    ];

    foreach ($steps as $i => $step) {
      $rank = (int) ($order[$step['key']] ?? 0);

      if ($current_step === 'cancelled') {
        $steps[$i]['state'] = ($step['key'] === 'request') ? 'complete' : 'pending';
        continue;
      }

      if ($rank < $current_rank) {
        $steps[$i]['state'] = 'complete';
      } elseif ($rank === $current_rank) {
        $steps[$i]['state'] = 'current';
      } else {
        $steps[$i]['state'] = 'pending';
      }
    }

    return $steps;
  }

  private static function token_from_request_path() : string {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri === '') return '';

    $path = wp_parse_url($uri, PHP_URL_PATH);
    if (!is_string($path) || $path === '') return '';

    if (preg_match('~^/trip/([^/]+)/?$~', $path, $m)) {
      return sanitize_text_field(rawurldecode((string) $m[1]));
    }

    return '';
  }

  private static function redirect_trip_with_flag(string $token, string $flag) : void {
    $url = home_url('/trip/' . rawurlencode($token) . '/');
    $url = add_query_arg(['pay' => $flag], $url);
    wp_safe_redirect($url);
    exit;
  }

  private static function format_money(int $amount_cents, string $currency = 'usd') : string {
    $currency = strtoupper($currency !== '' ? $currency : 'usd');
    if ($amount_cents <= 0) return '— ' . $currency;
    return '$' . number_format($amount_cents / 100, 2) . ' ' . $currency;
  }

  private static function js_quote(string $value) : string {
    return wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  private static function short_city_address(string $addr) : string {
    $addr = trim($addr);
    if ($addr === '') return '';

    $addr = preg_replace('/,\s*USA$/i', '', $addr);
    $addr = preg_replace('/\s+\d{5}(-\d{4})?$/', '', $addr);

    return trim($addr);
  }
  
  private static function render_live_map_card(array $ride, array $exec = []) : string {
  $ride_state = (string) ($ride['ride_state'] ?? '');

  if (!in_array($ride_state, ['RIDE_DEADHEAD', 'RIDE_WAITING'], true)) {
    return '';
  }

  return
    '<div id="sd-trip-live-map-card" class="sd-trip-card" style="display:none">' .
      '<div class="sd-trip-card-head">' .
        '<h2>Live Locations</h2>' .
        '<div class="sd-trip-sub">Your location and your driver update live here.</div>' .
      '</div>' .
      '<div id="sd-trip-live-map-status" class="sd-trip-sub sd-trip-map-status">Preparing live locations…</div>' .
      '<div id="sd-trip-live-map" class="sd-trip-live-map-canvas"></div>' .
    '</div>';
}

  private static function render_live_map_debug_card() : string {
  return
    '<div id="sd-trip-debug-card" class="sd-trip-card" style="display:none">' .
      '<div class="sd-trip-card-head">' .
        '<h2>Live Map Debug</h2>' .
        '<div class="sd-trip-sub">Browser geolocation + rider/driver live map diagnostics.</div>' .
      '</div>' .
      '<div class="sd-trip-debug-actions">' .
        '<button type="button" id="sd-trip-debug-toggle" class="sd-trip-btn">Show Debug</button>' .
        '<button type="button" id="sd-trip-debug-copy" class="sd-trip-btn">Copy Debug JSON</button>' .
      '</div>' .
      '<pre id="sd-trip-debug-pre" class="sd-trip-debug-pre" style="display:none"></pre>' .
    '</div>';
}

    private static function polling_js(string $token) : string {
    $endpoint = esc_url_raw(rest_url('sd/v1/trip-status?trip_token=' . rawurlencode($token)));
    $endpoint_json = self::js_quote($endpoint);

    return <<<HTML
<link
  rel="stylesheet"
  href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
  crossorigin=""
/>
<script
  src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
  crossorigin=""
></script>

<script>
(function(){
  const endpoint = {$endpoint_json};

  const routeSubEl = document.getElementById("sd-trip-route-sub");
  const heroRideEl = document.getElementById("sd-trip-hero-ride");
  const destinationEl = document.getElementById("sd-trip-hero-destination");
  const heroEtaEl = document.getElementById("sd-trip-hero-eta");
  const headlineEl = document.getElementById("sd-trip-headline");
  const subheadlineEl = document.getElementById("sd-trip-subheadline");
  const liveUpdateEl = document.getElementById("sd-trip-live-update");
  const stateBodyEl = document.getElementById("sd-trip-state-body");
  const stateBodySecondaryEl = document.getElementById("sd-trip-state-body-secondary");
  const liveMapWrapEl = document.getElementById("sd-trip-live-map-wrap");
  const wrapEl = document.querySelector(".sd-trip-wrap");

  if (!endpoint || !headlineEl || !subheadlineEl || !wrapEl) return;

  let busy = false;
  let latestPayload = null;

  let liveMap = null;
  let riderMarker = null;
  let driverMarker = null;

  let riderWatchId = null;
  let riderWatchActive = false;
  let firstFixReceived = false;
  let geoRetryTimer = null;
  let geoPromptedOnce = false;

  let riderLat = 0;
  let riderLng = 0;

  const debugCardEl = document.getElementById("sd-trip-debug-card");
  const debugPreEl = document.getElementById("sd-trip-debug-pre");
  const debugToggleEl = document.getElementById("sd-trip-debug-toggle");
  const debugCopyEl = document.getElementById("sd-trip-debug-copy");

  const debugState = {
    geolocation_supported: !!navigator.geolocation,
    secure_context: !!window.isSecureContext,
    permission_state: "unknown",
    geo_status: "idle", // idle | waiting | delayed | active | denied | unsupported | error
    watch_started: false,
    watch_id: null,
    rider_lat: 0,
    rider_lng: 0,
    rider_accuracy_m: 0,
    rider_ts: 0,
    geo_error_code: "",
    geo_error_message: "",
    map_ready: false,
    rider_marker: false,
    driver_marker: false,
    server_debug: null
  };

  function esc(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function shortAddr(str) {
    return String(str || "")
      .replace(/,\\s*USA$/i, "")
      .replace(/\\s+\\d{5}(-\\d{4})?$/, "");
  }

  function formatTimer(totalSeconds) {
    const s = Math.max(0, Number(totalSeconds || 0));
    const mm = String(Math.floor(s / 60)).padStart(2, "0");
    const ss = String(s % 60).padStart(2, "0");
    return mm + ":" + ss;
  }

  function setGeoStatus(status, code, message) {
    debugState.geo_status = status || "idle";
    debugState.geo_error_code = code ? String(code) : "";
    debugState.geo_error_message = message ? String(message) : "";
    renderDebug();
  }

  function renderDebug() {
    if (!debugCardEl || !debugPreEl) return;

    const out = {
      now_iso: new Date().toISOString(),
      rider: {
        lat: debugState.rider_lat,
        lng: debugState.rider_lng,
        accuracy_m: debugState.rider_accuracy_m,
        ts: debugState.rider_ts
      },
      geolocation_supported: debugState.geolocation_supported,
      secure_context: debugState.secure_context,
      permission_state: debugState.permission_state,
      geo_status: debugState.geo_status,
      watch_started: debugState.watch_started,
      watch_id: debugState.watch_id,
      geo_error_code: debugState.geo_error_code,
      geo_error_message: debugState.geo_error_message,
      map_ready: debugState.map_ready,
      rider_marker: debugState.rider_marker,
      driver_marker: debugState.driver_marker,
      server: debugState.server_debug
    };

    debugPreEl.textContent = JSON.stringify(out, null, 2);
  }

  function showDebugCard() {
    if (debugCardEl) debugCardEl.style.display = "";
    renderDebug();
  }

  if (debugToggleEl) {
    debugToggleEl.addEventListener("click", function() {
      if (!debugPreEl) return;
      const isHidden = debugPreEl.style.display === "none";
      debugPreEl.style.display = isHidden ? "" : "none";
      debugToggleEl.textContent = isHidden ? "Hide Debug" : "Show Debug";
      renderDebug();
    });
  }

  if (debugCopyEl) {
    debugCopyEl.addEventListener("click", async function() {
      if (!debugPreEl) return;
      renderDebug();
      try {
        await navigator.clipboard.writeText(debugPreEl.textContent || "");
      } catch (e) {}
    });
  }

  if (navigator.permissions && navigator.permissions.query) {
    navigator.permissions.query({ name: "geolocation" }).then(function(result) {
      debugState.permission_state = result.state || "unknown";
      renderDebug();

      result.onchange = function() {
        debugState.permission_state = result.state || "unknown";

        if (result.state === "granted" && shouldRunLiveMap(latestPayload)) {
          scheduleGeoRetry(250);
        } else if (result.state === "denied") {
          setGeoStatus("denied", "1", "Geolocation permission denied");
          stopRiderWatch();
          if (latestPayload) updateLiveMap(latestPayload);
        }

        renderDebug();
      };
    }).catch(function() {
      renderDebug();
    });
  }

  function updateArrivalTimer(payload) {
    const timerEl = document.getElementById("sd-trip-arrival-timer");
    if (!timerEl) return;

    const arrivedAt = Number(payload && payload.arrived_at_ts ? payload.arrived_at_ts : 0);
    if (!arrivedAt) {
      timerEl.textContent = "00:00";
      return;
    }

    const now = Math.floor(Date.now() / 1000);
    timerEl.textContent = formatTimer(now - arrivedAt);
  }

  function makeDotIcon(label, kind) {
    const cls = kind === "driver"
      ? "sd-trip-map-dot sd-trip-map-dot-driver"
      : "sd-trip-map-dot sd-trip-map-dot-you";

    return L.divIcon({
      className: "sd-trip-map-divicon-wrap",
      html:
        "<div class=\"" + cls + "\">" +
          "<span class=\"sd-trip-map-dot-core\"></span>" +
          "<span class=\"sd-trip-map-dot-label\">" + esc(label) + "</span>" +
        "</div>",
      iconSize: [88, 28],
      iconAnchor: [14, 14]
    });
  }

  function ensureRiderMarker(map, lat, lng) {
    if (!riderMarker) {
      riderMarker = L.marker([lat, lng], {
        title: "You",
        icon: makeDotIcon("You", "you")
      }).addTo(map);
    } else {
      riderMarker.setLatLng([lat, lng]);
    }
  }

  function ensureDriverMarker(map, lat, lng) {
    if (!driverMarker) {
      driverMarker = L.marker([lat, lng], {
        title: "Driver",
        icon: makeDotIcon("Driver", "driver")
      }).addTo(map);
    } else {
      driverMarker.setLatLng([lat, lng]);
    }
  }

  function ensureMap() {
    const mapEl = document.getElementById("sd-trip-live-map");
    if (!mapEl || typeof L === "undefined") return null;

    if (liveMap && liveMap._container !== mapEl) {
      liveMap.remove();
      liveMap = null;
      riderMarker = null;
      driverMarker = null;
      debugState.map_ready = false;
      debugState.rider_marker = false;
      debugState.driver_marker = false;
    }

    if (!liveMap) {
      liveMap = L.map(mapEl, {
        zoomControl: true,
        attributionControl: true
      });

      L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19,
        attribution: "&copy; OpenStreetMap contributors"
      }).addTo(liveMap);

      liveMap.setView([30.874, -83.283], 13);
      debugState.map_ready = true;

      setTimeout(function() {
        if (liveMap) liveMap.invalidateSize();
      }, 120);
    }

    return liveMap;
  }

  function shouldRunLiveMap(payload) {
    return !!(payload && (payload.ride_state === "RIDE_DEADHEAD" || payload.ride_state === "RIDE_WAITING"));
  }

  function clearGeoRetry() {
    if (geoRetryTimer) {
      clearTimeout(geoRetryTimer);
      geoRetryTimer = null;
    }
  }

  function scheduleGeoRetry(delayMs) {
    clearGeoRetry();

    geoRetryTimer = setTimeout(function() {
      geoRetryTimer = null;
      if (!riderWatchActive && shouldRunLiveMap(latestPayload)) {
        startRiderWatch();
      }
    }, Math.max(250, Number(delayMs || 0)));
  }

  function stopRiderWatch() {
    clearGeoRetry();

    if (riderWatchId !== null && navigator.geolocation) {
      try { navigator.geolocation.clearWatch(riderWatchId); } catch (e) {}
    }

    riderWatchId = null;
    riderWatchActive = false;
    debugState.watch_started = false;
    debugState.watch_id = null;
  }

  function handleGeoSuccess(pos) {
    riderLat = Number(pos.coords.latitude || 0);
    riderLng = Number(pos.coords.longitude || 0);
    firstFixReceived = true;

    debugState.watch_id = riderWatchId;
    debugState.rider_lat = riderLat;
    debugState.rider_lng = riderLng;
    debugState.rider_accuracy_m = Number(pos.coords.accuracy || 0);
    debugState.rider_ts = Number(pos.timestamp || Date.now());

    setGeoStatus("active", "", "");

    if (latestPayload) updateLiveMap(latestPayload);
    renderDebug();
  }

  function handleGeoError(err) {
    const code = String(err && err.code ? err.code : "");
    const message = String(err && err.message ? err.message : "Unknown geolocation error");

    if (code === "1") {
      setGeoStatus("denied", code, message);
      stopRiderWatch();
    } else if (code === "2") {
      setGeoStatus("error", code, message);
      scheduleGeoRetry(4000);
    } else if (code === "3") {
      // Timeout before first fix is not terminal.
      if (!firstFixReceived) {
        setGeoStatus("delayed", code, "Still locating you…");
        stopRiderWatch();
        scheduleGeoRetry(3000);
      } else {
        setGeoStatus("active", "", "");
      }
    } else {
      setGeoStatus("error", code, message);
      scheduleGeoRetry(5000);
    }

    if (latestPayload) updateLiveMap(latestPayload);
    renderDebug();
  }

  function startRiderWatch() {
    if (!navigator.geolocation) {
      setGeoStatus("unsupported", "", "Geolocation unsupported");
      renderDebug();
      return;
    }

    if (riderWatchActive) {
      renderDebug();
      return;
    }

    riderWatchActive = true;
    debugState.watch_started = true;
    debugState.watch_id = null;
    setGeoStatus("waiting", "", "");

    riderWatchId = navigator.geolocation.watchPosition(
      handleGeoSuccess,
      handleGeoError,
      {
        enableHighAccuracy: true,
        maximumAge: 0,
        timeout: 15000
      }
    );

    debugState.watch_id = riderWatchId;
    renderDebug();
  }

  function ensureRiderLocationWatch() {
    if (!navigator.geolocation) {
      setGeoStatus("unsupported", "", "Geolocation unsupported");
      renderDebug();
      return;
    }

    if (!window.isSecureContext) {
      setGeoStatus("error", "", "Location requires secure context");
      renderDebug();
      return;
    }

    if (!geoPromptedOnce) {
      geoPromptedOnce = true;
    }

    if (debugState.permission_state === "denied") {
      setGeoStatus("denied", "1", "Geolocation permission denied");
      renderDebug();
      return;
    }

    startRiderWatch();
  }

  function mapStatusText(payload, hasRider, hasDriver) {
    if (!hasDriver && !hasRider) {
      if (debugState.geo_status === "denied") {
        return "Location access denied. Waiting for driver location.";
      }
      if (debugState.geo_status === "delayed" || debugState.geo_status === "waiting") {
        return "Still locating you and driver…";
      }
      return "Waiting for live locations...";
    }

    if (hasDriver && !hasRider) {
      if (debugState.geo_status === "denied") {
        return "Driver live. Location access denied for your device.";
      }
      if (debugState.geo_status === "delayed") {
        return "Driver live. Still locating you…";
      }
      if (debugState.geo_status === "waiting") {
        return "Driver live. Waiting for your location permission…";
      }
      if (debugState.geo_status === "unsupported") {
        return "Driver live. Your browser does not support location.";
      }
      return "Driver live. Waiting for your location…";
    }

    if (!hasDriver && hasRider) {
      return "Your location is live. Waiting for driver location...";
    }

    return "Live view of you and your driver.";
  }

  function updateLiveMap(payload) {
    if (!liveMapWrapEl) return;

    let cardEl = document.getElementById("sd-trip-live-map-card");
    let statusEl = document.getElementById("sd-trip-live-map-status");
    let mapEl = document.getElementById("sd-trip-live-map");

    if (!cardEl && typeof payload.live_map_html === "string" && payload.live_map_html !== "") {
      liveMapWrapEl.innerHTML = payload.live_map_html;
      cardEl = document.getElementById("sd-trip-live-map-card");
      statusEl = document.getElementById("sd-trip-live-map-status");
      mapEl = document.getElementById("sd-trip-live-map");
    }

    showDebugCard();
    debugState.server_debug = payload && payload.debug ? payload.debug : null;

    if (!cardEl || !statusEl || !mapEl) {
      renderDebug();
      return;
    }

    if (!shouldRunLiveMap(payload)) {
      cardEl.style.display = "none";
      stopRiderWatch();
      renderDebug();
      return;
    }

    cardEl.style.display = "";

    const driverLat = Number(payload && payload.driver_lat ? payload.driver_lat : 0);
    const driverLng = Number(payload && payload.driver_lng ? payload.driver_lng : 0);
    const hasRider = !!(riderLat && riderLng);
    const hasDriver = !!(driverLat && driverLng);

    const map = ensureMap();
    if (!map) {
      statusEl.textContent = "Map is not ready.";
      renderDebug();
      return;
    }

    setTimeout(function() {
      if (map) map.invalidateSize();
    }, 80);

    if (hasRider) {
      ensureRiderMarker(map, riderLat, riderLng);
      debugState.rider_marker = true;
    }

    if (hasDriver) {
      ensureDriverMarker(map, driverLat, driverLng);
      debugState.driver_marker = true;
    }

    if (hasRider && hasDriver) {
      const bounds = L.latLngBounds([
        [riderLat, riderLng],
        [driverLat, driverLng]
      ]);

      map.fitBounds(bounds, {
        padding: [40, 40],
        maxZoom: 18
      });
    } else if (hasDriver) {
      map.setView([driverLat, driverLng], 16);
    } else if (hasRider) {
      map.setView([riderLat, riderLng], 16);
    }

    statusEl.textContent = mapStatusText(payload, hasRider, hasDriver);
    renderDebug();
  }

  function renderTimeline(steps) {
    const card = document.getElementById("sd-trip-status-card");
    if (!card) return;

    const html = (Array.isArray(steps) ? steps : []).map(function(step) {
      const state = step && step.state ? step.state : "pending";
      const label = step && step.label ? step.label : "";
      const note  = step && step.note ? step.note : "";
      let cls = "sd-trip-step";
      if (state === "complete") cls += " is-complete";
      if (state === "current") cls += " is-current";

      return ""
        + "<div class=\\"" + cls + "\\">"
        +   "<div class=\\"sd-trip-step-dot\\"></div>"
        +   "<div class=\\"sd-trip-step-copy\\">"
        +     "<div class=\\"sd-trip-step-label\\">" + esc(label) + "</div>"
        +     (note ? "<div class=\\"sd-trip-step-note\\">" + esc(note) + "</div>" : "")
        +   "</div>"
        + "</div>";
    }).join("");

    card.innerHTML = ""
      + "<div class=\\"sd-trip-card-head\\">"
      +   "<h2>Trip Status</h2>"
      +   "<div class=\\"sd-trip-sub\\">We will keep this page updated as your trip progresses.</div>"
      + "</div>"
      + "<div id=\\"sd-trip-timeline\\" class=\\"sd-trip-timeline\\">" + html + "</div>";
  }

  function renderCompactStatus(text) {
    const card = document.getElementById("sd-trip-status-card");
    if (!card) return;

    card.innerHTML = ""
      + "<div class=\\"sd-trip-card-head\\">"
      +   "<h2>Trip Status</h2>"
      +   "<div class=\\"sd-trip-sub\\">" + esc(text) + "</div>"
      + "</div>";
  }

  function updateStateBody(payload) {
    const html = (typeof payload.state_body_html === "string") ? payload.state_body_html : "";
    const showQuoteFirst = !!payload.show_quote_first;

    if (showQuoteFirst) {
      if (stateBodyEl) stateBodyEl.innerHTML = html;
      if (stateBodySecondaryEl) stateBodySecondaryEl.innerHTML = "";
    } else {
      if (stateBodyEl) stateBodyEl.innerHTML = "";
      if (stateBodySecondaryEl) stateBodySecondaryEl.innerHTML = html;
    }
  }

  function update(payload) {
    if (!payload || !payload.ok) return;

    latestPayload = payload;

    if (routeSubEl) {
      const pickup = payload.pickup_text ? shortAddr(payload.pickup_text) : "";
      routeSubEl.textContent = pickup ? (pickup + " →") : "";
    }

    if (heroRideEl) {
      heroRideEl.textContent = payload.ride_id ? ("Ride #" + String(payload.ride_id)) : "";
    }

    if (destinationEl) {
      const dropoff = payload.dropoff_text ? shortAddr(payload.dropoff_text) : "";
      destinationEl.textContent = dropoff || "Your Trip";
    }

    if (heroEtaEl) {
      if (payload.hero_eta_line) {
        heroEtaEl.textContent = payload.hero_eta_line;
        heroEtaEl.style.display = "";
      } else {
        heroEtaEl.textContent = "";
        heroEtaEl.style.display = "none";
      }
    }

    if (headlineEl) headlineEl.textContent = payload.headline || "Trip status pending";
    if (subheadlineEl) subheadlineEl.textContent = payload.subheadline || "We will keep this page updated as your trip progresses.";

    if (payload.current_step === "complete") {
      renderCompactStatus("Trip completed.");
    } else if (payload.current_step === "cancelled") {
      renderCompactStatus("Trip cancelled.");
    } else {
      renderTimeline(payload.timeline || []);
    }

    if (liveUpdateEl && typeof payload.live_update_html === "string") {
      liveUpdateEl.innerHTML = payload.live_update_html;
    }

    updateStateBody(payload);
    updateArrivalTimer(payload);
    updateLiveMap(payload);

    if (shouldRunLiveMap(payload)) {
      ensureRiderLocationWatch();
    } else {
      stopRiderWatch();
    }
  }

  async function poll() {
    if (busy) return;
    busy = true;

    try {
      const res = await fetch(endpoint, {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
        headers: { "Accept": "application/json" }
      });

      if (!res.ok) return;

      const payload = await res.json();
      update(payload);
    } catch (e) {
      console.warn("[trip-surface] poll error", e);
    } finally {
      busy = false;
    }
  }

  poll();
  setInterval(poll, 8000);
  setInterval(function(){
    if (latestPayload) updateArrivalTimer(latestPayload);
  }, 1000);
})();
</script>
HTML;
  }

  // ---------------------------------------------------------------------------
  // Render helpers
  // ---------------------------------------------------------------------------

  private static function render_shell(string $title, string $body_html) : void {
    echo '<!doctype html><html><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . esc_html($title) . '</title>';
    wp_head();
    echo '</head><body>';
    echo $body_html;
    wp_footer();
    echo '</body></html>';
  }

  private static function render_notice_card(string $message) : string {
    return '<div class="sd-trip-card"><div class="sd-trip-sub sd-trip-sub-body">' . esc_html($message) . '</div></div>';
  }

  private static function render_banner(string $message, string $tone = 'ok') : string {
    $class = 'sd-trip-banner';
    if ($tone === 'warn')  $class .= ' is-warn';
    if ($tone === 'error') $class .= ' is-error';
    if ($tone === 'ok')    $class .= ' is-ok';

    return '<div class="' . esc_attr($class) . '">' . esc_html($message) . '</div>';
  }
  
  private static function ride_has_block_conflict(int $ride_id) : bool {
  return ((int) get_post_meta($ride_id, '_sd_block_conflict', true)) === 1;
}

  private static function styles() : string {
    return <<<HTML
<style>
  :root{
    --sd-bg:#f6f7fb;
    --sd-card:#ffffff;
    --sd-text:#0f172a;
    --sd-sub:#475569;
    --sd-line:#e2e8f0;
    --sd-accent:#111827;
    --sd-alert-bg:#ecfdf5;
    --sd-alert-line:#86efac;
    --sd-ok-bg:#ecfdf5;
    --sd-ok-line:#86efac;
    --sd-warn-bg:#fffbeb;
    --sd-warn-line:#fcd34d;
    --sd-err-bg:#fef2f2;
    --sd-err-line:#fca5a5;
    --sd-step-complete:#0f172a;
    --sd-step-current:#111827;
    --sd-step-pending:#cbd5e1;
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    background:var(--sd-bg);
    color:var(--sd-text);
    font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  }
  .sd-trip-map-status{
    margin-bottom:10px;
  }
  .sd-trip-live-map-canvas{
    width:100%;
    min-height:320px;
    border-radius:14px;
    overflow:hidden;
    background:#e2e8f0;
  }
  .sd-trip-wrap{
    max-width:860px;
    margin:0 auto;
    padding:20px 14px 40px;
  }
  .sd-trip-card{
    background:var(--sd-card);
    border:1px solid var(--sd-line);
    border-radius:18px;
    padding:18px;
    margin-bottom:14px;
  }
  .sd-trip-hero{
    padding:22px 20px;
  }
  .sd-trip-card-alert{
    background:var(--sd-alert-bg);
    border-color:var(--sd-alert-line);
  }
  .sd-trip-card-head h2{
    margin:0 0 6px;
    font-size:28px;
    line-height:1.05;
  }
  .sd-trip-sub{
    font-size:14px;
    color:var(--sd-sub);
  }
  .sd-trip-sub-strong{
    font-size:16px;
  }
  .sd-trip-sub-body{
    font-size:16px;
    color:#0f172a;
  }
  .sd-trip-status-headline{
    font-size:20px;
    font-weight:800;
    margin-bottom:6px;
  }
  .sd-trip-quote-box{
    background:#fff;
    border:1px solid var(--sd-line);
    border-radius:14px;
    padding:14px;
    margin:14px 0;
  }
  .sd-trip-quote-line{
    font-size:16px;
    margin-bottom:8px;
  }
  .sd-trip-reassure{
    font-size:14px;
    color:var(--sd-sub);
    margin-bottom:14px;
  }
  .sd-trip-form{
    margin-top:8px;
  }
  .sd-trip-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:46px;
    padding:0 18px;
    border-radius:999px;
    border:1px solid var(--sd-line);
    background:#fff;
    color:var(--sd-text);
    font-weight:800;
    font-size:16px;
    cursor:pointer;
    text-decoration:none;
  }
  .sd-trip-btn-primary{
    background:var(--sd-accent);
    border-color:var(--sd-accent);
    color:#fff;
  }
  .sd-trip-banner{
    border-radius:14px;
    padding:14px 16px;
    margin-bottom:14px;
    border:1px solid var(--sd-line);
    font-size:14px;
    font-weight:700;
  }
  .sd-trip-banner.is-ok{
    background:var(--sd-ok-bg);
    border-color:var(--sd-ok-line);
  }
  .sd-trip-banner.is-warn{
    background:var(--sd-warn-bg);
    border-color:var(--sd-warn-line);
  }
  .sd-trip-banner.is-error{
    background:var(--sd-err-bg);
    border-color:var(--sd-err-line);
  }
  .sd-trip-banner-metrics{
    display:block;
  }
  .sd-trip-banner-metrics-title{
    font-size:14px;
    font-weight:800;
    margin-bottom:8px;
  }
  .sd-trip-banner-metrics-row{
    display:flex;
    gap:18px;
    flex-wrap:wrap;
    font-size:14px;
  }
  .sd-trip-timeline{
    display:flex;
    flex-direction:column;
    gap:14px;
    margin-top:8px;
  }
  .sd-trip-step{
    display:flex;
    gap:12px;
    align-items:flex-start;
    opacity:.72;
  }
  .sd-trip-step.is-complete,
  .sd-trip-step.is-current{
    opacity:1;
  }
  .sd-trip-step-dot{
    width:14px;
    height:14px;
    border-radius:999px;
    border:2px solid var(--sd-step-pending);
    background:#fff;
    margin-top:3px;
    flex:0 0 14px;
  }
  .sd-trip-step.is-complete .sd-trip-step-dot{
    background:var(--sd-step-complete);
    border-color:var(--sd-step-complete);
  }
  .sd-trip-step.is-current .sd-trip-step-dot{
    background:#fff;
    border-color:var(--sd-step-current);
    box-shadow:0 0 0 4px rgba(15,23,42,.08);
  }
  .sd-trip-step-copy{
    min-width:0;
  }
  .sd-trip-step-label{
    font-size:16px;
    font-weight:700;
    line-height:1.2;
  }
  .sd-trip-step-note{
    font-size:13px;
    color:var(--sd-sub);
    margin-top:3px;
    line-height:1.35;
  }
  .sd-trip-hero-top{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:8px;
  }
  .sd-trip-hero-route{
    min-width:0;
    flex:1 1 auto;
  }
  .sd-trip-route-sub{
    display:block;
    font-size:13px;
    color:#64748b;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
  }
  .sd-trip-hero-ride{
    font-size:13px;
    color:#64748b;
    font-weight:600;
    white-space:nowrap;
  }
  .sd-trip-hero-destination{
    font-size:22px;
    font-weight:800;
    line-height:1.2;
    margin-bottom:10px;
  }
  .sd-trip-hero-eta{
    font-size:14px;
    font-weight:700;
    color:#0f172a;
    margin-bottom:10px;
  }
  .sd-trip-debug-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:12px;
  }
  .sd-trip-debug-pre{
    margin:0;
    padding:12px;
    border-radius:12px;
    background:#0f172a;
    color:#e2e8f0;
    overflow:auto;
    font-size:12px;
    line-height:1.45;
  }
    .sd-trip-live-map-canvas .leaflet-control-attribution{
    font-size:10px;
  }
  .sd-trip-map-divicon-wrap{
    background:transparent !important;
    border:0 !important;
  }
  .sd-trip-map-dot{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:4px 10px 4px 4px;
    border-radius:999px;
    background:#ffffff;
    border:1px solid rgba(15,23,42,.12);
    box-shadow:0 4px 14px rgba(15,23,42,.12);
    white-space:nowrap;
  }
  .sd-trip-map-dot-core{
    width:18px;
    height:18px;
    border-radius:999px;
    display:inline-block;
    border:3px solid #fff;
    box-shadow:0 0 0 1px rgba(15,23,42,.12);
  }
  .sd-trip-map-dot-label{
    font-size:12px;
    line-height:1;
    font-weight:800;
    color:#0f172a;
  }
  .sd-trip-map-dot-you .sd-trip-map-dot-core{
    background:#2563eb;
  }
  .sd-trip-map-dot-driver .sd-trip-map-dot-core{
    background:#16a34a;
  }
  @media (max-width: 720px){
    .sd-trip-wrap{padding:14px 12px 32px}
    .sd-trip-card-head h2{font-size:24px}
    .sd-trip-status-headline{font-size:18px}
    .sd-trip-hero-destination{font-size:20px}
  }
</style>
HTML;
  }
}


