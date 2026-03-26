<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_StorefrontGate
 *
 * Canonical storefront gating service.
 *
 * Purpose:
 * - Read storefront-facing tenant config from SD_TenantConfig
 * - Decide whether the storefront should render active intake
 * - Provide one stable runtime gate for public storefront behavior
 *
 * Notes:
 * - Read-only service
 * - Safe to load multiple times
 * - Assumes SD_Meta, SD_TenantConfig, and optionally SD_TenantReadiness are loaded
 *
 * Return conventions:
 * - booleans are real booleans in response arrays
 * - mode/state strings are canonical schema values
 * - messages are rider-facing display strings, already selected for current state
 */
if (class_exists('SD_StorefrontGate', false)) { return; }

final class SD_StorefrontGate {

  /**
   * Evaluate storefront state for a tenant.
   *
   * Return shape:
   * [
   *   'tenant_id'                => 123,
   *   'is_ready'                 => true,
   *   'is_enabled'               => true,
   *   'accepting_requests'       => true,
   *   'is_open_now'              => true,
   *   'can_render_storefront'    => true,
   *   'can_render_request_form'  => true,
   *   'can_request_quote'        => true,
   *   'can_request_booking'      => false,
   *   'state'                    => 'open',
   *   'request_mode'             => 'quote_only',
   *   'hours_mode'               => 'always_on',
   *   'timezone'                 => 'America/New_York',
   *   'reason_code'              => 'ok',
   *   'message'                  => '',
   *   'messages'                 => [
   *     'busy'    => '...',
   *     'closed'  => '...',
   *     'current' => '',
   *   ],
   *   'readiness'                => [...],
   *   'config'                   => [...],
   * ]
   */
  public static function evaluate(int $tenant_id) : array {
    $tenant_id = absint($tenant_id);

    if ($tenant_id <= 0) {
      return self::result($tenant_id, [
        'is_ready'                => false,
        'is_enabled'              => false,
        'accepting_requests'      => false,
        'is_open_now'             => false,
        'can_render_storefront'   => false,
        'can_render_request_form' => false,
        'can_request_quote'       => false,
        'can_request_booking'     => false,
        'state'                   => 'closed',
        'request_mode'            => 'quote_only',
        'hours_mode'              => 'always_on',
        'timezone'                => '',
        'reason_code'             => 'invalid_tenant',
        'message'                 => 'Storefront unavailable.',
        'messages'                => [
          'busy'    => '',
          'closed'  => 'Storefront unavailable.',
          'current' => 'Storefront unavailable.',
        ],
        'readiness'               => self::fallback_readiness($tenant_id),
        'config'                  => [],
      ]);
    }

    $config = class_exists('SD_TenantConfig', false)
      ? SD_TenantConfig::storefront($tenant_id)
      : [];

    $state              = self::string_value($config, SD_Meta::STOREFRONT_STATE, 'open');
    $enabled            = self::bool_value($config, SD_Meta::STOREFRONT_ENABLED, true);
    $accepting          = self::bool_value($config, SD_Meta::STOREFRONT_ACCEPTING_REQUESTS, true);
    $request_mode       = self::string_value($config, SD_Meta::STOREFRONT_REQUEST_MODE, 'quote_only');
    $hours_mode         = self::string_value($config, SD_Meta::STOREFRONT_HOURS_MODE, 'always_on');
    $timezone           = self::string_value($config, SD_Meta::STOREFRONT_TIMEZONE, '');
    $requires_quote     = self::bool_value($config, SD_Meta::STOREFRONT_REQUIRES_QUOTE, true);
    $allows_booking     = self::bool_value($config, SD_Meta::STOREFRONT_ALLOWS_IMMEDIATE_BOOKING, false);
    $busy_message       = self::string_value($config, SD_Meta::STOREFRONT_BUSY_MESSAGE, '');
    $closure_message    = self::string_value($config, SD_Meta::STOREFRONT_CLOSURE_MESSAGE, '');

    $readiness = class_exists('SD_TenantReadiness', false)
      ? SD_TenantReadiness::evaluate($tenant_id)
      : self::fallback_readiness($tenant_id);

    $is_ready = !empty($readiness['is_ready']);

    $is_open_now = self::is_open_now($tenant_id, $hours_mode, $timezone);

    $reason_code = 'ok';
    $message     = '';

    $can_render_storefront   = true;
    $can_render_request_form = true;

    if (!$enabled) {
      $reason_code = 'storefront_disabled';
      $message     = 'This storefront is currently unavailable.';
      $can_render_request_form = false;
    } elseif (!$is_ready) {
      $reason_code = 'tenant_not_ready';
      $message     = 'This storefront is not yet ready for live requests.';
      $can_render_request_form = false;
    } elseif ($state === 'closed') {
      $reason_code = 'storefront_closed';
      $message     = ($closure_message !== '') ? $closure_message : 'This storefront is currently closed.';
      $can_render_request_form = false;
    } elseif ($state === 'busy') {
      $reason_code = 'storefront_busy';
      $message     = ($busy_message !== '') ? $busy_message : 'This storefront is currently busy.';
      $can_render_request_form = false;
    } elseif (!$accepting) {
      $reason_code = 'not_accepting_requests';
      $message     = 'This storefront is not accepting requests right now.';
      $can_render_request_form = false;
    } elseif (!$is_open_now) {
      $reason_code = 'outside_hours';
      $message     = 'This storefront is currently outside operating hours.';
      $can_render_request_form = false;
    }

    $can_request_quote   = self::can_request_quote_from_mode($request_mode, $requires_quote);
    $can_request_booking = self::can_request_booking_from_mode($request_mode, $allows_booking);

    if (!$can_render_request_form) {
      $can_request_quote   = false;
      $can_request_booking = false;
    }

    if (!$can_request_quote && !$can_request_booking && $reason_code === 'ok') {
      $reason_code = 'no_request_path';
      $message     = 'This storefront does not currently offer a request path.';
      $can_render_request_form = false;
    }

    return self::result($tenant_id, [
      'is_ready'                => $is_ready,
      'is_enabled'              => $enabled,
      'accepting_requests'      => $accepting,
      'is_open_now'             => $is_open_now,
      'can_render_storefront'   => $can_render_storefront,
      'can_render_request_form' => $can_render_request_form,
      'can_request_quote'       => $can_request_quote,
      'can_request_booking'     => $can_request_booking,
      'state'                   => $state,
      'request_mode'            => $request_mode,
      'hours_mode'              => $hours_mode,
      'timezone'                => $timezone,
      'reason_code'             => $reason_code,
      'message'                 => $message,
      'messages'                => [
        'busy'    => ($busy_message !== '') ? $busy_message : 'This storefront is currently busy.',
        'closed'  => ($closure_message !== '') ? $closure_message : 'This storefront is currently closed.',
        'current' => $message,
      ],
      'readiness'               => $readiness,
      'config'                  => $config,
    ]);
  }

