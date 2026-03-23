<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_Admin_LeadMeta
 *
 * Purpose:
 * - Read-only payload viewer for sd_lead
 * - Shows canonical engagement snapshot
 *
 * Rules:
 * - NO editing here
 * - Debug + visibility only
 */

final class SD_Module_Admin_LeadMeta {

  public static function register() : void {
    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
  }

  public static function add_metabox() : void {
    add_meta_box(
      'sd_lead_payload',
      'Lead Payload (Debug)',
      [__CLASS__, 'render'],
      SD_Module_LeadCPT::CPT,
      'normal',
      'default'
    );
  }

  public static function render(\WP_Post $post) : void {

    $id = (int) $post->ID;

    $get = function($key) use ($id) {
      return get_post_meta($id, $key, true);
    };

    echo '<div style="font-family:monospace;font-size:13px;">';

    self::section('Core Identity', [
      'lead_id'   => $id,
      'tenant_id' => $get(SD_Meta::TENANT_ID),
      'token'     => $get(SD_Meta::TRIP_TOKEN),
      'status'    => $get(SD_Meta::LEAD_STATUS),
    ]);

    self::section('Route', [
      'pickup_text'       => $get(SD_Meta::PICKUP_TEXT),
      'dropoff_text'      => $get(SD_Meta::DROPOFF_TEXT),
      'pickup_place_id'   => $get(SD_Meta::PICKUP_PLACE_ID),
      'dropoff_place_id'  => $get(SD_Meta::DROPOFF_PLACE_ID),
      'pickup_lat/lng'    => self::pair($get(SD_Meta::PICKUP_LAT), $get(SD_Meta::PICKUP_LNG)),
      'dropoff_lat/lng'   => self::pair($get(SD_Meta::DROPOFF_LAT), $get(SD_Meta::DROPOFF_LNG)),
    ]);

    self::section('Request', [
      'mode'          => $get(SD_Meta::REQUEST_MODE),
      'requested_ts'  => self::ts($get(SD_Meta::REQUESTED_TS)),
      'scheduled_ts'  => self::ts($get(SD_Meta::PICKUP_SCHEDULED_TS)),
      'date'          => $get(SD_Meta::REQUESTED_DATE),
      'time'          => $get(SD_Meta::REQUESTED_TIME),
    ]);

    self::section('Customer', [
      'name'  => $get(SD_Meta::CUSTOMER_NAME),
      'phone' => $get(SD_Meta::CUSTOMER_PHONE),
      'notes' => $get(SD_Meta::RESERVE_NOTES),
    ]);

    self::section('Pipeline Pointers', [
      'availability'      => $get(SD_Meta::AVAILABILITY_STATUS),
      'current_quote_id'  => $get(SD_Meta::CURRENT_QUOTE_ID),
      'current_attempt_id'=> $get(SD_Meta::CURRENT_ATTEMPT_ID),
      'promoted_ride_id'  => $get(SD_Meta::PROMOTED_RIDE_ID),
    ]);

    self::section('Debug', [
      'form_snapshot' => self::json($get(SD_Meta::P_FORM_SNAPSHOT_JSON)),
    ]);

    echo '</div>';
  }

  // ---------------------------------------------------------------------

  private static function section(string $title, array $rows) : void {
    echo '<div style="margin-bottom:16px;">';
    echo '<strong style="display:block;margin-bottom:6px;">' . esc_html($title) . '</strong>';
    echo '<table style="width:100%;border-collapse:collapse;">';

    foreach ($rows as $k => $v) {
      echo '<tr>';
      echo '<td style="padding:4px 8px;border-bottom:1px solid #eee;width:220px;">' . esc_html($k) . '</td>';
      echo '<td style="padding:4px 8px;border-bottom:1px solid #eee;">' . esc_html((string) $v) . '</td>';
      echo '</tr>';
    }

    echo '</table>';
    echo '</div>';
  }

  private static function pair($a, $b) : string {
    if (!$a && !$b) return '';
    return $a . ', ' . $b;
  }

  private static function ts($ts) : string {
    if (!$ts) return '';
    return gmdate('Y-m-d H:i:s', (int)$ts) . ' UTC';
  }

  private static function json($value) : string {
    if (!$value) return '';
    $decoded = json_decode((string)$value, true);
    if (!is_array($decoded)) return (string)$value;

    return wp_json_encode($decoded, JSON_PRETTY_PRINT);
  }
}

