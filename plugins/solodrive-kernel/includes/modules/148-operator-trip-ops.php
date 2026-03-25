<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorTripOps (lead-root refactor)
 *
 * Purpose:
 * - Render the state-governed lead/trip-ops body for Drive Mode
 * - Keep operator ops UI separate from route/page orchestration
 *
 * Canon:
 * - Stable header, state-governed body
 * - lead is the canonical operator context
 * - quote work is urgent operator work
 * - auth is the gate before ride creation
 * - only the next allowed action is shown
 *
 * Intended consumers:
 * - 144-operator-drive-mode.php
 */

if (class_exists('SD_Module_OperatorTripOps', false)) { return; }

final class SD_Module_OperatorTripOps {

  /**
   * Render the state-governed body for an active lead payload.
   *
   * Expects the payload shape from SD_Module_OperatorActiveRide::build()
   */
  public static function render(array $active) : string {
    $lead_status    = (string) ($active['lead_status'] ?? '');
    $quote_status   = (string) ($active['quote_status'] ?? '');
    $attempt_status = (string) ($active['attempt_status'] ?? '');
    $ride_state     = (string) ($active['ride_state'] ?? '');

    if (in_array($quote_status, ['PROPOSED', 'APPROVED', 'PRESENTED'], true) || $lead_status === 'LEAD_WAITING_QUOTE') {
      return self::render_waiting_quote_body($active);
    }

    if ($ride_state === '' && $quote_status === 'LEAD_ACCEPTED' && !in_array($attempt_status, ['AUTHORIZED', 'SUCCEEDED'], true)) {
      return self::render_waiting_authorization_body($active);
    }

    return self::render_ride_state_body($active, $ride_state);
  }

  /**
   * Render the full active lead panel: stable header + state body.
   */
  public static function render_active_ride_panel(array $active) : string {
    $html  = '<div class="sd-op-card">';
    $html .= '  <div class="sd-op-card-head">';
    $html .= '    <h2>trip-ops</h2>';
    $html .= '    <div class="sd-op-sub">Stable header. State-governed body.</div>';
    $html .= '  </div>';

    if ((int) ($active['lead_id'] ?? 0) <= 0) {
      $html .= '<p>No lead selected.</p>';
      $html .= '</div>';
      return $html;
    }

    $html .= self::render_active_header($active);
    $html .= self::render($active);
    $html .= '</div>';

    return $html;
  }