  /**
   * Quick yes/no for storefront intake form rendering.
   */
  public static function can_render_request_form(int $tenant_id) : bool {
    $result = self::evaluate($tenant_id);
    return !empty($result['can_render_request_form']);
  }

  /**
   * Quick yes/no for storefront shell rendering.
   * This is intentionally more permissive than request form rendering.
   */
  public static function can_render_storefront(int $tenant_id) : bool {
    $result = self::evaluate($tenant_id);
    return !empty($result['can_render_storefront']);
  }

  /**
   * Current canonical request mode.
   */
  public static function request_mode(int $tenant_id) : string {
    $result = self::evaluate($tenant_id);
    return (string) ($result['request_mode'] ?? 'quote_only');
  }

  /**
   * Convenience: should storefront present a quote path?
   */
  public static function can_request_quote(int $tenant_id) : bool {
    $result = self::evaluate($tenant_id);
    return !empty($result['can_request_quote']);
  }

  /**
   * Convenience: should storefront present a booking path?
   */
  public static function can_request_booking(int $tenant_id) : bool {
    $result = self::evaluate($tenant_id);
    return !empty($result['can_request_booking']);
  }

  /**
   * Convenience: return rider-facing current message.
   */
  public static function current_message(int $tenant_id) : string {
    $result = self::evaluate($tenant_id);
    return (string) ($result['message'] ?? '');
    }

  /**
   * Convenience: current storefront state.
   */
  public static function state(int $tenant_id) : string {
    $result = self::evaluate($tenant_id);
    return (string) ($result['state'] ?? 'closed');
    }

  /**
   * Convenience: runtime context for public storefront templates.
   */
  public static function view_model(int $tenant_id) : array {
    $result = self::evaluate($tenant_id);

    return [
      'tenant_id'               => $tenant_id,
      'state'                   => $result['state'],
      'request_mode'            => $result['request_mode'],
      'can_render_storefront'   => $result['can_render_storefront'],
      'can_render_request_form' => $result['can_render_request_form'],
      'can_request_quote'       => $result['can_request_quote'],
      'can_request_booking'     => $result['can_request_booking'],
      'message'                 => $result['message'],
      'reason_code'             => $result['reason_code'],
      'is_open_now'             => $result['is_open_now'],
      'hours_mode'              => $result['hours_mode'],
      'timezone'                => $result['timezone'],
    ];
    }

  // ---------------------------------------------------------------------------
  // Internal logic
  // ---------------------------------------------------------------------------

  private static function can_request_quote_from_mode(string $request_mode, bool $requires_quote) : bool {
    return in_array($request_mode, ['quote_only', 'quote_or_booking'], true);
    }

  
    private static function can_request_booking_from_mode(string $request_mode, bool $allows_booking) : bool {
    if (!$allows_booking) {
      return false;
    }

    return in_array($request_mode, ['booking_only', 'quote_or_booking'], true);
  }

