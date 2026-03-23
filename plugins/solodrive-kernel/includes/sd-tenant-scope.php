<?php
if (!defined('ABSPATH')) { exit; }

final class SD_TenantScope {

  /**
   * Tenant-owned post types (everything except the tenant CPT itself).
   * Keep this list canonical.
   */
  public static function tenant_scoped_post_types() : array {
    return [
      'sd_lead',
      'sd_ride',
      'sd_quote',
      'sd_attempt',
      'sd_capture',
      // add more as you introduce them
    ];
  }

  public static function is_tenant_scoped_post_type(string $post_type) : bool {
    return in_array($post_type, self::tenant_scoped_post_types(), true);
  }

  public static function require_tenant_id(int $tenant_id, string $context = '') : int {
    $tenant_id = (int) $tenant_id;
    if ($tenant_id <= 0) {
      return 0;
    }

    // Validate the tenant record exists and is the right CPT.
    if (!class_exists('SD_Module_TenantCPT')) return 0;
    if (get_post_type($tenant_id) !== SD_Module_TenantCPT::CPT) return 0;

    return $tenant_id;
  }

  /**
   * Enforce: tenant-owned records MUST have sd_tenant_id.
   * Returns WP_Error on failure.
   */
  public static function enforce_meta(array $postarr, array $meta_input = [], string $context = '') {
    $post_type = isset($postarr['post_type']) ? (string) $postarr['post_type'] : '';
    if ($post_type === '' || !self::is_tenant_scoped_post_type($post_type)) return true;

    $key = defined('SD_Meta::TENANT_ID') ? SD_Meta::TENANT_ID : 'sd_tenant_id';

    $tenant_id = 0;
    if (array_key_exists($key, $meta_input)) {
      $tenant_id = (int) $meta_input[$key];
    }

    $tenant_id = self::require_tenant_id($tenant_id, $context);
    if ($tenant_id <= 0) {
      return new WP_Error('sd_tenant_missing', 'Tenant context missing for tenant-scoped record.');
    }

    return true;
  }
}