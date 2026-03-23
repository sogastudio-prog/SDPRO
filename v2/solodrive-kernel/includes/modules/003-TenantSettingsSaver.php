<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_TenantSettingsSaver
 *
 * Canonical tenant settings save service.
 *
 * Purpose:
 * - Validate a section payload against SD_TenantSettingsSchema
 * - Save all meta keys owned by that section
 * - Return normalized values + validation/save errors
 *
 * Notes:
 * - Write service only
 * - Safe to load multiple times
 * - Assumes SD_Meta, SD_TenantSettingsSchema, and SD_TenantConfig are loaded
 * - This class does NOT enforce UI routing; caller is responsible for nonce/capability checks
 *
 * Important persistence rule:
 * - Section saves are authoritative
 * - Owned keys are always persisted, even when empty
 * - Missing bool fields are normalized to '0'
 * - Missing non-bool fields fall back to schema defaults so config state is deterministic
 */
if (class_exists('SD_TenantSettingsSaver', false)) { return; }

final class SD_TenantSettingsSaver {

  /**
   * Save one schema-owned section to a tenant record.
   *
   * Return shape:
   * [
   *   'ok'         => bool,
   *   'tenant_id'  => int,
   *   'section'    => string,
   *   'normalized' => array,
   *   'saved'      => array,
   *   'errors'     => [ meta_key => [messages...] ],
   *   'warnings'   => [ ... ],
   * ]
   */
  public static function save_section(int $tenant_id, string $section, array $input) : array {
    $tenant_id = absint($tenant_id);
    $section   = (string) $section;

    if ($tenant_id <= 0) {
      return self::result(false, $tenant_id, $section, [], [], [
        '_section' => ['Invalid tenant ID.'],
      ]);
    }

    if (!SD_TenantSettingsSchema::has_section($section)) {
      return self::result(false, $tenant_id, $section, [], [], [
        '_section' => ['Unknown settings section.'],
      ]);
    }

    $fields = SD_TenantSettingsSchema::section_fields($section);
    $input  = self::complete_section_input($section, $input);

    $validation = SD_TenantSettingsSchema::validate($section, $input);
    $normalized = (array) ($validation['normalized'] ?? []);
    $errors     = (array) ($validation['errors'] ?? []);

    /**
     * Guarantee deterministic persistence for every owned key.
     * Even if schema validation omitted a key, we still persist
     * a normalized/default value for that section-owned field.
     */
    foreach ($fields as $meta_key => $field) {
      if (array_key_exists($meta_key, $normalized)) {
        continue;
      }

      $fallback = self::fallback_value_for_field($field);
      $normalized[$meta_key] = $fallback;
    }

    if (!empty($errors)) {
      return self::result(false, $tenant_id, $section, $normalized, [], $errors);
    }

    $saved = [];

    foreach ($fields as $meta_key => $field) {
      $value       = $normalized[$meta_key];
      $store_value = self::prepare_for_storage($field, $value);

      $ok = update_post_meta($tenant_id, $meta_key, $store_value);

      /**
       * update_post_meta() returns:
       * - meta_id if inserted
       * - true if updated
       * - false if unchanged or failed
       *
       * We treat "unchanged" as success if the stored value already matches.
       */
      if ($ok === false) {
        $current = get_post_meta($tenant_id, $meta_key, true);
        if ((string) $current !== (string) $store_value) {
          $errors[$meta_key][] = 'Failed to save value.';
          continue;
        }
      }

      $saved[$meta_key] = $store_value;
    }

    if (!empty($errors)) {
      return self::result(false, $tenant_id, $section, $normalized, $saved, $errors);
    }

    if (class_exists('SD_TenantConfig', false)) {
      SD_TenantConfig::clear_cache($tenant_id);
    }

    /**
     * Action hook for downstream runtime consumers.
     *
     * @param int    $tenant_id
     * @param string $section
     * @param array  $saved
     * @param array  $normalized
     */
    do_action('sd_tenant_settings_saved_section', $tenant_id, $section, $saved, $normalized);

    return self::result(true, $tenant_id, $section, $normalized, $saved, []);
  }

  /**
   * Save multiple sections in sequence.
   *
   * Payload shape:
   * [
   *   'storefront' => [...],
   *   'pricing'    => [...],
   * ]
   */
  public static function save_sections(int $tenant_id, array $payload_by_section) : array {
    $tenant_id = absint($tenant_id);
    $results   = [];
    $all_ok    = true;

    foreach ($payload_by_section as $section => $input) {
      $result = self::save_section($tenant_id, (string) $section, is_array($input) ? $input : []);
      $results[(string) $section] = $result;

      if (empty($result['ok'])) {
        $all_ok = false;
      }
    }

    return [
      'ok'        => $all_ok,
      'tenant_id' => $tenant_id,
      'results'   => $results,
    ];
  }