  /**
   * Shared active lead header block.
   */
  public static function render_active_header(array $active) : string {
    $lead_id    = (int) ($active['lead_id'] ?? 0);
    $quote_id   = (int) ($active['quote_id'] ?? 0);
    $attempt_id = (int) ($active['attempt_id'] ?? 0);
    $ride_id    = (int) ($active['ride_id'] ?? 0);
    $phone      = (string) ($active['customer_phone'] ?? '');
    $sched_ts   = (int) ($active['scheduled_ts'] ?? 0);
    $attempt    = (string) ($active['attempt_status'] ?? '');
    $captured   = (int) ($active['captured_at'] ?? 0);

    $html  = '<div class="sd-op-active-head">';
    $html .= '  <div class="sd-op-active-line"><strong>' . esc_html((string) ($active['customer_name'] !== '' ? $active['customer_name'] : ('Lead #' . $lead_id))) . '</strong>';

    if ($phone !== '') {
      $html .= ' • ' . esc_html($phone);
    }

    $html .= ' • Lead #' . $lead_id;

    if ($quote_id > 0) {
      $html .= ' • Quote #' . $quote_id;
    }

    if ($attempt_id > 0) {
      $html .= ' • Auth #' . $attempt_id;
    }

    if ($ride_id > 0) {
      $html .= ' • Ride #' . $ride_id;
    }

    $html .= '</div>';

    $html .= '  <div class="sd-op-active-line">' . esc_html(trim((string) ($active['pickup_text'] ?? '') . ' → ' . (string) ($active['dropoff_text'] ?? ''))) . '</div>';

    $html .= '  <div class="sd-op-active-line">';
    $html .= 'Lead: ' . esc_html(self::state_label((string) ($active['lead_status'] ?? ''))) . ' • ';
    $html .= 'Quote: ' . esc_html(self::state_label((string) ($active['quote_status'] ?? '')));

    if ($attempt !== '') {
      $html .= ' • Auth: ' . esc_html(self::state_label($attempt));
    }

    if ((string) ($active['ride_state'] ?? '') !== '') {
      $html .= ' • Ride: ' . esc_html(self::state_label((string) ($active['ride_state'] ?? '')));
    }

    $html .= '  </div>';

    $html .= '  <div class="sd-op-active-line">';
    $html .= 'Requested: ' . esc_html(self::human_time((int) ($active['requested_at'] ?? 0))) . ' • ';
    $html .= 'Updated: ' . esc_html(self::human_time((int) ($active['updated_at'] ?? 0)));

    if ($sched_ts > 0) {
      $html .= ' • Scheduled: ' . esc_html(wp_date('M j, g:i a', $sched_ts));
    }

    if ($captured > 0) {
      $html .= ' • Captured: ' . esc_html(self::human_time($captured));
    }

    $html .= '  </div>';

    $html .= '  <div class="sd-op-active-line">';
    $html .= 'Operator: ' . esc_html((string) ($active['operator_status_label'] ?? 'OFFLINE')) . ' • ';
    $html .= 'Live location: ' . esc_html((string) ($active['live_location_label'] ?? 'missing')) . ' • ';
    $html .= 'Last known loc: ' . esc_html((string) ($active['last_known_loc'] ?? '—')) . ' • ';
    $html .= 'Last ping: ' . esc_html((string) ($active['last_ping_ago'] ?? '—')) . ' • ';
    $html .= 'Base location: ' . esc_html((string) ($active['base_location_label'] ?? 'missing'));
    $html .= '  </div>';
    $html .= '</div>';

    return $html;
  }

  // ---------------------------------------------------------------------------
  // Waiting quote body
  // ---------------------------------------------------------------------------

