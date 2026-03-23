<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_Admin_DispatchBoard (v1.3)
 *
 * Purpose:
 * - Platform-wide dispatch monitoring board (admin only).
 * - Shows cross-tenant activity (no tenant resolution in admin).
 * - Read-only board linking out to canonical edit screens.
 *
 * Canon:
 * - Platform admin sees all tenants.
 * - Admin board is read-only.
 * - Record tenant is read from sd_tenant_id on the record.
 */

final class SD_Module_Admin_DispatchBoard {

  private const PAGE_SLUG = 'sd-dispatch-board';
  private const PER_PAGE  = 30;

  public static function register() : void {
    if (!is_admin()) return;

    add_action('admin_menu', [__CLASS__, 'admin_menu']);
  }

  public static function admin_menu() : void {
    add_menu_page(
      'Dispatch Board',
      'Dispatch',
      'manage_options',
      self::PAGE_SLUG,
      [__CLASS__, 'render_page'],
      'dashicons-clipboard',
      3
    );
  }

  public static function render_page() : void {
    if (!current_user_can('manage_options')) {
      wp_die('Access denied.');
    }

    $ride_cpt = self::ride_cpt();

    $rides = get_posts([
      'post_type'      => $ride_cpt,
      'post_status'    => 'any',
      'posts_per_page' => self::PER_PAGE,
      'orderby'        => 'modified',
      'order'          => 'DESC',
    ]);

    echo '<div class="wrap">';
    echo '<h1>Dispatch Board</h1>';
    echo '<p class="description">Platform-wide activity view. Read-only.</p>';

    echo '<div style="margin:12px 0;padding:10px 12px;border:1px solid #ccd0d4;background:#fff;">';
    echo '<strong>Bound CPTs:</strong> ';
    echo 'ride=' . esc_html($ride_cpt) . ' | quote=' . esc_html(self::quote_cpt()) . ' | attempt=' . esc_html(self::attempt_cpt());
    echo '</div>';

    if (empty($rides)) {
      echo '<div class="notice notice-info"><p>No rides found.</p></div>';
      echo '</div>';
      return;
    }

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:16px;align-items:start;">';

    foreach ($rides as $ride) {
      self::render_ride_card($ride);
    }

    echo '</div>';
    echo '</div>';
  }

  private static function render_ride_card(\WP_Post $ride) : void {
    $ride_id = (int) $ride->ID;

    $tenant_id    = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    $tenant_label = self::tenant_label($tenant_id);

    $ride_state  = (string) get_post_meta($ride_id, SD_Meta::RIDE_STATE, true);
    $lead_status = (string) get_post_meta($ride_id, SD_Meta::LEAD_STATUS, true);

    $pickup  = (string) get_post_meta($ride_id, SD_Meta::PICKUP_TEXT, true);
    $dropoff = (string) get_post_meta($ride_id, SD_Meta::DROPOFF_TEXT, true);

    $quote_id = self::ride_quote_id($ride_id);
    $quote_status = $quote_id > 0
      ? (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true)
      : '—';

    $requested_ago = self::time_ago_from_mysql($ride->post_date_gmt ?: $ride->post_date);
    $updated_ago   = self::time_ago_from_mysql($ride->post_modified_gmt ?: $ride->post_modified);

    $edit_url  = get_edit_post_link($ride_id, '');
    $quote_url = $quote_id > 0 ? get_edit_post_link($quote_id, '') : '';

    echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:14px;box-shadow:0 1px 2px rgba(0,0,0,.04);">';

    echo '<div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">';
    echo '<div>';
    echo '<div style="font-weight:600;font-size:14px;">Ride #' . (int) $ride_id . '</div>';
    echo '<div style="color:#50575e;font-size:12px;margin-top:2px;">Tenant: ' . esc_html($tenant_label) . '</div>';
    echo '</div>';

    echo '<div style="text-align:right;">';
    if ($edit_url) {
      echo '<a class="button button-small" href="' . esc_url($edit_url) . '">Open Ride</a>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div style="margin-top:12px;font-size:13px;line-height:1.45;">';

    echo '<div><strong>Ride state:</strong> <code>' . esc_html($ride_state !== '' ? $ride_state : '—') . '</code></div>';
    echo '<div style="margin-top:4px;"><strong>Lead status:</strong> <code>' . esc_html($lead_status !== '' ? $lead_status : '—') . '</code></div>';
    echo '<div style="margin-top:4px;"><strong>Quote status:</strong> <code>' . esc_html($quote_status !== '' ? $quote_status : '—') . '</code>';
    if ($quote_id > 0 && $quote_url) {
      echo ' <a href="' . esc_url($quote_url) . '">#' . (int) $quote_id . '</a>';
    }
    echo '</div>';

    echo '<div style="margin-top:10px;"><strong>Requested:</strong> ' . esc_html($requested_ago) . '</div>';
    echo '<div style="margin-top:4px;"><strong>Updated:</strong> ' . esc_html($updated_ago) . '</div>';

    echo '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #f0f0f1;">';
    echo '<div><strong>Pickup:</strong> ' . esc_html($pickup !== '' ? $pickup : '—') . '</div>';
    echo '<div style="margin-top:4px;"><strong>Dropoff:</strong> ' . esc_html($dropoff !== '' ? $dropoff : '—') . '</div>';
    echo '</div>';

    echo '</div>';
    echo '</div>';
  }

  private static function ride_quote_id(int $ride_id) : int {
    $quote_id = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($quote_id > 0) {
      return $quote_id;
    }

    $q = get_posts([
      'post_type'      => self::quote_cpt(),
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::RIDE_ID,
        'value'   => (string) $ride_id,
        'compare' => '=',
      ]],
    ]);

    return !empty($q) ? (int) $q[0] : 0;
  }

  private static function tenant_label(int $tenant_id) : string {
    if ($tenant_id <= 0) {
      return 'Unscoped';
    }

    $title = get_the_title($tenant_id);
    if (is_string($title) && $title !== '') {
      return $title . ' (#' . $tenant_id . ')';
    }

    return 'Tenant #' . $tenant_id;
  }

  private static function time_ago_from_mysql(string $mysql) : string {
    if ($mysql === '' || $mysql === '0000-00-00 00:00:00') {
      return '—';
    }

    $ts = strtotime($mysql);
    if (!$ts) {
      return '—';
    }

    return human_time_diff($ts, current_time('timestamp', true)) . ' ago';
  }

  private static function ride_cpt() : string {
    if (class_exists('SD_Module_RideCPT') && defined('SD_Module_RideCPT::CPT')) {
      return (string) SD_Module_RideCPT::CPT;
    }
    if (class_exists('SD_CPT_Ride') && defined('SD_CPT_Ride::CPT')) {
      return (string) SD_CPT_Ride::CPT;
    }
    return 'sd_ride';
  }

  private static function quote_cpt() : string {
    if (class_exists('SD_Module_QuoteCPT') && defined('SD_Module_QuoteCPT::CPT')) {
      return (string) SD_Module_QuoteCPT::CPT;
    }
    if (class_exists('SD_CPT_Quote') && defined('SD_CPT_Quote::CPT')) {
      return (string) SD_CPT_Quote::CPT;
    }
    return 'sd_quote';
  }

  private static function attempt_cpt() : string {
    if (class_exists('SD_Module_AttemptCPT') && defined('SD_Module_AttemptCPT::CPT')) {
      return (string) SD_Module_AttemptCPT::CPT;
    }
    return 'sd_attempt';
  }
}

SD_Module_Admin_DispatchBoard::register();