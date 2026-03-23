<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_TenantConfig
 *
 * Canonical tenant settings reader.
 *
 * Purpose:
 * - Read tenant-scoped settings from sd_tenant post meta
 * - Merge schema defaults
 * - Normalize values through SD_TenantSettingsSchema
 * - Provide one stable read surface for storefront, quote engine, and admin UI
 *
 * Notes:
 * - Read-only service
 * - Safe to load multiple times
 * - Assumes SD_Meta and SD_TenantSettingsSchema are already loaded
 */
if (class_exists('SD_TenantConfig', false)) { return; }

final class SD_TenantConfig {

  /**
   * Small in-request cache to avoid repeated meta reads.
   *
   * @var array<int,array>
   */
  private static $cache = [];

  /**
   * Get all tenant settings, grouped by schema section.
   *
   * Return shape:
   * [
   *   'tenant_id' => 123,
   *   'sections'  => [
   *     'storefront'   => [...],
   *     'pricing'      => [...],
   *     ...
   *   ],
   *   'flat' => [
   *     'sd_tenant_slug' => 'foo',
   *     ...
   *   ],
   * ]
   */
  public static function get(int $tenant_id) : array {
    $tenant_id = absint($tenant_id);
    if ($tenant_id <= 0) {
      return self::empty_payload();
    }

    if (isset(self::$cache[$tenant_id])) {
      return self::$cache[$tenant_id];
    }

    $payload = [
      'tenant_id' => $tenant_id,
      'sections'  => [],
      'flat'      => [],
    ];

    foreach (array_keys(SD_TenantSettingsSchema::sections()) as $section) {
      $section_values = self::get_section($tenant_id, $section);
      $payload['sections'][$section] = $section_values;

      foreach ($section_values as $meta_key => $value) {
        $payload['flat'][$meta_key] = $value;
      }
    }

    self::$cache[$tenant_id] = $payload;
    return $payload;
  }

  /**
   * Get one schema section for a tenant.
   */
  public static function get_section(int $tenant_id, string $section) : array {
    $tenant_id = absint($tenant_id);
    if ($tenant_id <= 0 || !SD_TenantSettingsSchema::has_section($section)) {
      return [];
    }

    $fields   = SD_TenantSettingsSchema::section_fields($section);
    $defaults = SD_TenantSettingsSchema::defaults($section);
    $raw      = [];

    foreach ($fields as $meta_key => $_field) {
      $stored = get_post_meta($tenant_id, $meta_key, true);

      if ($stored === '' || $stored === null) {
        if (array_key_exists($meta_key, $defaults)) {
          $raw[$meta_key] = $defaults[$meta_key];
        }
      } else {
        $raw[$meta_key] = $stored;
      }
    }

    return SD_TenantSettingsSchema::normalize($section, $raw);
  }

  /**
   * Get one meta value through schema ownership + normalization.
   */
  public static function get_value(int $tenant_id, string $meta_key, $default = null) {
    $tenant_id = absint($tenant_id);
    if ($tenant_id <= 0 || $meta_key === '') {
      return $default;
    }

    $ownership = SD_TenantSettingsSchema::ownership_map();
    if (!isset($ownership[$meta_key])) {
      $stored = get_post_meta($tenant_id, $meta_key, true);
      return ($stored === '' || $stored === null) ? $default : $stored;
    }

    $section = $ownership[$meta_key];
    $values  = self::get_section($tenant_id, $section);

    if (!array_key_exists($meta_key, $values)) {
      return $default;
    }

    $value = $values[$meta_key];
    return ($value === '' || $value === null) ? $default : $value;
  }

  /**
   * Convenience: get many values by key.
   *
   * Returns [ meta_key => value ].
   */
  public static function get_values(int $tenant_id, array $meta_keys) : array {
    $out = [];
    foreach ($meta_keys as $meta_key) {
      $out[$meta_key] = self::get_value($tenant_id, (string) $meta_key, null);
    }
    return $out;
  }