  private static function render_waiting_quote_body(array $active) : string {
    $lead_id          = (int) ($active['lead_id'] ?? 0);
    $quote_id         = (int) ($active['quote_id'] ?? 0);
    $amount_cents     = (int) ($active['quote_total_cents'] ?? 0);
    $currency         = (string) ($active['quote_currency'] ?? 'usd');
    $pickup_eta_min   = (int) ($active['pickup_eta_min'] ?? 0);
    $confidence       = (string) ($active['confidence_label'] ?? 'Missing deadhead');
    $miles_to_pickup  = (float) ($active['miles_to_pickup'] ?? 0.0);
    $trip_miles       = (float) ($active['trip_miles'] ?? 0.0);
    $trip_mins        = (int) ($active['trip_mins'] ?? 0);
    $tot_per_60       = (float) ($active['tot_per_60'] ?? 0.0);
    $tot_per_mile     = (float) ($active['tot_per_mile'] ?? 0.0);

    $html  = '<div class="sd-op-state-box is-alert">';
    $html .= '  <h3>Waiting quote</h3>';
    $html .= '  <p>Review, adjust if needed, then approve and present the quote to the rider.</p>';

    $html .= '  <div class="sd-op-active-head" style="margin-bottom:12px">';
    $html .= '    <div class="sd-op-active-line"><strong>Draft total:</strong> ' . esc_html(self::format_money($amount_cents, $currency)) . '</div>';
    $html .= '    <div class="sd-op-active-line"><strong>Pickup ETA:</strong> ' . esc_html((string) $pickup_eta_min) . ' min</div>';
    $html .= '    <div class="sd-op-active-line"><strong>Confidence:</strong> ' . esc_html($confidence) . '</div>';
    $html .= '    <div class="sd-op-active-line"><strong>Miles to pickup:</strong> ' . esc_html(number_format($miles_to_pickup, 1)) . '</div>';
    $html .= '    <div class="sd-op-active-line"><strong>Trip miles:</strong> ' . esc_html(number_format($trip_miles, 1)) . '</div>';
    $html .= '    <div class="sd-op-active-line"><strong>Trip mins:</strong> ' . esc_html((string) $trip_mins) . '</div>';
    $html .= '    <div class="sd-op-active-line"><strong>Tot$/60min:</strong> ' . esc_html('$' . number_format($tot_per_60, 2)) . '</div>';
    $html .= '    <div class="sd-op-active-line"><strong>Tot$/mile:</strong> ' . esc_html('$' . number_format($tot_per_mile, 2)) . '</div>';
    $html .= '  </div>';

    $html .= '  <div class="sd-op-cta-row" style="margin-bottom:12px">';
    $html .= self::post_button('sd_operator_quote_approve', $lead_id, $quote_id, 'Approve', true);
    $html .= self::post_button('sd_operator_quote_recalculate', $lead_id, $quote_id, 'Recalculate', false);
    $html .= '  </div>';

    $html .= '  <div class="sd-op-active-head" style="margin-bottom:12px">';
    $html .= '    <div class="sd-op-active-line"><strong>Adjust price</strong></div>';
    $html .= '    <div class="sd-op-cta-row">';

    foreach ([-10, -5, 5, 10] as $pct) {
      $label = ($pct > 0 ? '+' : '') . $pct . '%';
      $html .= self::post_button('sd_operator_quote_adjust_percent', $lead_id, $quote_id, $label, false, [
        'delta_percent' => $pct,
      ]);
    }

    $html .= '    </div>';
    $html .= '    <div class="sd-op-active-line" style="margin-top:10px"><strong>Adjust pickup ETA</strong></div>';
    $html .= '    <div class="sd-op-cta-row">';

    foreach ([-5, 5] as $mins) {
      $label = ($mins > 0 ? '+' : '') . $mins . ' min';
      $html .= self::post_button('sd_operator_quote_adjust_eta', $lead_id, $quote_id, $label, false, [
        'delta_minutes' => $mins,
      ]);
    }

    $html .= '    </div>';
    $html .= '  </div>';

    $html .= '  <div class="sd-op-cta-row">';
    $html .= self::post_button('sd_operator_quote_present_adjusted', $lead_id, $quote_id, 'Present adjusted', false);
    $html .= self::post_button('sd_operator_quote_reject', $lead_id, $quote_id, 'Reject', false, [
      'decision_note' => 'Rejected from operator trips surface',
    ]);
    $html .= '  </div>';
    $html .= '</div>';

    return $html;
  }

  // ---------------------------------------------------------------------------
  // Waiting auth body
  // ---------------------------------------------------------------------------

  private static function render_waiting_authorization_body(array $active) : string {
    $lead_id       = (int) ($active['lead_id'] ?? 0);
    $quote_id      = (int) ($active['quote_id'] ?? 0);
    $attempt_id    = (int) ($active['attempt_id'] ?? 0);
    $attempt_state = (string) ($active['attempt_status'] ?? '');

    $html  = '<div class="sd-op-state-box is-alert">';
    $html .= '  <h3>Authorization pending</h3>';
    $html .= '  <p>The rider has accepted the offer. Authorization must succeed before the ride is created.</p>';

    $html .= '  <div class="sd-op-active-head" style="margin-bottom:12px">';
    if ($quote_id > 0) {
      $html .= '    <div class="sd-op-active-line"><strong>Quote:</strong> #' . (int) $quote_id . '</div>';
    }
    if ($attempt_id > 0) {
      $html .= '    <div class="sd-op-active-line"><strong>Auth attempt:</strong> #' . (int) $attempt_id . '</div>';
    }
    $html .= '    <div class="sd-op-active-line"><strong>Status:</strong> ' . esc_html(self::state_label($attempt_state !== '' ? $attempt_state : 'pending')) . '</div>';
    $html .= '  </div>';

    $html .= '  <div class="sd-op-cta-row">';
    $html .= self::lead_action_button('sd_operator_open_authorization', $lead_id, 'Open authorization', true, [
      'quote_id'   => $quote_id,
      'attempt_id' => $attempt_id,
    ]);
    $html .= '  </div>';
    $html .= '</div>';

    return $html;
  }

  // ---------------------------------------------------------------------------
  // Ride-state body
  // ---------------------------------------------------------------------------

