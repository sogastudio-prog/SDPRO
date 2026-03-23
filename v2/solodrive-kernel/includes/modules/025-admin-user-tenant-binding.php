<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_AdminUserTenantBinding (v1)
 *
 * Purpose:
 * - Provide a simple admin UI to bind a WP user to a tenant via user meta:
 *     SD_Meta::TENANT_ID (sd_tenant_id)
 *
 * Notes:
 * - This is a DAY-1 primitive to support onboarding (Tenant Owner, Dispatch, Driver).
 * - Admins can scope any user; future versions can tighten capabilities.
 */

final class SD_Module_AdminUserTenantBinding {

  private const NONCE_FIELD  = 'sd_user_tenant_nonce';
  private const NONCE_ACTION = 'sd_user_tenant_save';

  public static function register() : void {
    if (!is_admin()) return;

    add_action('show_user_profile', [__CLASS__, 'render_panel']);
    add_action('edit_user_profile', [__CLASS__, 'render_panel']);

    add_action('personal_options_update', [__CLASS__, 'save_panel']);
    add_action('edit_user_profile_update', [__CLASS__, 'save_panel']);
  }

  public static function render_panel(\WP_User $user) : void {
    // Capability gate: only users who can edit this user can scope them.
    if (!current_user_can('edit_user', $user->ID)) return;

    $current = (int) get_user_meta($user->ID, SD_Meta::TENANT_ID, true);

    $tenants = get_posts([
      'post_type'      => SD_Module_TenantCPT::CPT,
      'post_status'    => ['publish', 'draft', 'private'],
      'posts_per_page' => 200,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'fields'         => 'ids',
    ]);

    echo '<h2>Tenant Scope</h2>';
    echo '<table class="form-table" role="presentation"><tbody>';

    wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

    echo '<tr>';
      echo '<th><label for="sd_tenant_id">Tenant</label></th>';
      echo '<td>';
        echo '<select name="sd_tenant_id" id="sd_tenant_id">';
          echo '<option value="0"' . selected($current, 0, false) . '>— Unscoped (no tenant) —</option>';
          foreach ($tenants as $tenant_id) {
            $tenant_id = (int) $tenant_id;
            $title = get_the_title($tenant_id);
            if ($title === '') $title = '(untitled)';
            $label = $title . ' (#' . $tenant_id . ')';
            echo '<option value="' . esc_attr((string)$tenant_id) . '"' . selected($current, $tenant_id, false) . '>' . esc_html($label) . '</option>';
          }
        echo '</select>';

        echo '<p class="description" style="max-width: 780px;">';
          echo 'Binds this WordPress user to a tenant by writing <code>' . esc_html(SD_Meta::TENANT_ID) . '</code> user meta. ';
          echo 'Tenant scoping is used for admin list filtering and tenant-specific configuration routing. ';
          echo 'Leave unscoped for platform admins.';
        echo '</p>';
      echo '</td>';
    echo '</tr>';

    echo '</tbody></table>';
  }

  public static function save_panel(int $user_id) : void {
    if (!current_user_can('edit_user', $user_id)) return;

    $nonce = isset($_POST[self::NONCE_FIELD]) ? sanitize_text_field((string) wp_unslash($_POST[self::NONCE_FIELD])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) return;

    $tenant_id = isset($_POST['sd_tenant_id']) ? absint(wp_unslash($_POST['sd_tenant_id'])) : 0;

    if ($tenant_id > 0) {
      // Ensure target tenant exists.
      $p = get_post($tenant_id);
      if (!$p || $p->post_type !== SD_Module_TenantCPT::CPT) {
        $tenant_id = 0;
      }
    }

    if ($tenant_id > 0) {
      update_user_meta($user_id, SD_Meta::TENANT_ID, $tenant_id);
    } else {
      delete_user_meta($user_id, SD_Meta::TENANT_ID);
    }
  }
}
