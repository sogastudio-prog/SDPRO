<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_TenantStripeSettings {

  private const META_STRIPE_MODE           = '_sd_stripe_mode';
  private const META_STRIPE_WEBHOOK_SECRET = '_sd_stripe_webhook_secret';

  private const NONCE_ACTION = 'sd_save_tenant_stripe_settings';
  private const POST_ACTION  = 'sd_save_tenant_stripe_settings';
  private const CPT          = 'sd_tenant';

  public static function register() : void {
    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
    add_action('admin_post_' . self::POST_ACTION, [__CLASS__, 'handle_save']);
  }

  public static function add_metabox() : void {
    add_meta_box(
      'sd_tenant_stripe_settings',
      'Stripe Settings (Tenant)',
      [__CLASS__, 'render_metabox'],
      self::CPT,
      'normal',
      'default'
    );
  }

  public static function render_metabox(\WP_Post $post) : void {
    $tenant_id = (int) $post->ID;

    SD_Admin_ViewEditToggle::render(
      'sd_tenant_stripe_settings_' . $tenant_id,
      function() use ($tenant_id) {
        self::render_readonly($tenant_id);
      },
      function() use ($tenant_id) {
        self::render_editform($tenant_id);
      }
    );
  }

  private static function render_readonly(int $tenant_id) : void {
    $acct = (string) get_post_meta($tenant_id, SD_Meta::STRIPE_ACCOUNT_ID, true);
    $mode = (string) get_post_meta($tenant_id, self::META_STRIPE_MODE, true) ?: 'test';
    $has_webhook = ((string) get_post_meta($tenant_id, self::META_STRIPE_WEBHOOK_SECRET, true)) !== '';

    echo '<table class="widefat striped"><tbody>';
    echo '<tr><th>Connected account</th><td>' . esc_html($acct ?: '—') . '</td></tr>';
    echo '<tr><th>Mode</th><td>' . esc_html($mode) . '</td></tr>';
    echo '<tr><th>Tenant webhook secret</th><td>' . ($has_webhook ? 'yes' : 'no') . '</td></tr>';
    echo '</tbody></table>';
  }

  private static function render_editform(int $tenant_id) : void {
    $acct    = (string) get_post_meta($tenant_id, SD_Meta::STRIPE_ACCOUNT_ID, true);
    $mode    = (string) get_post_meta($tenant_id, self::META_STRIPE_MODE, true) ?: 'test';
    $webhook = (string) get_post_meta($tenant_id, self::META_STRIPE_WEBHOOK_SECRET, true);

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="' . esc_attr(self::POST_ACTION) . '">';
    echo '<input type="hidden" name="tenant_id" value="' . esc_attr((string)$tenant_id) . '">';
    wp_nonce_field(self::NONCE_ACTION, '_sd_nonce');

    echo '<p><label><strong>Connected Account ID</strong></label><br/>';
    echo '<input type="text" class="widefat" name="stripe_account_id" value="' . esc_attr($acct) . '" placeholder="acct_..." />';
    echo '</p>';

    echo '<p><label><strong>Stripe Mode</strong></label><br/>';
    echo '<select name="sd_stripe_mode">';
    echo '<option value="test"' . selected($mode, 'test', false) . '>test</option>';
    echo '<option value="live"' . selected($mode, 'live', false) . '>live</option>';
    echo '</select></p>';

    echo '<p><label><strong>Webhook Secret</strong></label><br/>';
    echo '<input type="text" class="widefat" name="sd_stripe_webhook_secret" value="' . esc_attr($webhook) . '" />';
    echo '</p>';

    echo '<p><button type="submit" class="button button-primary">Save Stripe Settings</button></p>';

    echo '</form>';
  }

  public static function handle_save() : void {
    if (!current_user_can('edit_posts')) {
      wp_die('Permission denied');
    }

    $tenant_id = isset($_POST['tenant_id']) ? absint($_POST['tenant_id']) : 0;

    if (!$tenant_id || get_post_type($tenant_id) !== self::CPT) {
      wp_die('Invalid tenant');
    }

    check_admin_referer(self::NONCE_ACTION, '_sd_nonce');

    $acct = isset($_POST['stripe_account_id'])
      ? sanitize_text_field(wp_unslash($_POST['stripe_account_id']))
      : '';

    $mode = isset($_POST['sd_stripe_mode'])
      ? sanitize_key(wp_unslash($_POST['sd_stripe_mode']))
      : 'test';

    $webhook = isset($_POST['sd_stripe_webhook_secret'])
      ? sanitize_text_field(wp_unslash($_POST['sd_stripe_webhook_secret']))
      : '';

    if (!in_array($mode, ['test','live'], true)) {
      $mode = 'test';
    }

    update_post_meta($tenant_id, SD_Meta::STRIPE_ACCOUNT_ID, $acct);
    update_post_meta($tenant_id, self::META_STRIPE_MODE, $mode);
    update_post_meta($tenant_id, self::META_STRIPE_WEBHOOK_SECRET, $webhook);

    wp_safe_redirect(add_query_arg([
      'post' => $tenant_id,
      'action' => 'edit',
      'sd_stripe_status' => 'updated'
    ], admin_url('post.php')));

    exit;
  }
}