<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_RouteCompute (v1.1)
 *
 * Purpose:
 * - Server-side AJAX wrapper around SD_Route_Service::compute_leg()
 * - Returns only token-safe logistics: meters + seconds
 *
 * Improvements:
 * - If origin is not provided, automatically resolve from:
 *     1) tenant live operator location (if fresh)
 *     2) tenant base location
 *
 * Security:
 * - Requires nonce.
 * - Requires tenant resolution.
 */

final class SD_Module_RouteCompute {

  private const ACTION = 'sd_route_compute_leg';
  private const NONCE  = 'sd_route_compute_leg';

  public static function register() : void {
    add_action('wp_ajax_' . self::ACTION,        [__CLASS__, 'handle']);
    add_action('wp_ajax_nopriv_' . self::ACTION, [__CLASS__, 'handle']);
  }

  public static function ajax_action() : string { return self::ACTION; }
  public static function nonce_action() : string { return self::NONCE; }

  public static function handle() : void {

    $nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, self::NONCE)) {
      wp_send_json_error(['message' => 'Unauthorized.'], 403);
    }

    $tenant_id = isset($_POST['tenant_id']) ? (int) $_POST['tenant_id'] : 0;

    if ($tenant_id <= 0 && class_exists('SD_Module_TenantResolver')) {
      $tenant_id = (int) SD_Module_TenantResolver::current_tenant_id();
    }

    if ($tenant_id <= 0) {
      wp_send_json_error(['message' => 'Tenant not resolved.'], 400);
    }

    $origin = self::read_point('origin');
    $dest   = self::read_point('dest');

    if (!$dest) {
      wp_send_json_error(['message' => 'Missing destination.'], 400);
    }

    // If origin missing, resolve from tenant location context
    if (!$origin) {
      $origin = self::tenant_origin_fallback($tenant_id);
    }

    if (!$origin) {
      wp_send_json_error(['message' => 'Origin could not be resolved.'], 400);
    }

    if (!class_exists('SD_Route_Service')) {
      wp_send_json_error(['message' => 'Route service unavailable.'], 500);
    }

    $leg = SD_Route_Service::compute_leg($origin, $dest, [
      'tenant_id' => $tenant_id,
      'timeout'   => 10,
    ]);

    if (!$leg) {
      wp_send_json_error(['message' => 'Route compute failed.'], 502);
    }

    wp_send_json_success([
      'meters'  => (int) ($leg['meters'] ?? 0),
      'seconds' => (int) ($leg['seconds'] ?? 0),
    ]);
  }

  /**
   * Read explicit origin/destination from request.
   */
  private static function read_point(string $prefix) : ?array {

    $pid_key = $prefix . '_place_id';
    $lat_key = $prefix . '_lat';
    $lng_key = $prefix . '_lng';

    $pid = isset($_POST[$pid_key]) ? trim((string) wp_unslash($_POST[$pid_key])) : '';
    if ($pid !== '') {
      return ['place_id' => sanitize_text_field($pid)];
    }

    $lat = isset($_POST[$lat_key]) ? (float) $_POST[$lat_key] : 0.0;
    $lng = isset($_POST[$lng_key]) ? (float) $_POST[$lng_key] : 0.0;

    if (abs($lat) > 0.0001 && abs($lng) > 0.0001) {
      return ['lat' => $lat, 'lng' => $lng];
    }

    return null;
  }

  /**
   * Resolve routing origin from tenant state.
   *
   * Priority:
   * 1) tenant live operator location
   * 2) tenant base location
   */
  /**
   * Resolve routing origin from tenant state.
   *
   * Priority:
   * 1) tenant live operator location
   * 2) tenant base location
   */
  /**
   * Resolve routing origin from tenant state.
   *
   * Priority:
   * 1) tenant live operator location
   * 2) tenant base location
   */
  private static function tenant_origin_fallback(int $tenant_id) : ?array {
    if ($tenant_id <= 0 || !class_exists('SD_Meta')) {
      return null;
    }

    $last_lat = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LAT, true);
    $last_lng = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LNG, true);
    $last_ts  = (int) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_TS, true);

    $base_lat = (float) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LAT, true);
    $base_lng = (float) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LNG, true);

    $has_last = (abs($last_lat) > 0.0001 && abs($last_lng) > 0.0001);
    $fresh    = ($last_ts > 0 && (time() - $last_ts) <= 120);

    if ($has_last && $fresh) {
      return [
        'lat' => $last_lat,
        'lng' => $last_lng,
      ];
    }

    if (abs($base_lat) > 0.0001 && abs($base_lng) > 0.0001) {
      return [
        'lat' => $base_lat,
        'lng' => $base_lng,
      ];
    }

    return null;
  }
}