  /**
   * Storefront hours gate.
   *
   * Current implementation:
   * - always_on => open
   * - scheduled => open only if a matching calendar window exists
   *
   * Supported sources, in order:
   * 1) SD_Availability::is_storefront_open_now($tenant_id) if present
   * 2) Calendar section daily window JSON from SD_TenantConfig if present
   *
   * Expected daily JSON formats accepted:
   * - {"enabled":true,"start":"09:00","end":"17:00"}
   * - [{"start":"09:00","end":"12:00"},{"start":"13:00","end":"17:00"}]
   */
  private static function is_open_now(int $tenant_id, string $hours_mode, string $timezone) : bool {
    if ($hours_mode !== 'scheduled') {
      return true;
    }

    if (class_exists('SD_Availability', false) && method_exists('SD_Availability', 'is_storefront_open_now')) {
      return (bool) SD_Availability::is_storefront_open_now($tenant_id);
    }

    if (!class_exists('SD_TenantConfig', false)) {
      return false;
    }

    $calendar = SD_TenantConfig::calendar($tenant_id);
    if (empty($calendar)) {
      return false;
    }

    $tz_string = $timezone !== '' ? $timezone : self::string_value($calendar, SD_Meta::CALENDAR_TIMEZONE, '');
    if ($tz_string === '') {
      return false;
    }

    try {
      $tz = new \DateTimeZone($tz_string);
      $now = new \DateTimeImmutable('now', $tz);
    } catch (\Exception $e) {
      return false;
    }

    $weekday_map = [
      'Mon' => SD_Meta::HOURS_MONDAY,
      'Tue' => SD_Meta::HOURS_TUESDAY,
      'Wed' => SD_Meta::HOURS_WEDNESDAY,
      'Thu' => SD_Meta::HOURS_THURSDAY,
      'Fri' => SD_Meta::HOURS_FRIDAY,
      'Sat' => SD_Meta::HOURS_SATURDAY,
      'Sun' => SD_Meta::HOURS_SUNDAY,
    ];

    $day_key = $weekday_map[$now->format('D')] ?? '';
    if ($day_key === '') {
      return false;
    }

    $raw = isset($calendar[$day_key]) ? (string) $calendar[$day_key] : '';
    if ($raw === '') {
      return false;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      return false;
    }

    $now_hm = $now->format('H:i');

    // Format: {"enabled":true,"start":"09:00","end":"17:00"}
    if (array_key_exists('enabled', $decoded) || array_key_exists('start', $decoded) || array_key_exists('end', $decoded)) {
      $enabled = !isset($decoded['enabled']) || !empty($decoded['enabled']);
      if (!$enabled) {
        return false;
      }

      $start = isset($decoded['start']) ? (string) $decoded['start'] : '';
      $end   = isset($decoded['end'])   ? (string) $decoded['end']   : '';

      return self::time_in_window($now_hm, $start, $end);
    }

    // Format: [{"start":"09:00","end":"12:00"},{"start":"13:00","end":"17:00"}]
    foreach ($decoded as $window) {
      if (!is_array($window)) {
        continue;
      }
      $start = isset($window['start']) ? (string) $window['start'] : '';
      $end   = isset($window['end'])   ? (string) $window['end']   : '';

      if (self::time_in_window($now_hm, $start, $end)) {
        return true;
      }
    }

    return false;
  }

  private static function time_in_window(string $now_hm, string $start, string $end) : bool {
    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) {
      return false;
    }

    // Same-day window only.
    if ($start >= $end) {
      return false;
    }

    return ($now_hm >= $start && $now_hm <= $end);
  }

  private static function fallback_readiness(int $tenant_id) : array {
    if (class_exists('SD_TenantConfig', false) && method_exists('SD_TenantConfig', 'basic_readiness')) {
      return SD_TenantConfig::basic_readiness($tenant_id);
    }

    return [
      'tenant_id' => $tenant_id,
      'is_ready'  => false,
      'missing'   => [],
      'warnings'  => [],
      'details'   => [],
    ];
  }

  private static function string_value(array $config, string $key, string $default = '') : string {
    if (!array_key_exists($key, $config)) {
      return $default;
    }
    $value = $config[$key];
    return is_scalar($value) ? trim((string) $value) : $default;
  }

  private static function bool_value(array $config, string $key, bool $default = false) : bool {
    if (!array_key_exists($key, $config)) {
      return $default;
    }

    $value = $config[$key];

    if (is_bool($value)) {
      return $value;
    }

    return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
  }

  private static function result(int $tenant_id, array $data) : array {
    return array_merge([
      'tenant_id' => $tenant_id,
    ], $data);
  }
}