  /**
   * Convenience: does tenant have a non-empty value for a key?
   */
  public static function has_value(int $tenant_id, string $meta_key) : bool {
    $value = self::get_value($tenant_id, $meta_key, null);
    return !self::is_empty($value);
  }

  /**
   * Convenience: section with defaults guaranteed present.
   */
  public static function storefront(int $tenant_id) : array {
    return self::get_section($tenant_id, SD_TenantSettingsSchema::SECTION_STOREFRONT);
  }

  public static function pricing(int $tenant_id) : array {
    return self::get_section($tenant_id, SD_TenantSettingsSchema::SECTION_PRICING);
  }

  public static function profile(int $tenant_id) : array {
    return self::get_section($tenant_id, SD_TenantSettingsSchema::SECTION_PROFILE);
  }

  public static function vehicle(int $tenant_id) : array {
    return self::get_section($tenant_id, SD_TenantSettingsSchema::SECTION_VEHICLE);
  }

  public static function base_location(int $tenant_id) : array {
    return self::get_section($tenant_id, SD_TenantSettingsSchema::SECTION_BASE_LOCATION);
  }

  public static function brand(int $tenant_id) : array {
    return self::get_section($tenant_id, SD_TenantSettingsSchema::SECTION_BRAND);
  }

  public static function calendar(int $tenant_id) : array {
    return self::get_section($tenant_id, SD_TenantSettingsSchema::SECTION_CALENDAR);
  }

  public static function drive_mode(int $tenant_id) : array {
    return self::get_section($tenant_id, SD_TenantSettingsSchema::SECTION_DRIVE_MODE);
  }

  /**
   * Clear one tenant from in-request cache.
   */
  public static function clear_cache(int $tenant_id) : void {
    $tenant_id = absint($tenant_id);
    if ($tenant_id > 0 && isset(self::$cache[$tenant_id])) {
      unset(self::$cache[$tenant_id]);
    }
  }

  /**
   * Clear all cached tenants for the current request.
   */
  public static function clear_all_cache() : void {
    self::$cache = [];
  }

  /**
   * Lightweight readiness snapshot helper for UI use.
   *
   * This is not a substitute for SD_TenantReadiness, but is useful
   * before that module is added.
   */
  public static function basic_readiness(int $tenant_id) : array {
    $tenant_id = absint($tenant_id);
    if ($tenant_id <= 0) {
      return [
        'is_ready' => false,
        'missing'  => ['tenant_id'],
      ];
    }

    $req = SD_TenantSettingsSchema::readiness_requirements();
    $missing = [];

    foreach ((array) ($req['required'] ?? []) as $meta_key) {
      if (!self::has_value($tenant_id, $meta_key)) {
        $missing[] = $meta_key;
      }
    }

    return [
      'is_ready' => empty($missing),
      'missing'  => $missing,
    ];
  }

  /**
   * Resolve tenant id from a ride/quote/attempt record if needed.
   * Useful for storefront/quote consumers reading from operational records.
   */
  public static function tenant_id_from_record(int $post_id) : int {
    $post_id = absint($post_id);
    if ($post_id <= 0) {
      return 0;
    }

    return absint(get_post_meta($post_id, SD_Meta::TENANT_ID, true));
  }

  /**
   * Return a section shaped for form population.
   *
   * Right now this is identical to get_section(), but keeping the method
   * separate gives us a stable seam if form-specific shaping is needed later.
   */
  public static function form_defaults(int $tenant_id, string $section) : array {
    return self::get_section($tenant_id, $section);
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  private static function empty_payload() : array {
    $sections = [];
    foreach (array_keys(SD_TenantSettingsSchema::sections()) as $section) {
      $sections[$section] = SD_TenantSettingsSchema::defaults($section);
    }

    $flat = [];
    foreach ($sections as $section_values) {
      foreach ($section_values as $meta_key => $value) {
        $flat[$meta_key] = $value;
      }
    }

    return [
      'tenant_id' => 0,
      'sections'  => $sections,
      'flat'      => $flat,
    ];
  }

  private static function is_empty($value) : bool {
    return $value === '' || $value === null || $value === [];
  }
}