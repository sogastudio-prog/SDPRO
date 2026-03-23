<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_RolesCaps (v1)
 *
 * Day-1 model:
 * - Users are WP users with roles + caps
 * - Single tenant scope via user meta: sd_tenant_id
 *
 * Notes:
 * - Administrator is treated as platform-super by default (gets all sd_* caps).
 * - Roles are additive bundles. A user may hold multiple roles.
 */

final class SD_Module_RolesCaps {

  // Roles
  public const ROLE_OWNER    = 'sd_owner';
  public const ROLE_DISPATCH = 'sd_dispatch';
  public const ROLE_STAFF    = 'sd_staff';
  public const ROLE_DRIVER   = 'sd_driver';

  // Caps (kernel-owned)
  public const CAP_MANAGE_ALL_TENANTS = 'sd_manage_all_tenants'; // platform-only
  public const CAP_MANAGE_TENANT      = 'sd_manage_tenant';      // owner/admin of tenant
  public const CAP_DISPATCH           = 'sd_dispatch_access';
  public const CAP_DRIVER             = 'sd_driver_access';

  public const CAP_VIEW_RIDES         = 'sd_view_rides';
  public const CAP_EDIT_RIDES         = 'sd_edit_rides';

  public const CAP_VIEW_QUOTES        = 'sd_view_quotes';
  public const CAP_EDIT_QUOTES        = 'sd_edit_quotes';

  public const CAP_VIEW_ATTEMPTS      = 'sd_view_attempts';
  public const CAP_VIEW_EVIDENCE      = 'sd_view_evidence';

  private const OPT_BOOTSTRAPPED = 'sd_roles_caps_bootstrapped_v1';

  public static function register() : void {
    add_action('init', [__CLASS__, 'ensure_roles_and_caps'], 5);
  }

  public static function ensure_roles_and_caps() : void {
    // Only bootstrap once per install; still safe if it runs again.
    $done = (string) get_option(self::OPT_BOOTSTRAPPED, '');
    if ($done !== '') return;

    $caps_owner = [
      self::CAP_MANAGE_TENANT => true,
      self::CAP_DISPATCH      => true,
      self::CAP_DRIVER        => true,

      self::CAP_VIEW_RIDES    => true,
      self::CAP_EDIT_RIDES    => true,

      self::CAP_VIEW_QUOTES   => true,
      self::CAP_EDIT_QUOTES   => true,

      self::CAP_VIEW_ATTEMPTS => true,
      self::CAP_VIEW_EVIDENCE => true,
    ];

    $caps_dispatch = [
      self::CAP_DISPATCH      => true,
      self::CAP_VIEW_RIDES    => true,
      self::CAP_EDIT_RIDES    => true,
      self::CAP_VIEW_QUOTES   => true,
      self::CAP_EDIT_QUOTES   => true,
      self::CAP_VIEW_EVIDENCE => true,
      // no attempts/financials by default
    ];

    $caps_staff = [
      self::CAP_DISPATCH      => true,
      self::CAP_VIEW_RIDES    => true,
      self::CAP_VIEW_QUOTES   => true,
      self::CAP_VIEW_EVIDENCE => true,
      // read-mostly
    ];

    $caps_driver = [
      self::CAP_DRIVER      => true,
      self::CAP_VIEW_RIDES  => true,
      self::CAP_VIEW_QUOTES => true,
    ];

    // Create roles if missing (does not overwrite existing caps on role).
    self::upsert_role(self::ROLE_OWNER,    'Tenant Owner',    $caps_owner);
    self::upsert_role(self::ROLE_DISPATCH, 'Dispatch',        $caps_dispatch);
    self::upsert_role(self::ROLE_STAFF,    'Staff',           $caps_staff);
    self::upsert_role(self::ROLE_DRIVER,   'Driver',          $caps_driver);

    // Ensure Administrator has all kernel caps (platform super-user).
    self::grant_caps_to_role('administrator', array_keys(self::all_kernel_caps()));

    update_option(self::OPT_BOOTSTRAPPED, gmdate('c'), false);
  }

  private static function upsert_role(string $role, string $label, array $caps) : void {
    $r = get_role($role);
    if (!$r) {
      add_role($role, $label, $caps);
      return;
    }
    // Merge in any missing caps (do not remove anything).
    foreach ($caps as $cap => $allow) {
      if ($allow) $r->add_cap($cap, true);
    }
  }

  private static function grant_caps_to_role(string $role, array $caps) : void {
    $r = get_role($role);
    if (!$r) return;
    foreach ($caps as $cap) {
      $r->add_cap($cap, true);
    }
  }

  private static function all_kernel_caps() : array {
    return [
      self::CAP_MANAGE_ALL_TENANTS => true,
      self::CAP_MANAGE_TENANT      => true,
      self::CAP_DISPATCH           => true,
      self::CAP_DRIVER             => true,
      self::CAP_VIEW_RIDES         => true,
      self::CAP_EDIT_RIDES         => true,
      self::CAP_VIEW_QUOTES        => true,
      self::CAP_EDIT_QUOTES        => true,
      self::CAP_VIEW_ATTEMPTS      => true,
      self::CAP_VIEW_EVIDENCE      => true,
    ];
  }
}