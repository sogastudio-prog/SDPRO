<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_UserTenantAssignment (v1)
 *
 * Purpose:
 * - Add a "Tenant assignment" selector to WP user profiles in wp-admin.
 * - Persist tenant scope on the user via user meta:
 *     sd_tenant_id = (int) tenant_post_id
 *
 * Notes:
 * - Day-1 manual assignment (explicit, reliable).
 * - Compatible with users having any WP role (or none).
 */

final class SD_Module_UserTenantAssignment {

  public static function register() : void {
    if (!is_admin()) return;

    add_action('show_user_profile', [__CLASS__, 'render_profile_fields']);
    add_action('edit_user_profile', [__CLASS__, 'render_profile_fields']);

    add_action('personal_options_update', [__CLASS__, 'save_profile_fields']);
    add_action('edit_user_profile_update', [__CLASS__, 'save_profile_fields']);
  }

  public static function render_profile_fields(\WP_User $user) : void {
    if (!current_user_can('edit_users')) return;

    $current_tenant_id = (int) get_user_meta($user->ID, 'sd_tenant_id', true);

    // Fetch tenants
    $tenants = get_posts([
      'post_type'      => SD_Module_TenantCPT::CPT,
      'post_status'    => ['publish', 'draft', 'private'],
      'posts_per_page' => 500,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'fields'         => 'ids',
    ]);

    wp_nonce_field('sd_user_tenant_save_' . $user->ID, 'sd_user_tenant_nonce');

    echo '<h2>SoloDrive</h2>';
    echo '<table class="form-table" role="presentation">';
      echo '<tr>';
        echo '<th><label for="sd_tenant_id">Tenant assignment</label></th>';
        echo '<td>';
          echo '<select name="sd_tenant_id" id="sd_tenant_id">';
            echo '<option value="0"' . selected($current_tenant_id, 0, false) . '>— None (unscoped) —</option>';

            foreach ($tenants as $tenant_id) {
              $title  = get_the_title($tenant_id);
              $slug   = (string) get_post_meta($tenant_id, SD_Meta::TENANT_SLUG, true);
              $domain = (string) get_post_meta($tenant_id, SD_Meta::TENANT_DOMAIN, true);

              $label = $title;
              $bits = [];
              if ($slug !== '')   $bits[] = $slug;
              if ($domain !== '') $bits[] = $domain;
              if (!empty($bits))  $label .= ' (' . implode(' • ', $bits) . ')';

              echo '<option value="' . esc_attr((string)$tenant_id) . '"' . selected($current_tenant_id, (int)$tenant_id, false) . '>';
                echo esc_html($label);
              echo '</option>';
            }
          echo '</select>';

          echo '<p class="description" style="margin-top:6px">';
            echo 'Sets <code>sd_tenant_id</code> on this user for tenant scoping (drivers/staff/owners).';
          echo '</p>';
        echo '</td>';
      echo '</tr>';
    echo '</table>';
  }

  public static function save_profile_fields(int $user_id) : void {
    if (!current_user_can('edit_users')) return;

    $nonce = isset($_POST['sd_user_tenant_nonce']) ? sanitize_text_field((string) wp_unslash($_POST['sd_user_tenant_nonce'])) : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'sd_user_tenant_save_' . $user_id)) return;

    $tenant_id = isset($_POST['sd_tenant_id']) ? absint(wp_unslash($_POST['sd_tenant_id'])) : 0;

    // Validate: must be an sd_tenant post id (or 0)
    if ($tenant_id > 0) {
      $p = get_post($tenant_id);
      if (!$p || $p->post_type !== SD_Module_TenantCPT::CPT) {
        $tenant_id = 0;
      }
    }

    if ($tenant_id > 0) {
      update_user_meta($user_id, 'sd_tenant_id', $tenant_id);
    } else {
      delete_user_meta($user_id, 'sd_tenant_id');
    }
  }
}