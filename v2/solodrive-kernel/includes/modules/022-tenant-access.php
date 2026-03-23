<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_TenantAccess (v1)
 *
 * - Day-1: single tenant per user via user meta sd_tenant_id
 * - Platform admins (administrator) can operate cross-tenant, and may override tenant context.
 */

final class SD_TenantAccess {

  public const USER_META_TENANT_ID = 'sd_tenant_id';

  /**
   * Returns current user's tenant id (0 if none / not logged in).
   */
  public static function current_user_tenant_id() : int {
    if (!is_user_logged_in()) return 0;
    $uid = get_current_user_id();
    return (int) get_user_meta($uid, self::USER_META_TENANT_ID, true);
  }

  /**
   * Returns whether current user is a platform admin (cross-tenant).
   * Day-1: administrators are platform admins.
   */
  public static function current_user_can_manage_all_tenants() : bool {
    return current_user_can(SD_Module_RolesCaps::CAP_MANAGE_ALL_TENANTS) || current_user_can('manage_options');
  }

  /**
   * Tenant context for admin screens:
   * - If editing a tenant post, that post's ID is the tenant context.
   * - Else if platform admin and ?sd_tenant_id= is provided, allow override.
   * - Else use current user's tenant id.
   */
  public static function current_tenant_context_id() : int {
    // Editing a tenant record: that is the context.
    if (is_admin() && isset($_GET['post'], $_GET['action'])) {
      $action = sanitize_key((string) wp_unslash($_GET['action']));
      if ($action === 'edit') {
        $post_id = (int) $_GET['post'];
        if ($post_id > 0 && get_post_type($post_id) === SD_Module_TenantCPT::CPT) {
          return $post_id;
        }
      }
    }

    // Platform override.
    if (self::current_user_can_manage_all_tenants() && isset($_GET['sd_tenant_id'])) {
      $override = absint(wp_unslash($_GET['sd_tenant_id']));
      if ($override > 0) return $override;
    }

    return self::current_user_tenant_id();
  }

  /**
   * True if the record (post) belongs to the current tenant context.
   * Records must store SD_Meta::TENANT_ID as post meta.
   */
  public static function record_belongs_to_current_tenant(int $post_id) : bool {
    $ctx = self::current_tenant_context_id();
    if ($ctx <= 0) return false;

    $rid = (int) get_post_meta($post_id, SD_Meta::TENANT_ID, true);
    return $rid === $ctx;
  }
}