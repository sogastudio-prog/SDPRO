<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Route_Service (v1.1)
 *
 * Purpose:
 * - Platform-wide routing primitive used by:
 *   - quote input builders (TripRouteInputs)
 *   - UI tools (route inputs UI, admin tools)
 *   - any future intake/ops workflows that need meters/seconds
 *
 * Doctrine:
 * - Never exposes server keys to browser.
 * - Returns numeric logistics only (meters/seconds).
 * - Tenant-aware (tenant override key allowed).
 * - Logs fail-soft diagnostics so route failures can be traced quickly.
 */
// Quote routing origin priority:
// 1. tenant last known location (fresh enough)
// 2. tenant base location
// 3. fail soft
// NOT driver last known location ...
final class SD_Route_Service {

  private const PLATFORM_ROUTES_KEY_CONST = 'SD_GOOGLE_ROUTES_SERVER_KEY';

  public static function compute_leg(array $origin, array $dest, array $opts = []) : ?array {
    $tenant_id = isset($opts['tenant_id']) ? (int) $opts['tenant_id'] : 0;
    $timeout   = isset($opts['timeout']) ? max(3, (int) $opts['timeout']) : 8;

    $key = self::get_google_routes_server_key($tenant_id);
    if ($key === '') {
      self::dbg('route_compute_no_key', [
        'tenant_id' => $tenant_id,
      ]);
      return null;
    }

    $origin_payload = self::routes_point($origin);
    $dest_payload   = self::routes_point($dest);

    if (empty($origin_payload) || empty($dest_payload)) {
      self::dbg('route_compute_invalid_point', [
        'tenant_id' => $tenant_id,
        'origin'    => $origin,
        'dest'      => $dest,
      ]);
      return null;
    }

    $endpoint = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    $body = [
      'origin'                   => $origin_payload,
      'destination'              => $dest_payload,
      'travelMode'               => 'DRIVE',
      'routingPreference'        => 'TRAFFIC_AWARE',
      'computeAlternativeRoutes' => false,
      'languageCode'             => 'en-US',
      'units'                    => 'IMPERIAL',
    ];

    $resp = wp_remote_post($endpoint, [
      'timeout' => $timeout,
      'headers' => [
        'Content-Type'     => 'application/json',
        'X-Goog-Api-Key'   => $key,
        'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration',
      ],
      'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($resp)) {
      self::dbg('route_compute_wp_error', [
        'tenant_id' => $tenant_id,
        'message'   => $resp->get_error_message(),
        'origin'    => self::debug_point($origin),
        'dest'      => self::debug_point($dest),
      ]);
      return null;
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $raw  = (string) wp_remote_retrieve_body($resp);

    if ($code < 200 || $code >= 300) {
      self::dbg('route_compute_http_error', [
        'tenant_id' => $tenant_id,
        'http_code' => $code,
        'body'      => self::truncate($raw, 1200),
        'origin'    => self::debug_point($origin),
        'dest'      => self::debug_point($dest),
      ]);
      return null;
    }

    if ($raw === '') {
      self::dbg('route_compute_empty_body', [
        'tenant_id' => $tenant_id,
        'http_code' => $code,
        'origin'    => self::debug_point($origin),
        'dest'      => self::debug_point($dest),
      ]);
      return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
      self::dbg('route_compute_bad_json', [
        'tenant_id' => $tenant_id,
        'http_code' => $code,
        'body'      => self::truncate($raw, 1200),
      ]);
      return null;
    }

    if (empty($json['routes'][0])) {
      self::dbg('route_compute_no_routes', [
        'tenant_id' => $tenant_id,
        'http_code' => $code,
        'body'      => self::truncate($raw, 1200),
        'origin'    => self::debug_point($origin),
        'dest'      => self::debug_point($dest),
      ]);
      return null;
    }

    $r = $json['routes'][0];

    $meters = isset($r['distanceMeters']) ? (int) $r['distanceMeters'] : 0;

    $seconds = 0;
    if (isset($r['duration']) && is_string($r['duration'])) {
      $seconds = (int) round((float) rtrim($r['duration'], 's'));
    }

    if ($meters <= 0 && $seconds <= 0) {
      self::dbg('route_compute_zero_result', [
        'tenant_id' => $tenant_id,
        'origin'    => self::debug_point($origin),
        'dest'      => self::debug_point($dest),
        'body'      => self::truncate($raw, 1200),
      ]);
    }

    return [
      'meters'  => max(0, $meters),
      'seconds' => max(0, $seconds),
    ];
  }

  // ---------------------------------------------------------------------------
  // Point helpers
  // ---------------------------------------------------------------------------

  public static function point_from_place_id(string $place_id) : ?array {
    $place_id = trim($place_id);
    if ($place_id === '') return null;
    return ['place_id' => $place_id];
  }

  public static function point_from_latlng($lat, $lng) : ?array {
    $lat = (float) $lat;
    $lng = (float) $lng;
    if (!self::has_point($lat, $lng)) return null;
    return ['lat' => $lat, 'lng' => $lng];
  }

  private static function has_point(float $lat, float $lng) : bool {
    return (abs($lat) > 0.0001 && abs($lng) > 0.0001);
  }

  private static function routes_point(array $p) : array {
    $pid = isset($p['place_id']) ? trim((string) $p['place_id']) : '';
    if ($pid !== '') {
      return ['placeId' => $pid];
    }

    $lat = isset($p['lat']) ? (float) $p['lat'] : 0.0;
    $lng = isset($p['lng']) ? (float) $p['lng'] : 0.0;

    if (self::has_point($lat, $lng)) {
      return [
        'location' => [
          'latLng' => [
            'latitude'  => $lat,
            'longitude' => $lng,
          ],
        ],
      ];
    }

    return [];
  }

  // ---------------------------------------------------------------------------
  // Key resolution
  // ---------------------------------------------------------------------------

  private static function get_google_routes_server_key(int $tenant_id = 0) : string {
    if ($tenant_id > 0 && class_exists('SD_Meta')) {
      $t = trim((string) get_post_meta($tenant_id, SD_Meta::GOOGLE_ROUTES_SERVER_KEY, true));
      if ($t !== '') return $t;
    }

    if (defined(self::PLATFORM_ROUTES_KEY_CONST)) {
      $v = constant(self::PLATFORM_ROUTES_KEY_CONST);
      if (is_string($v) && trim($v) !== '') return trim($v);
    }

    return '';
  }

  // ---------------------------------------------------------------------------
  // Debug helpers
  // ---------------------------------------------------------------------------

private static function debug_enabled() : bool {
  return (defined('SD_ROUTE_DEBUG') && SD_ROUTE_DEBUG)
    || (defined('SD_INTAKE_DEBUG') && SD_INTAKE_DEBUG)
    || (defined('WP_DEBUG') && WP_DEBUG);
}

private static function dbg(string $event, array $ctx = []) : void {
  if (!self::debug_enabled()) return;
  if (!function_exists('error_log')) return;

  error_log('[solodrive] ' . wp_json_encode([
    'sd'    => true,
    'event' => $event,
    'ts'    => gmdate('c'),
    'ctx'   => $ctx,
  ]));
}

  private static function debug_point(array $p) : array {
    if (!empty($p['place_id'])) {
      return ['place_id' => (string) $p['place_id']];
    }
    return [
      'lat' => isset($p['lat']) ? (float) $p['lat'] : 0.0,
      'lng' => isset($p['lng']) ? (float) $p['lng'] : 0.0,
    ];
  }

  private static function truncate(string $s, int $max = 1200) : string {
    $s = trim($s);
    if (strlen($s) <= $max) return $s;
    return substr($s, 0, $max) . '…';
  }
}