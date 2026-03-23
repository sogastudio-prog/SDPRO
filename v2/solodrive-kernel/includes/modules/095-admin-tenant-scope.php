<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_AdminTenantScope (v1.1)
 *
 * Purpose:
 * - Automatically scope admin list tables by sd_tenant_id for tenant-scoped CPTs:
 *   sd_ride, sd_quote, sd_attempt
 * - Restrict sd_tenant list to the user's tenant unless platform admin
 * - Allow platform admins to optionally filter by ?sd_tenant_id=
 *
 * Canon:
 * - Tenant-scoped records are filtered by SD_Meta::TENANT_ID
 * - Platform admins may view all tenants or filter to one tenant
 * - Non-platform users only see their own tenant-scoped records
 */
final class SD_Module_AdminTenantScope {

  public static function register() : void {
    if (!is_admin()) return;

    add_action('pre_get_posts', [__CLASS__, 'scope_admin_queries']);
    add_action('restrict_manage_posts', [__CLASS__, 'render_platform_tenant_filter']);
  }

  public static function scope_admin_queries(\WP_Query $q) : void {
    if (!is_admin()) return;
    if (!$q->is_main_query()) return;

    global $pagenow;
    if ($pagenow !== 'edit.php') return;

    $screen_post_type = (string) ($q->get('post_type') ?: '');
    if ($screen_post_type === '') {
      $screen_post_type = isset($_GET['post_type'])
        ? sanitize_key((string) wp_unslash($_GET['post_type']))
        : '';
    }

    if ($screen_post_type === '') return;

    $tenant_cpt  = self::tenant_cpt();
    $ride_cpt    = self::ride_cpt();
    $quote_cpt   = self::quote_cpt();
    $attempt_cpt = self::attempt_cpt();

    $ctx_tenant_id      = self::current_context_tenant_id();
    $platform_filter_id = self::requested_tenant_filter_id();
    $is_platform        = self::current_user_can_manage_all_tenants();

    // 1) sd_tenant list
    if ($screen_post_type === $tenant_cpt) {
      if ($is_platform) {
        if ($platform_filter_id > 0) {
          $q->set('p', $platform_filter_id);
          $q->set('posts_per_page', 1);
        }
        return;
      }

      if ($ctx_tenant_id > 0) {
        $q->set('p', $ctx_tenant_id);
        $q->set('posts_per_page', 1);
      } else {
        $q->set('post__in', [0]);
      }
      return;
    }

    // 2) Tenant-scoped operational CPTs
    $tenant_scoped = [$ride_cpt, $quote_cpt, $attempt_cpt];
    if (!in_array($screen_post_type, $tenant_scoped, true)) return;

    // Platform admins: optional filter, else see all
    if ($is_platform) {
      if ($platform_filter_id <= 0) {
        return;
      }

      self::append_tenant_meta_query($q, $platform_filter_id);
      return;
    }

    // Non-platform users: must have tenant context
    if ($ctx_tenant_id <= 0) {
      $q->set('post__in', [0]);
      return;
    }

    self::append_tenant_meta_query($q, $ctx_tenant_id);
  }

  /**
   * Platform-only helper: show a tenant selector on list tables.
   * Uses ?sd_tenant_id= for query filtering.
   */
  public static function render_platform_tenant_filter() : void {
    if (!is_admin()) return;
    if (!self::current_user_can_manage_all_tenants()) return;

    global $pagenow;
    if ($pagenow !== 'edit.php') return;

    $post_type = isset($_GET['post_type'])
      ? sanitize_key((string) wp_unslash($_GET['post_type']))
      : 'post';

    $scopable = in_array($post_type, [
      self::ride_cpt(),
      self::quote_cpt(),
      self::attempt_cpt(),
      self::tenant_cpt(),
    ], true);

    if (!$scopable) return;

    $selected = self::requested_tenant_filter_id();

    $tenants = get_posts([
      'post_type'      => self::tenant_cpt(),
      'post_status'    => 'any',
      'posts_per_page' => 200,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'fields'         => 'ids',
    ]);

    echo '<label class="screen-reader-text" for="sd_tenant_id">Tenant</label>';
    echo '<select name="sd_tenant_id" id="sd_tenant_id">';
    echo '<option value="0"' . selected($selected, 0, false) . '>All tenants</option>';

    foreach ($tenants as $tid) {
      $title = get_the_title($tid);
      echo '<option value="' . esc_attr((string) $tid) . '"' . selected($selected, (int) $tid, false) . '>' . esc_html($title) . '</option>';
    }

    echo '</select>';
  }

  private static function append_tenant_meta_query(\WP_Query $q, int $tenant_id) : void {
    if ($tenant_id <= 0) return;

    $meta_query = (array) $q->get('meta_query');

    $meta_query[] = [
      'key'     => SD_Meta::TENANT_ID,
      'value'   => (string) $tenant_id,
      'compare' => '=',
    ];

    $q->set('meta_query', $meta_query);
  }

  private static function requested_tenant_filter_id() : int {
    return isset($_GET['sd_tenant_id'])
      ? absint(wp_unslash($_GET['sd_tenant_id']))
      : 0;
  }

  private static function current_context_tenant_id() : int {
    if (!class_exists('SD_TenantAccess') || !method_exists('SD_TenantAccess', 'current_tenant_context_id')) {
      return 0;
    }

    return (int) SD_TenantAccess::current_tenant_context_id();
  }

  private static function current_user_can_manage_all_tenants() : bool {
    if (!class_exists('SD_TenantAccess') || !method_exists('SD_TenantAccess', 'current_user_can_manage_all_tenants')) {
      return current_user_can('manage_options');
    }

    return (bool) SD_TenantAccess::current_user_can_manage_all_tenants();
  }

  private static function tenant_cpt() : string {
    return (class_exists('SD_Module_TenantCPT') && defined('SD_Module_TenantCPT::CPT'))
      ? SD_Module_TenantCPT::CPT
      : 'sd_tenant';
  }

  private static function ride_cpt() : string {
    return (class_exists('SD_Module_RideCPT') && defined('SD_Module_RideCPT::CPT'))
      ? SD_Module_RideCPT::CPT
      : 'sd_ride';
  }

  private static function quote_cpt() : string {
    return (class_exists('SD_Module_QuoteCPT') && defined('SD_Module_QuoteCPT::CPT'))
      ? SD_Module_QuoteCPT::CPT
      : 'sd_quote';
  }

  private static function attempt_cpt() : string {
    return (class_exists('SD_Module_AttemptCPT') && defined('SD_Module_AttemptCPT::CPT'))
      ? SD_Module_AttemptCPT::CPT
      : 'sd_attempt';
  }
}