  private static function render_ride_state_body(array $active, string $ride_state) : string {
    $lead_id       = (int) ($active['lead_id'] ?? 0);
    $ride_id       = (int) ($active['ride_id'] ?? 0);
    $capture_error = (string) ($active['capture_error'] ?? '');
    $lead_status   = (string) ($active['lead_status'] ?? '');
    $quote_status  = (string) ($active['quote_status'] ?? '');
    $attempt_status = (string) ($active['attempt_status'] ?? '');

    $html  = '<div class="sd-op-state-box">';
    $html .= '  <h3>' . esc_html(self::state_body_title($lead_status, $quote_status, $attempt_status, $ride_state)) . '</h3>';
    $html .= '  <p>Only the next allowed action is shown.</p>';

    if ($capture_error !== '') {
      $html .= '<div class="sd-op-active-head" style="margin-bottom:12px;border-color:#fecaca;background:#fef2f2">';
      $html .= '<div class="sd-op-active-line"><strong>Capture issue:</strong> ' . esc_html($capture_error) . '</div>';
      $html .= '</div>';
    }

    $html .= '  <div class="sd-op-cta-row">';

    if ($ride_state !== '' && $ride_id > 0) {
      foreach (self::ride_progress_actions($ride_state) as $action) {
        $html .= self::ride_progress_button(
          $lead_id,
          $ride_id,
          (string) $action['to_state'],
          (string) $action['label'],
          !empty($action['primary'])
        );
      }
    } else {
      foreach (self::lead_progress_actions($lead_status, $quote_status, $attempt_status, $ride_state) as $action) {
        $html .= self::lead_action_button(
          (string) ($action['action_name'] ?? 'sd_operator_open_lead'),
          $lead_id,
          (string) ($action['label'] ?? 'Open'),
          !empty($action['primary']),
          (array) ($action['extra'] ?? [])
        );
      }
    }

    $html .= '  </div>';
    $html .= '</div>';

    return $html;
  }

  // ---------------------------------------------------------------------------
  // Shared helpers
  // ---------------------------------------------------------------------------

  private static function post_button(string $action_name, int $lead_id, int $quote_id, string $label, bool $primary = false, array $extra = []) : string {
    $class = 'sd-op-btn' . ($primary ? ' sd-op-btn-primary' : '');

    $html  = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;">';
    $html .= wp_nonce_field('sd_operator_trip_action', '_wpnonce', true, false);
    $html .= '<input type="hidden" name="action" value="' . esc_attr($action_name) . '">';
    $html .= '<input type="hidden" name="lead_id" value="' . (int) $lead_id . '">';

    if ($quote_id > 0) {
      $html .= '<input type="hidden" name="quote_id" value="' . (int) $quote_id . '">';
    }

    foreach ($extra as $key => $value) {
      $html .= '<input type="hidden" name="' . esc_attr((string) $key) . '" value="' . esc_attr((string) $value) . '">';
    }

    $html .= '<button class="' . esc_attr($class) . '" type="submit">' . esc_html($label) . '</button>';
    $html .= '</form>';

    return $html;
  }

  private static function lead_action_button(string $action_name, int $lead_id, string $label, bool $primary = false, array $extra = []) : string {
    $class = 'sd-op-btn' . ($primary ? ' sd-op-btn-primary' : '');

    $html  = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;">';
    $html .= wp_nonce_field('sd_operator_trip_action', '_wpnonce', true, false);
    $html .= '<input type="hidden" name="action" value="' . esc_attr($action_name) . '">';
    $html .= '<input type="hidden" name="lead_id" value="' . (int) $lead_id . '">';

    foreach ($extra as $key => $value) {
      if ($value === '' || $value === null) {
        continue;
      }
      $html .= '<input type="hidden" name="' . esc_attr((string) $key) . '" value="' . esc_attr((string) $value) . '">';
    }

    $html .= '<button class="' . esc_attr($class) . '" type="submit">' . esc_html($label) . '</button>';
    $html .= '</form>';

    return $html;
  }

