<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_RequestSurface (v1.2)
 *
 * Purpose:
 * - Render the public request surface (ride request form embed).
 *
 * Tenant-scoped config:
 * - CF7 form post ID is stored on the tenant as meta key:
 *     sd_intake_cf7_form_id
 *
 * Canon:
 * - Tenant must resolve.
 * - Fail-soft: never fatal on misconfig (including missing SD_Meta constants).
 */
final class SD_Module_RequestSurface {

  // Hard-string meta key to avoid fatals if SD_Meta is stale/mismatched.
  private const META_INTAKE_CF7_FORM_ID = 'sd_intake_cf7_form_id';

  public static function register() : void {
    add_shortcode('sd_request', [__CLASS__, 'shortcode_request']);
  }

  public static function shortcode_request($atts = []) : string {

    $tenant_id = class_exists('SD_Module_TenantResolver')
      ? (int) SD_Module_TenantResolver::current_tenant_id()
      : 0;

    if ($tenant_id <= 0) {
      return self::card_notice('This tenant is not configured yet.');
    }

    // Tenant-scoped CF7 form id
    $form_id = (int) get_post_meta($tenant_id, self::META_INTAKE_CF7_FORM_ID, true);

    // Optional fallback for dev
    if ($form_id <= 0 && defined('SD_CF7_FORM_ID_RIDE_REQUEST')) {
      $form_id = (int) SD_CF7_FORM_ID_RIDE_REQUEST;
    }

    if ($form_id <= 0) {
      return self::card_notice('Ride request form is not configured yet.');
    }

    // Embed CF7
    $short = '[contact-form-7 id="' . (int) $form_id . '"]';
    return do_shortcode($short);
  }

  private static function card_notice(string $msg) : string {
    $msg = esc_html($msg);
    return '<div class="sd-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px;font-family:system-ui;max-width:820px;margin:12px auto;">'
      . '<strong>SoloDrive</strong>'
      . '<div style="color:#4b5563;margin-top:6px;">' . $msg . '</div>'
      . '</div>';
  }
}

SD_Module_RequestSurface::register();