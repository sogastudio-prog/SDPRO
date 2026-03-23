<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_CPT_Ride (v1.1)
 *
 * Purpose:
 * - Register sd_ride CPT.
 * - Enforce tenant scoping: every sd_ride MUST have meta sd_tenant_id.
 * - Provide a single canonical creator for programmatic usage.
 *
 * Canon:
 * - Rides are tenant-scoped records (NOT identities).
 * - sd_tenant_id is REQUIRED on all rides.
 * - Admin can create records, but tenant_id must be supplied/resolved.
 */
final class SD_CPT_Ride {

  public const CPT = 'sd_ride';

  private const NONCE_ACTION     = 'sd_ride_tenant_scope';
  private const NOTICE_TRANSIENT = 'sd_notice_ride_tenant_required';

  public static function register() : void {
    add_action('init', [__CLASS__, 'register_cpt']);

    // Admin UX helpers
    if (is_admin()) {
      add_action('add_meta_boxes', [__CLASS__, 'add_metaboxes']);
      add_action('save_post_' . self::CPT, [__CLASS__, 'save_tenant_metabox'], 10, 2);
      add_action('admin_notices', [__CLASS__, 'maybe_admin_notice']);
    }

    // Enforce tenant id on ALL creation paths
    add_action('save_post_' . self::CPT, [__CLASS__, 'enforce_tenant_id_on_save'], 20, 2);
  }

  // ---------------------------------------------------------------------------
  // CPT
  // ---------------------------------------------------------------------------

  public static function register_cpt() : void {
    register_post_type(self::CPT, [
      'labels' => [
        'name'          => 'Rides',
        'singular_name' => 'Ride',
      ],
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'supports' => ['title'],
      'has_archive' => false,
      'rewrite' => false,
      'menu_icon' => 'dashicons-car',
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }

  // ---------------------------------------------------------------------------
  // Canonical creator (programmatic)
  // ---------------------------------------------------------------------------

  /**
   * Create a tenant-scoped ride.
   *
   * @param int   $tenant_id REQUIRED.
   * @param array $meta      Additional meta (public sd_* and/or private _sd_*).
   * @return int|WP_Error
   */
  public static function create(int $tenant_id, array $meta = []) {
    $tenant_id = (int) $tenant_id;
    if ($tenant_id <= 0) {
      return new WP_Error('sd_tenant_missing', 'Tenant id is required to create a ride.');
    }

    // Ensure required meta exists.
    $meta[SD_Meta::TENANT_ID] = $tenant_id;

    $ride_id = wp_insert_post([
      'post_type'   => self::CPT,
      'post_status' => 'publish',
      'post_title'  => 'Ride',
      'meta_input'  => $meta,
    ], true);

    if (is_wp_error($ride_id) || (int) $ride_id <= 0) {
      return is_wp_error($ride_id) ? $ride_id : new WP_Error('sd_ride_create_failed', 'Ride creation failed.');
    }

    // Hard guarantee.
    update_post_meta((int) $ride_id, SD_Meta::TENANT_ID, $tenant_id);

    return (int) $ride_id;
  }

  // ---------------------------------------------------------------------------
  // Admin metabox (tenant scope)
  // ---------------------------------------------------------------------------

  public static function add_metaboxes() : void {
    add_meta_box(
      'sd_ride_tenant_scope',
      'Tenant Scope',
      [__CLASS__, 'render_tenant_metabox'],
      self::CPT,
      'side',
      'high'
    );
  }

  public static function render_tenant_metabox(\WP_Post $post) : void {
    $tenant_id = (int) get_post_meta($post->ID, SD_Meta::TENANT_ID, true);
    wp_nonce_field(self::NONCE_ACTION, '_sd_ride_tenant_nonce');

    echo '<p class="description">All rides must belong to a tenant.</p>';
    echo '<label for="sd_tenant_id" style="display:block;font-weight:600;margin-bottom:6px;">sd_tenant_id</label>';
    echo '<input type="number" min="1" step="1" name="sd_tenant_id" id="sd_tenant_id" value="' . esc_attr((string) $tenant_id) . '" style="width:100%;" />';
    echo '<p class="description" style="margin-top:8px;">If left blank, the system will attempt to resolve a default tenant (resolver/option). If it cannot, the record will be forced to Draft.</p>';
  }

  public static function save_tenant_metabox(int $post_id, \WP_Post $post) : void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $nonce = isset($_POST['_sd_ride_tenant_nonce']) ? (string) $_POST['_sd_ride_tenant_nonce'] : '';
    if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) return;

    // If explicitly provided, write it.
    if (isset($_POST['sd_tenant_id'])) {
      $tid = (int) $_POST['sd_tenant_id'];
      if ($tid > 0) {
        update_post_meta($post_id, SD_Meta::TENANT_ID, $tid);
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Enforcement
  // ---------------------------------------------------------------------------

  private static function resolve_default_tenant_id() : int {
    // A) TenantResolver (host/path routing)
    if (class_exists('SD_Module_TenantResolver')) {
      $tid = (int) SD_Module_TenantResolver::current_tenant_id();
      if ($tid > 0) return $tid;
    }

    // B) Explicit POST field from admin metabox
    if (isset($_POST['sd_tenant_id'])) {
      $tid = (int) $_POST['sd_tenant_id'];
      if ($tid > 0) return $tid;
    }

    // C) Platform fallback option
    if (class_exists('SD_Module_TenantCPT')) {
      $opt = (int) get_option(SD_Module_TenantCPT::OPT_CURRENT_TENANT_ID, 0);
      if ($opt > 0 && get_post_type($opt) === SD_Module_TenantCPT::CPT) return $opt;
    }

    return 0;
  }

  public static function enforce_tenant_id_on_save(int $post_id, \WP_Post $post) : void {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if ($post->post_type !== self::CPT) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $existing = (int) get_post_meta($post_id, SD_Meta::TENANT_ID, true);
    if ($existing > 0) return;

    $tid = self::resolve_default_tenant_id();
    if ($tid > 0) {
      update_post_meta($post_id, SD_Meta::TENANT_ID, $tid);
      return;
    }

    // HARD FAIL-SOFT: never leave published unscoped records.
    // Force to draft and notify admin.
    if (is_admin()) {
      set_transient(self::NOTICE_TRANSIENT, 1, 60);
    }

    remove_action('save_post_' . self::CPT, [__CLASS__, 'enforce_tenant_id_on_save'], 20);
    wp_update_post([
      'ID' => $post_id,
      'post_status' => 'draft',
    ]);
    add_action('save_post_' . self::CPT, [__CLASS__, 'enforce_tenant_id_on_save'], 20, 2);
  }

  public static function maybe_admin_notice() : void {
    if (!is_admin()) return;
    if (!get_transient(self::NOTICE_TRANSIENT)) return;
    delete_transient(self::NOTICE_TRANSIENT);
    echo '<div class="notice notice-error"><p><strong>Ride not saved:</strong> sd_tenant_id is required for all rides. The record was forced to Draft.</p></div>';
  }
}

SD_CPT_Ride::register();