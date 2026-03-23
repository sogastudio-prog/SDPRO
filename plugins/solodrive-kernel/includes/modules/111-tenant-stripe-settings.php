<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_TenantStripeSettings (v1)
 *
 * Purpose:
 * - Tenant-scoped Stripe config beyond the connected account id.
 *
 * Notes:
 * - Tenant connected account id already lives in SD_Meta::STRIPE_ACCOUNT_ID (public tenant meta).
 * - Secrets should NOT be stored in wp_options. For foundation we store webhook secret on tenant as private meta
 *   to support future per-tenant webhooks, but webhook verification currently uses SD_STRIPE_WEBHOOK_SECRET constant.
 *
 * Stored on TENANT (private meta):
 * - _sd_stripe_mode                (test|live)
 * - _sd_stripe_webhook_secret      (optional; future per-tenant verify)
 */

final class SD_Module_TenantStripeSettings {

  private const META_STRIPE_MODE           = '_sd_stripe_mode';
  private const META_STRIPE_WEBHOOK_SECRET = '_sd_stripe_webhook_secret';

  public static function register() : void {
    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
    add_action('save_post_' . SD_Module_TenantCPT::CPT, [__CLASS__, 'save_metabox'], 20, 2);
  }

  public static function add_metabox() : void {
    add_meta_box(
      'sd_tenant_stripe_settings',
      'Stripe Settings (Tenant)',
      [__CLASS__, 'render_metabox'],
      SD_Module_TenantCPT::CPT,
      'normal',
      'default'
    );
  }

  public static function render_metabox(\WP_Post $post) : void {
    wp_nonce_field('sd_tenant_stripe_settings_save_' . $post->ID, 'sd_tenant_stripe_settings_nonce');

    SD_Admin_ViewEditToggle::render(
      'sd_tenant_stripe_settings_' . $post->ID,
      function() use ($post) {
        self::render_readonly((int)$post->ID);
      },
      function() use ($post) {
        self::render_editform((int)$post->ID);
      },
      [
        'edit_label'   => 'Edit',
        'cancel_label' => 'Cancel',
      ]
    );
  }

  private static function render_readonly(int $tenant_id) : void {
    $acct = (string) get_post_meta($tenant_id, SD_Meta::STRIPE_ACCOUNT_ID, true);
    $mode = (string) get_post_meta($tenant_id, self::META_STRIPE_MODE, true);
    if ($mode === '') $mode = 'test';

    $has_webhook = ((string) get_post_meta($tenant_id, self::META_STRIPE_WEBHOOK_SECRET, true)) !== '';

    echo '<table class="widefat striped"><tbody>';
      echo '<tr><th>Connected account</th><td>' . esc_html($acct !== '' ? $acct : '—') . '</td></tr>';
      echo '<tr><th>Mode</th><td>' . esc_html($mode) . '</td></tr>';
      echo '<tr><th>Tenant webhook secret (stored)</th><td>' . esc_html($has_webhook ? 'yes' : 'no') . '</td></tr>';
      echo '<tr><th>Platform secret key</th><td>' . esc_html(defined('SD_STRIPE_SECRET_KEY') ? 'defined' : 'missing') . '</td></tr>';
      echo '<tr><th>Platform webhook secret</th><td>' . esc_html(defined('SD_STRIPE_WEBHOOK_SECRET') ? 'defined' : 'missing') . '</td></tr>';
    echo '</tbody></table>';

    echo '<p style="margin-top:10px;color:#555">';
      echo 'Foundation behavior: webhook verification uses <code>SD_STRIPE_WEBHOOK_SECRET</code>. Tenant webhook secret is stored for future per-tenant routing.';
    echo '</p>';
  }

  private static function render_editform(int $tenant_id) : void {
    $mode = (string) get_post_meta($tenant_id, self::META_STRIPE_MODE, true);
    if (!in_array($mode, ['test','live'], true)) $mode = 'test';

    $webhook = (string) get_post_meta($tenant_id, self::META_STRIPE_WEBHOOK_SECRET, true);

    echo '<p><label><strong>Stripe mode</strong></label><br/>';
    echo '<select name="sd_stripe_mode">';
      echo '<option value="test"' . selected($mode, 'test', false) . '>test</option>';
      echo '<option value="live"' . selected($mode, 'live', false) . '>live</option>';
    echo '</select></p>';

    echo '<p><label><strong>Tenant webhook secret (optional)</strong></label><br/>';
    echo '<input type="text" class="widefat" name="sd_stripe_webhook_secret" value="' . esc_attr($webhook) . '" placeholder="whsec_... (optional)" />';
    echo '<span style="display:block;margin-top:6px;color:#666">';
      echo 'Not used for verification yet (platform constant is used). Stored for future per-tenant webhook routing.';
    echo '</span></p>';
  }

  public static function save_metabox(int $post_id, \WP_Post $post) : void {
    if ($post->post_type !== SD_Module_TenantCPT::CPT) return;
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) return;

    $nonce = isset($_POST['sd_tenant_stripe_settings_nonce'])
      ? sanitize_text_field((string) wp_unslash($_POST['sd_tenant_stripe_settings_nonce']))
      : '';
    if (!$nonce || !wp_verify_nonce($nonce, 'sd_tenant_stripe_settings_save_' . $post_id)) return;

    if (!current_user_can('edit_post', $post_id)) return;

    $mode = isset($_POST['sd_stripe_mode'])
      ? sanitize_key((string) wp_unslash($_POST['sd_stripe_mode']))
      : 'test';
    if (!in_array($mode, ['test','live'], true)) $mode = 'test';

    $webhook = isset($_POST['sd_stripe_webhook_secret'])
      ? sanitize_text_field((string) wp_unslash($_POST['sd_stripe_webhook_secret']))
      : '';

    update_post_meta($post_id, self::META_STRIPE_MODE, $mode);
    update_post_meta($post_id, self::META_STRIPE_WEBHOOK_SECRET, $webhook);
  }
}