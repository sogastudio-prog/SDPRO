<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_TenantCPT (v2.0)
 *
 * Purpose:
 * - Establish a first-class tenant record.
 * - Keep tenant-scoped configuration on the tenant post meta (NOT wp_options).
 * - Provide a clean tenant edit screen shell for section-based settings modules.
 *
 * CPT:
 * - sd_tenant
 *
 * Notes:
 * - This module NO LONGER owns tenant settings UI or save_post handling.
 * - Tenant settings are now managed by dedicated section modules, e.g.:
 *   - 108-tenant-settings-storefront.php
 *   - 109-tenant-settings-pricing.php
 *   - 110-tenant-settings-base-location.php
 *   - and subsequent section modules
 * - This file should remain focused on:
 *   - CPT registration
 *   - edit-screen asset bootstrapping
 *   - optional summary / readiness metaboxes
 */

final class SD_Module_TenantCPT {

  public const CPT = 'sd_tenant';

  // Fallback option (admin/dev convenience)
  public const OPT_CURRENT_TENANT_ID = 'sd_current_tenant_id';

  public static function register() : void {
    add_action('init', [__CLASS__, 'register_cpt']);

    if (is_admin()) {
      add_action('add_meta_boxes', [__CLASS__, 'add_metaboxes']);
      add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue']);
    }
  }

  public static function register_cpt() : void {
    $labels = [
      'name'               => 'Tenants',
      'singular_name'      => 'Tenant',
      'add_new'            => 'Add Tenant',
      'add_new_item'       => 'Add New Tenant',
      'edit_item'          => 'Edit Tenant',
      'new_item'           => 'New Tenant',
      'view_item'          => 'View Tenant',
      'search_items'       => 'Search Tenants',
      'not_found'          => 'No tenants found',
      'not_found_in_trash' => 'No tenants found in Trash',
      'menu_name'          => 'Tenants',
    ];

    register_post_type(self::CPT, [
      'labels'             => $labels,
      'public'             => false,
      'show_ui'            => true,
      'show_in_menu'       => true,
      'menu_position'      => 25,
      'menu_icon'          => 'dashicons-admin-multisite',
      'supports'           => ['title'],
      'capability_type'    => 'post',
      'map_meta_cap'       => true,
    ]);
  }

  public static function add_metaboxes() : void {
    add_meta_box(
      'sd_tenant_kernel_summary',
      'Tenant Kernel Summary',
      [__CLASS__, 'render_summary_metabox'],
      self::CPT,
      'side',
      'high'
    );
  }

