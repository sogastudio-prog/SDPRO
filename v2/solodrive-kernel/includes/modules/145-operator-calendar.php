<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorCalendar (v0.1)
 *
 * Purpose:
 * - Render a minimal private calendar/list surface for scheduled and reserved rides
 * - Keep future reservations visible outside Drive Mode queue
 *
 * Canon:
 * - This is a visibility surface, not a scheduling engine
 * - Reserved rides may stay out of /operator/trips/ queue until execution horizon
 * - /operator/calendar/ should still show them
 */

if (class_exists('SD_Module_OperatorCalendar', false)) { return; }

final class SD_Module_OperatorCalendar {

  private const DEFAULT_LOOKAHEAD_DAYS = 30;
  private const MAX_ITEMS = 100;

  public static function render_page() : void {
    status_header(200);

    if (!is_user_logged_in()) {
      if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'render_login_screen')) {
        SD_Module_OperatorUI::render_login_screen('Operator Login', '/operator/calendar/');
        return;
      }

      self::render_fallback_login('/operator/calendar/');
      return;
    }

    $tenant_id = self::current_user_tenant_id();

    if ($tenant_id <= 0) {
      $body = '<div class="sd-op-wrap"><div class="sd-op-card"><h2>SoloDrive</h2><p>Your user is not assigned to a tenant yet.</p></div></div>';
      self::render_shell('Calendar', $body);
      return;
    }

    if (!self::current_user_can_operator_surface()) {
      $body = '<div class="sd-op-wrap"><div class="sd-op-card"><h2>Access denied</h2><p>Your account does not have operator access.</p></div></div>';
      self::render_shell('Calendar', $body);
      return;
    }

    $rides = self::calendar_items_for_tenant($tenant_id);

    $html  = '<div class="sd-op-wrap">';
    $html .= '  <div class="sd-op-head">';
    $html .= '    <div>';
    $html .= '      <div class="sd-op-kicker">Calendar</div>';
    $html .= '      <h1>' . esc_html(wp_get_current_user()->display_name ?: 'Operator') . '</h1>';
    $html .= '      <div class="sd-op-sub">Tenant #' . (int) $tenant_id . '</div>';
    $html .= '    </div>';
    $html .= '    <div class="sd-op-cta-row">';
    $html .= '      <a class="sd-op-btn" href="' . esc_url(home_url('/operator/')) . '">Tenant Home</a>';
    $html .= '      <a class="sd-op-btn sd-op-btn-primary" href="' . esc_url(home_url('/operator/trips/')) . '">Drive Mode</a>';
    $html .= '    </div>';
    $html .= '  </div>';

    $html .= '  <div class="sd-op-card">';
    $html .= '    <div class="sd-op-card-head">';
    $html .= '      <h2>Upcoming scheduled rides</h2>';
    $html .= '      <div class="sd-op-sub">Reserved and scheduled rides are listed here even when they are outside the Drive Mode queue horizon.</div>';
    $html .= '    </div>';

    if (empty($rides)) {
      $html .= '<p>No upcoming scheduled rides found.</p>';
      $html .= '  </div>';
      $html .= '</div>';
      self::render_shell('Calendar', $html);
      return;
    }

    $current_day = '';

    foreach ($rides as $item) {
      $day_label = self::day_label((int) $item['service_start_ts']);

      if ($day_label !== $current_day) {
        if ($current_day !== '') {
          $html .= '</div>';
        }
        $current_day = $day_label;
        $html .= '<div class="sd-op-day-group">';
        $html .= '  <div class="sd-op-day-label">' . esc_html($day_label) . '</div>';
      }

      $html .= self::render_calendar_row($item);
    }

    if ($current_day !== '') {
      $html .= '</div>';
    }

    $html .= '  </div>';
    $html .= '</div>';

    self::render_shell('Calendar', $html);
  }

  private static function calendar_items_for_tenant(int $tenant_id) : array {
    $now        = time();
    $window_end = $now + (self::DEFAULT_LOOKAHEAD_DAYS * DAY_IN_SECONDS);

    $ride_ids = get_posts([
      'post_type'      => 'sd_ride',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => self::MAX_ITEMS,
      'orderby'        => 'meta_value_num',
      'order'          => 'ASC',
      'fields'         => 'ids',
      'meta_key'       => SD_Meta::SERVICE_START_TS,
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'     => SD_Meta::TENANT_ID,
          'value'   => $tenant_id,
          'compare' => '=',
          'type'    => 'NUMERIC',
        ],
        [
          'key'     => SD_Meta::SERVICE_START_TS,
          'value'   => $now,
          'compare' => '>=',
          'type'    => 'NUMERIC',
        ],
        [
          'key'     => SD_Meta::SERVICE_START_TS,
          'value'   => $window_end,
          'compare' => '<=',
          'type'    => 'NUMERIC',
        ],
      ],
    ]);

    $items = [];

    foreach ($ride_ids as $ride_id) {
      $ride_id = (int) $ride_id;

      $ride_state = (string) get_post_meta($ride_id, SD_Meta::RIDE_STATE, true);
      if (in_array($ride_state, ['RIDE_COMPLETE', 'RIDE_CANCELLED'], true)) {
        continue;
      }

      $service_start_ts = (int) get_post_meta($ride_id, SD_Meta::SERVICE_START_TS, true);
      if ($service_start_ts <= 0) {
        continue;
      }

      $quote_id = self::latest_quote_id_for_ride($ride_id);

      $items[] = [
        'ride_id'          => $ride_id,
        'quote_id'         => $quote_id,
        'ride_mode'        => (string) get_post_meta($ride_id, SD_Meta::RIDE_MODE, true),
        'ride_state'       => $ride_state,
        'quote_status'     => $quote_id > 0 ? (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true) : '',
        'customer_name'    => (string) get_post_meta($ride_id, SD_Meta::CUSTOMER_NAME, true),
        'customer_phone'   => (string) get_post_meta($ride_id, SD_Meta::CUSTOMER_PHONE, true),
        'pickup_text'      => (string) get_post_meta($ride_id, SD_Meta::PICKUP_TEXT, true),
        'dropoff_text'     => (string) get_post_meta($ride_id, SD_Meta::DROPOFF_TEXT, true),
        'service_start_ts' => $service_start_ts,
        'service_end_ts'   => (int) get_post_meta($ride_id, SD_Meta::SERVICE_END_TS, true),
        'block_conflict'   => (int) get_post_meta($ride_id, SD_Meta::P_BLOCK_CONFLICT, true),
      ];
    }

    return $items;
  }

  private static function render_calendar_row(array $item) : string {
    $ride_id    = (int) ($item['ride_id'] ?? 0);
    $quote_id   = (int) ($item['quote_id'] ?? 0);
    $name       = (string) ($item['customer_name'] ?? '');
    $phone      = (string) ($item['customer_phone'] ?? '');
    $pickup     = (string) ($item['pickup_text'] ?? '');
    $dropoff    = (string) ($item['dropoff_text'] ?? '');
    $ride_mode  = (string) ($item['ride_mode'] ?? '');
    $ride_state = (string) ($item['ride_state'] ?? '');
    $quote_stat = (string) ($item['quote_status'] ?? '');
    $start_ts   = (int) ($item['service_start_ts'] ?? 0);
    $end_ts     = (int) ($item['service_end_ts'] ?? 0);
    $conflict   = !empty($item['block_conflict']);

    $html  = '<div class="sd-op-queue-row">';
    $html .= '  <div class="sd-op-queue-main">';
    $html .= '    <div class="sd-op-queue-name">' . esc_html($name !== '' ? $name : ('Ride #' . $ride_id)) . '</div>';
    $html .= '    <div class="sd-op-queue-route">' . esc_html($pickup) . ' → ' . esc_html($dropoff) . '</div>';
    $html .= '    <div class="sd-op-queue-sub">';
    $html .= '      <strong>' . esc_html(self::time_range_label($start_ts, $end_ts)) . '</strong>';
    if ($phone !== '') {
      $html .= ' • ' . esc_html($phone);
    }
    $html .= '    </div>';
    $html .= '  </div>';

    $html .= '  <div class="sd-op-queue-meta">';
    $html .= '    <div>' . esc_html(self::pretty_enum($ride_mode !== '' ? $ride_mode : 'scheduled')) . '</div>';
    $html .= '    <div>' . esc_html(self::pretty_enum($quote_stat !== '' ? $quote_stat : $ride_state)) . '</div>';
    if ($conflict) {
      $html .= '    <div style="color:#b91c1c;font-weight:700;">Conflict</div>';
    }
    $html .= '    <a class="sd-op-link" href="' . esc_url(home_url('/operator/trips/?ride_id=' . $ride_id . '&tab=trip-ops')) . '">Open trip-ops</a>';
    if ($quote_id > 0) {
      $html .= '    <div class="sd-op-sub">Quote #' . (int) $quote_id . '</div>';
    }
    $html .= '  </div>';
    $html .= '</div>';

    return $html;
  }

  private static function day_label(int $ts) : string {
    if ($ts <= 0) return 'Unknown day';
    return date_i18n('l, F j, Y', $ts);
  }

  private static function time_range_label(int $start_ts, int $end_ts) : string {
    if ($start_ts <= 0) return 'Time unavailable';

    $start = date_i18n('g:i A', $start_ts);
    if ($end_ts > $start_ts) {
      return $start . ' – ' . date_i18n('g:i A', $end_ts);
    }

    return $start;
  }

  private static function pretty_enum(string $value) : string {
    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'pretty_enum')) {
      return SD_Module_OperatorUI::pretty_enum($value);
    }

    if ($value === '') return '';
    return ucwords(str_replace('_', ' ', strtolower($value)));
  }

  private static function latest_quote_id_for_ride(int $ride_id) : int {
    $quote_id = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($quote_id > 0 && get_post_type($quote_id) === 'sd_quote') {
      return $quote_id;
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

    $quote_id = !empty($ids[0]) ? (int) $ids[0] : 0;

    if ($quote_id > 0) {
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, $quote_id);
    }

    return $quote_id;
  }

  private static function current_user_tenant_id() : int {
    if (class_exists('SD_TenantAccess', false) && method_exists('SD_TenantAccess', 'current_user_tenant_id')) {
      $tenant_id = (int) SD_TenantAccess::current_user_tenant_id();
      if ($tenant_id > 0) return $tenant_id;
    }

    return (int) get_user_meta(get_current_user_id(), SD_Meta::TENANT_ID, true);
  }

  private static function current_user_can_operator_surface() : bool {
    if (current_user_can('manage_options')) return true;
    if (current_user_can('sd_operator')) return true;
    if (current_user_can('read')) return true;
    return false;
  }

  private static function render_shell(string $title, string $body_html) : void {
    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'render_shell')) {
      SD_Module_OperatorUI::render_shell($title, $body_html);
      return;
    }

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

  private static function render_fallback_login(string $redirect_path) : void {
    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'render_login_screen')) {
      SD_Module_OperatorUI::render_login_screen('Operator Login', $redirect_path);
      return;
    }

    wp_safe_redirect(wp_login_url(home_url($redirect_path)));
    exit;
  }
}