<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Canon quote record:
 * - child of sd_lead
 * - commercial artifact only
 * - tenant-scoped always
 */
final class SD_Module_QuoteCPT {

  public const CPT = 'sd_quote';
  private const NOTICE_TRANSIENT = 'sd_quote_missing_scope_notice';

  public static function register() : void {
    add_action('init', [__CLASS__, 'register_cpt']);
    add_action('save_post_' . self::CPT, [__CLASS__, 'enforce_scope_on_save'], 20, 2);
    add_action('admin_notices', [__CLASS__, 'maybe_admin_notice']);
  }

  public static function register_cpt() : void {
    register_post_type(self::CPT, [
      'label'               => 'Quotes',
      'labels'              => [
        'name'          => 'Quotes',
        'singular_name' => 'Quote',
      ],
      'public'              => false,
      'show_ui'             => true,
      'show_in_menu'        => true,
      'show_in_admin_bar'   => false,
      'show_in_nav_menus'   => false,
      'exclude_from_search' => true,
      'publicly_queryable'  => false,
      'menu_position'       => 27,
      'menu_icon'           => 'dashicons-money-alt',
      'supports'            => ['title'],
      'capability_type'     => 'post',
      'map_meta_cap'        => true,
    ]);
  }

  public static function enforce_scope_on_save(int $post_id, \WP_Post $post) : void {
    if (wp_is_post_revision($post_id)) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if ($post->post_type !== self::CPT) return;

    $tenant_id = (int) get_post_meta($post_id, SD_Meta::TENANT_ID, true);
    $lead_id   = (int) get_post_meta($post_id, SD_Meta::LEAD_ID, true);

    if ($tenant_id > 0 && $lead_id > 0) {
      return;
    }

    if (is_admin()) {
      set_transient(self::NOTICE_TRANSIENT, 1, 60);
    }

    remove_action('save_post_' . self::CPT, [__CLASS__, 'enforce_scope_on_save'], 20);
    wp_update_post([
      'ID'          => $post_id,
      'post_status' => 'draft',
    ]);
    add_action('save_post_' . self::CPT, [__CLASS__, 'enforce_scope_on_save'], 20, 2);
  }

  public static function maybe_admin_notice() : void {
    if (!is_admin()) return;
    if (!get_transient(self::NOTICE_TRANSIENT)) return;

    delete_transient(self::NOTICE_TRANSIENT);

    echo '<div class="notice notice-error"><p><strong>Quote not saved:</strong> sd_tenant_id and sd_lead_id are required for all quotes. The record was forced to Draft.</p></div>';
  }
}