  public static function admin_enqueue(string $hook) : void {
    if (!self::is_tenant_edit_screen()) return;

    if (class_exists('SD_Module_Places')) {
      SD_Module_Places::enqueue();
    }

    $handle = 'sd-tenant-admin-screen';
    if (!wp_script_is($handle, 'registered')) {
      wp_register_script($handle, '', [], '2.0', true);
    }
    wp_enqueue_script($handle);

    wp_add_inline_style($handle, '
      .sd-tenant-summary-list{margin:0;padding:0;list-style:none}
      .sd-tenant-summary-list li{margin:0 0 10px;padding:0}
      .sd-tenant-summary-key{display:block;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#646970;margin-bottom:2px}
      .sd-tenant-summary-value{display:block;font-size:13px;color:#1d2327;word-break:break-word}
      .sd-tenant-summary-muted{color:#646970}
      .sd-tenant-badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600}
      .sd-tenant-badge--ready{background:#edfaef;color:#116329}
      .sd-tenant-badge--incomplete{background:#fff8e5;color:#8a5a00}
      .sd-tenant-readiness-list{margin:8px 0 0 18px}
      .sd-tenant-readiness-list li{margin:0 0 4px}
      .pac-container{z-index:999999!important;}
    ');
  }

  private static function is_tenant_edit_screen() : bool {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) return false;

    return ($screen->base === 'post' && $screen->post_type === self::CPT);
  }

  public static function render_summary_metabox(\WP_Post $post) : void {
    $tenant_id = (int) $post->ID;

    $slug   = class_exists('SD_TenantConfig', false)
      ? (string) SD_TenantConfig::get_value($tenant_id, SD_Meta::TENANT_SLUG, '')
      : (string) get_post_meta($tenant_id, SD_Meta::TENANT_SLUG, true);

    $domain = class_exists('SD_TenantConfig', false)
      ? (string) SD_TenantConfig::get_value($tenant_id, SD_Meta::TENANT_DOMAIN, '')
      : (string) get_post_meta($tenant_id, SD_Meta::TENANT_DOMAIN, true);

    $state  = class_exists('SD_TenantConfig', false)
      ? (string) SD_TenantConfig::get_value($tenant_id, SD_Meta::STOREFRONT_STATE, 'open')
      : (string) get_post_meta($tenant_id, SD_Meta::STOREFRONT_STATE, true);

    $business_name = class_exists('SD_TenantConfig', false)
      ? (string) SD_TenantConfig::get_value($tenant_id, SD_Meta::PROFILE_BUSINESS_NAME, '')
      : (string) get_post_meta($tenant_id, SD_Meta::PROFILE_BUSINESS_NAME, true);

    $base_label = class_exists('SD_TenantConfig', false)
      ? (string) SD_TenantConfig::get_value($tenant_id, SD_Meta::BASE_LOCATION_LABEL, '')
      : (string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LABEL, true);

      $readiness = class_exists('SD_TenantReadiness', false)
      ? SD_TenantReadiness::evaluate($tenant_id)
      : [
          'is_ready' => false,
          'missing'  => [],
          'warnings' => [],
        ];

    $is_ready = !empty($readiness['is_ready']);
    $badge_class = $is_ready ? 'sd-tenant-badge sd-tenant-badge--ready' : 'sd-tenant-badge sd-tenant-badge--incomplete';
    $badge_text  = $is_ready ? 'Ready for testing' : 'Configuration incomplete';

    echo '<div class="sd-tenant-kernel-summary">';
      echo '<p><span class="' . esc_attr($badge_class) . '">' . esc_html($badge_text) . '</span></p>';

      echo '<ul class="sd-tenant-summary-list">';

  // 🔴 NEW — runtime state FIRST (this is key UX decision)
  self::summary_row('Last Known Location:', self::format_last_known_location($tenant_id));
  self::summary_row('Last Ping: ', self::format_last_ping($tenant_id));
  self::summary_row('Accuracy: ', self::format_last_accuracy($tenant_id));

  // existing
  self::summary_row('Business: ', $business_name);
  self::summary_row('Slug: ', $slug);
  self::summary_row('Domain: ', $domain);
  self::summary_row('Storefront State: ', self::pretty_enum($state));
  self::summary_row('Base Location: ', $base_label);

echo '</ul>';

      if (!$is_ready && !empty($readiness['missing'])) {
        echo '<div style="margin-top:12px;">';
          echo '<strong>Missing required config</strong>';
          echo '<ul class="sd-tenant-readiness-list">';
            foreach ((array) $readiness['missing'] as $meta_key) {
              echo '<li>' . esc_html(self::label_for_meta_key((string) $meta_key)) . '</li>';
            }
          echo '</ul>';
        echo '</div>';
      }

      if (!empty($readiness['warnings'])) {
        echo '<div style="margin-top:12px;">';
          echo '<strong>Warnings</strong>';
          echo '<ul class="sd-tenant-readiness-list">';
            foreach ((array) $readiness['warnings'] as $warning) {
              echo '<li>' . esc_html((string) $warning) . '</li>';
            }
          echo '</ul>';
        echo '</div>';
      }

      echo '<p class="description" style="margin-top:12px;">Tenant settings are managed through the section cards on this screen.</p>';
    echo '</div>';
  }

  private static function summary_row(string $label, string $value) : void {
    echo '<li>';
      echo '<span class="sd-tenant-summary-key">' . esc_html($label) . '</span>';
      if ($value !== '') {
        echo '<span class="sd-tenant-summary-value">' . esc_html($value) . '</span>';
      } else {
        echo '<span class="sd-tenant-summary-value sd-tenant-summary-muted">—</span>';
      }
    echo '</li>';
  }

  private static function pretty_enum(string $value) : string {
    if ($value === '') return '';
    return ucwords(str_replace('_', ' ', $value));
  }

  private static function label_for_meta_key(string $meta_key) : string {
    $labels = [
      SD_Meta::TENANT_SLUG                    => 'Tenant Slug',
      SD_Meta::STOREFRONT_STATE               => 'Storefront State',
      SD_Meta::STOREFRONT_ENABLED             => 'Storefront Enabled',
      SD_Meta::STOREFRONT_ACCEPTING_REQUESTS  => 'Accepting Requests',
      SD_Meta::BASE_LOCATION_LABEL            => 'Base Location Label',
      SD_Meta::BASE_LOCATION_LAT              => 'Base Latitude',
      SD_Meta::BASE_LOCATION_LNG              => 'Base Longitude',
      SD_Meta::BASE_LOCATION_RADIUS_M         => 'Base Radius',
      SD_Meta::QUOTE_MODE                     => 'Quote Mode',
      SD_Meta::PRICING_MODEL                  => 'Pricing Model',
      SD_Meta::QUOTE_EXPIRY_MINUTES           => 'Quote Expiry Minutes',
      SD_Meta::LEAD_EXPIRY_MINUTES            => 'Lead Expiry Minutes',
      SD_Meta::PROFILE_BUSINESS_NAME          => 'Business Name',
      SD_Meta::STRIPE_ACCOUNT_ID              => 'Stripe Account',
      SD_Meta::CALENDAR_TIMEZONE              => 'Calendar Timezone',
      SD_Meta::STOREFRONT_TIMEZONE            => 'Storefront Timezone',
      SD_Meta::AFTER_HOURS_SURCHARGE_VALUE    => 'After Hours Surcharge Value',
    ];

    return $labels[$meta_key] ?? $meta_key;
  }

  private static function format_last_known_location(int $tenant_id) : string {
  $lat = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LAT, true);
  $lng = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_LNG, true);

  if (abs($lat) < 0.0001 || abs($lng) < 0.0001) {
    return '—';
  }

  return number_format($lat, 5) . ', ' . number_format($lng, 5);
  }

private static function format_last_ping(int $tenant_id) : string {
  $ts = (int) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_TS, true);

  if ($ts <= 0) {
    return '—';
  }

  return human_time_diff($ts, time()) . ' ago';
  }

private static function format_last_accuracy(int $tenant_id) : string {
  $acc = (float) get_post_meta($tenant_id, SD_Meta::TENANT_LAST_LOCATION_ACCURACY_M, true);

  if ($acc <= 0) {
    return '—';
  }

  return number_format($acc, 1) . ' m';
  }
}

SD_Module_TenantCPT::register();