  private static function ride_progress_button(int $lead_id, int $ride_id, string $to_state, string $label, bool $primary = false) : string {
    $class = 'sd-op-btn' . ($primary ? ' sd-op-btn-primary' : '');

    $html  = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:0;">';
    $html .= wp_nonce_field('sd_operator_trip_action', '_wpnonce', true, false);
    $html .= '<input type="hidden" name="action" value="sd_operator_ride_progress">';
    $html .= '<input type="hidden" name="lead_id" value="' . (int) $lead_id . '">';
    $html .= '<input type="hidden" name="ride_id" value="' . (int) $ride_id . '">';
    $html .= '<input type="hidden" name="to_state" value="' . esc_attr($to_state) . '">';
    $html .= '<button class="' . esc_attr($class) . '" type="submit">' . esc_html($label) . '</button>';
    $html .= '</form>';

    return $html;
  }

  private static function state_label(string $state) : string {
    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'display_state_label')) {
      return SD_Module_OperatorUI::display_state_label($state);
    }

    return $state !== '' ? $state : '—';
  }

  private static function human_time(int $ts) : string {
    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'human_time')) {
      return SD_Module_OperatorUI::human_time($ts);
    }

    if ($ts <= 0) return '—';
    return human_time_diff($ts, time()) . ' ago';
  }

  private static function format_money(int $amount_cents, string $currency = 'usd') : string {
    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'format_money')) {
      return SD_Module_OperatorUI::format_money($amount_cents, $currency);
    }

    if ($amount_cents <= 0) {
      return '— ' . strtoupper($currency !== '' ? $currency : 'usd');
    }

    return '$' . number_format($amount_cents / 100, 2) . ' ' . strtoupper($currency !== '' ? $currency : 'usd');
  }

  private static function state_body_title(string $lead_status, string $quote_status, string $attempt_status, string $ride_state) : string {
    if (class_exists('SD_Module_OperatorActiveRide', false) && method_exists('SD_Module_OperatorActiveRide', 'lead_state_title')) {
      return SD_Module_OperatorActiveRide::lead_state_title($lead_status, $quote_status, $attempt_status, $ride_state);
    }

    if ($ride_state !== '') {
      return self::ride_state_title($ride_state);
    }

    return $lead_status !== '' ? $lead_status : 'Open lead';
  }

  private static function ride_state_title(string $ride_state) : string {
    if (class_exists('SD_Module_OperatorActiveRide', false) && method_exists('SD_Module_OperatorActiveRide', 'ride_state_title')) {
      return SD_Module_OperatorActiveRide::ride_state_title($ride_state);
    }

    return $ride_state !== '' ? $ride_state : 'Open ride';
  }

  private static function ride_progress_actions(string $ride_state) : array {
    if (class_exists('SD_Module_OperatorActiveRide', false) && method_exists('SD_Module_OperatorActiveRide', 'ride_progress_actions')) {
      return SD_Module_OperatorActiveRide::ride_progress_actions($ride_state);
    }

    return [];
  }

  private static function lead_progress_actions(string $lead_status, string $quote_status, string $attempt_status, string $ride_state) : array {
    if (class_exists('SD_Module_OperatorActiveRide', false) && method_exists('SD_Module_OperatorActiveRide', 'lead_progress_actions')) {
      $actions = SD_Module_OperatorActiveRide::lead_progress_actions($lead_status, $quote_status, $attempt_status, $ride_state);

      $normalized = [];
      foreach ($actions as $action) {
        $action_key = (string) ($action['action'] ?? '');
        $action_name = 'sd_operator_open_lead';

        switch ($action_key) {
          case 'review_quote':
            $action_name = 'sd_operator_open_quote';
            break;
          case 'review_authorization':
            $action_name = 'sd_operator_open_authorization';
            break;
          case 'open_dispatch':
            $action_name = 'sd_operator_open_dispatch';
            break;
        }

        $normalized[] = [
          'label'       => (string) ($action['label'] ?? 'Open'),
          'action_name' => $action_name,
          'primary'     => !empty($action['primary']),
          'extra'       => (array) ($action['extra'] ?? []),
        ];
      }

      return $normalized;
    }

    return [];
  }
}