  /**
   * Delete all meta keys owned by a section.
   *
   * Use sparingly. Not recommended for normal form saves.
   */
  public static function reset_section(int $tenant_id, string $section) : array {
    $tenant_id = absint($tenant_id);
    $section   = (string) $section;

    if ($tenant_id <= 0) {
      return self::result(false, $tenant_id, $section, [], [], [
        '_section' => ['Invalid tenant ID.'],
      ]);
    }

    if (!SD_TenantSettingsSchema::has_section($section)) {
      return self::result(false, $tenant_id, $section, [], [], [
        '_section' => ['Unknown settings section.'],
      ]);
    }

    $saved  = [];
    $errors = [];

    foreach (SD_TenantSettingsSchema::owned_meta_keys($section) as $meta_key) {
      $ok = delete_post_meta($tenant_id, $meta_key);

      if ($ok || get_post_meta($tenant_id, $meta_key, true) === '') {
        $saved[$meta_key] = null;
      } else {
        $errors[$meta_key][] = 'Failed to reset value.';
      }
    }

    if (class_exists('SD_TenantConfig', false)) {
      SD_TenantConfig::clear_cache($tenant_id);
    }

    do_action('sd_tenant_settings_reset_section', $tenant_id, $section, $saved);

    return self::result(
      empty($errors),
      $tenant_id,
      $section,
      SD_TenantSettingsSchema::defaults($section),
      $saved,
      $errors
    );
  }

  /**
   * Filter raw input down to only schema-owned keys for a section.
   *
   * Important:
   * - Missing bools are normalized to '0'
   * - Other missing keys are intentionally left absent here;
   *   save_section() will complete them with defaults before validation/save
   */
  public static function filter_input_for_section(string $section, array $input) : array {
    $out = [];

    if (!SD_TenantSettingsSchema::has_section($section)) {
      return $out;
    }

    $fields = SD_TenantSettingsSchema::section_fields($section);

    foreach ($fields as $meta_key => $field) {
      $type = (string) ($field['type'] ?? 'string');

      if (array_key_exists($meta_key, $input)) {
        $out[$meta_key] = $input[$meta_key];
        continue;
      }

      if ($type === 'bool') {
        $out[$meta_key] = '0';
      }
    }

    return $out;
  }

  /**
   * Build a save-ready payload from request arrays like $_POST.
   *
   * Helpful for section save handlers.
   */
  public static function section_input_from_request(string $section, array $request) : array {
    $request = wp_unslash($request);
    return self::filter_input_for_section($section, $request);
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  private static function result(bool $ok, int $tenant_id, string $section, array $normalized, array $saved, array $errors, array $warnings = []) : array {
    return [
      'ok'         => $ok,
      'tenant_id'  => $tenant_id,
      'section'    => $section,
      'normalized' => $normalized,
      'saved'      => $saved,
      'errors'     => $errors,
      'warnings'   => $warnings,
    ];
  }

  /**
   * Ensure every section-owned key has a value before schema validation/save.
   *
   * Rules:
   * - bool => '0' when missing
   * - everything else => schema default when missing
   */
  private static function complete_section_input(string $section, array $input) : array {
    $fields = SD_TenantSettingsSchema::section_fields($section);

    foreach ($fields as $meta_key => $field) {
      if (array_key_exists($meta_key, $input)) {
        continue;
      }

      $type = (string) ($field['type'] ?? 'string');

      if ($type === 'bool') {
        $input[$meta_key] = '0';
        continue;
      }

      $input[$meta_key] = self::fallback_value_for_field($field);
    }

    return $input;
  }

  /**
   * Default/fallback value for a schema field.
   */
  private static function fallback_value_for_field(array $field) {
    if (array_key_exists('default', $field)) {
      return $field['default'];
    }

    $type = (string) ($field['type'] ?? 'string');

    switch ($type) {
      case 'bool':
        return '0';

      case 'int':
      case 'attachment_id':
      case 'money':
      case 'currency':
      case 'slug':
      case 'domain':
      case 'timezone':
      case 'email':
      case 'url':
      case 'phone':
      case 'hex_color':
      case 'lat':
      case 'lng':
      case 'json':
      case 'textarea':
      case 'enum':
      case 'string':
      default:
        return '';
    }
  }

  private static function prepare_for_storage(array $field, $value) {
    $type = (string) ($field['type'] ?? 'string');

    /**
     * WordPress post meta stores scalars as strings.
     * We keep normalized strings where appropriate for stability,
     * while allowing ints through for code readability.
     */
    switch ($type) {
      case 'int':
      case 'attachment_id':
        return ($value === '' || $value === null) ? '' : (int) $value;

      case 'bool':
        return ((string) $value === '1') ? '1' : '0';

      case 'money':
      case 'currency':
      case 'slug':
      case 'domain':
      case 'timezone':
      case 'email':
      case 'url':
      case 'phone':
      case 'hex_color':
      case 'lat':
      case 'lng':
      case 'json':
      case 'textarea':
      case 'enum':
      case 'string':
      default:
        return is_scalar($value) ? (string) $value : wp_json_encode($value);
    }
  }
}