<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_Admin_RideMetabox (v1.1)
 *
 * Purpose:
 * - Provide a clean admin control plane for sd_ride:
 *   - view current state
 *   - apply next allowed state (through state service)
 *   - rotate token (invalidate link)
 *   - view token expiry window
 *
 * Canon:
 * - Read-only by default; explicit action buttons only
 * - No save_post hacks
 * - No raw lifecycle keys
 * - Uses kernel services for state + token operations
 */

final class SD_Module_Admin_RideMetabox {

  private const NONCE_ACTION = 'sd_ride_admin_actions';

  public static function register() : void {
    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
    add_action('admin_post_sd_ride_apply_state', [__CLASS__, 'handle_apply_state']);
    add_action('admin_post_sd_ride_rotate_token', [__CLASS__, 'handle_rotate_token']);
    add_action('admin_notices', [__CLASS__, 'admin_notices']);
  }

  public static function add_metabox() : void {
    add_meta_box(
      'sd_ride_control',
      'Ride Control (Kernel)',
      [__CLASS__, 'render_metabox'],
      self::ride_cpt(),
      'side',
      'high'
    );
  }

  public static function render_metabox(\WP_Post $post) : void {
    $ride_id = (int) $post->ID;
    if ($ride_id <= 0) return;

    $state = class_exists('SD_Module_RideStateService')
      ? SD_Module_RideStateService::get($ride_id)
      : '';

    $token      = (string) get_post_meta($ride_id, SD_Meta::TRIP_TOKEN, true);
    $token_hint = $token !== '' ? substr($token, 0, 8) : '';
    $trip_url   = $token !== '' ? home_url('/trip/' . rawurlencode($token) . '/') : '';

    $expires_at    = (int) get_post_meta($ride_id, SD_Meta::P_TOKEN_EXPIRES_AT, true);
    $expires_human = $expires_at > 0 ? gmdate('Y-m-d H:i:s', $expires_at) . ' UTC' : '—';

    $allowed = self::allowed_next_states($state);

    $nonce = wp_create_nonce(self::NONCE_ACTION);
    $admin_post = admin_url('admin-post.php');

    echo '<div style="margin-bottom:10px;">';
    echo '<strong>Current state</strong><br>';
    echo '<code>' . esc_html($state !== '' ? $state : '—') . '</code>';
    echo '</div>';

    echo '<div style="margin-bottom:10px;">';
    echo '<strong>Token</strong><br>';
    if ($token !== '') {
      echo '<code>' . esc_html($token_hint) . '…</code><br>';
      echo '<a href="' . esc_url($trip_url) . '" target="_blank" rel="noopener noreferrer">Open Trip</a>';
    } else {
      echo '<span style="color:#666;">(none assigned)</span>';
    }
    echo '</div>';

    echo '<div style="margin-bottom:10px;">';
    echo '<strong>Expires</strong><br>';
    echo '<code>' . esc_html($expires_human) . '</code>';
    echo '</div>';

    echo '<input type="hidden" name="sd_ride_nonce" value="' . esc_attr($nonce) . '">';
    echo '<input type="hidden" name="ride_id" value="' . esc_attr((string) $ride_id) . '">';

    echo '<label for="sd_ride_next_state" style="display:block; font-weight:600; margin:10px 0 4px;">Next state</label>';

    if (!empty($allowed)) {
      echo '<select name="next_state" id="sd_ride_next_state" style="width:100%;">';
      foreach ($allowed as $to) {
        echo '<option value="' . esc_attr($to) . '">' . esc_html($to) . '</option>';
      }
      echo '</select>';

      echo '<div style="margin-top:10px;">';
      echo '<button
              type="submit"
              class="button button-primary"
              name="action"
              value="sd_ride_apply_state"
              formmethod="post"
              formaction="' . esc_url($admin_post) . '"
            >Apply state</button>';
      echo '</div>';
    } else {
      echo '<div style="color:#666; font-size:12px;">No allowed next states.</div>';
    }

    echo '<div style="margin-top:8px;">';
    echo '<button
            type="submit"
            class="button"
            name="action"
            value="sd_ride_rotate_token"
            formmethod="post"
            formaction="' . esc_url($admin_post) . '"
            onclick="return confirm(\'Rotate token? Old link will stop working.\');"
          >Rotate token</button>';
    echo '</div>';

    echo '<p style="margin-top:10px; color:#666; font-size:12px;">State changes and token rotation are enforced by kernel services.</p>';
  }

  public static function handle_apply_state() : void {
    if (!is_admin()) wp_die('Forbidden');

    $ride_id = isset($_POST['ride_id']) ? (int) $_POST['ride_id'] : 0;
    $next    = isset($_POST['next_state']) ? sanitize_text_field((string) $_POST['next_state']) : '';

    $nonce = isset($_POST['sd_ride_nonce']) ? sanitize_text_field((string) $_POST['sd_ride_nonce']) : '';
    if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      self::redirect_back($ride_id, ['sd_notice' => 'nonce_fail']);
    }

    if ($ride_id <= 0 || get_post_type($ride_id) !== self::ride_cpt()) {
      self::redirect_back($ride_id, ['sd_notice' => 'bad_ride']);
    }

    if (!current_user_can('edit_post', $ride_id)) {
      self::redirect_back($ride_id, ['sd_notice' => 'cap_fail']);
    }

    if ($next === '') {
      self::redirect_back($ride_id, ['sd_notice' => 'state_missing']);
    }

    if (!class_exists('SD_Module_RideStateService')) {
      self::redirect_back($ride_id, ['sd_notice' => 'state_service_missing']);
    }

    $ok = SD_Module_RideStateService::set($ride_id, $next, ['via' => 'admin_metabox']);

    self::redirect_back($ride_id, [
      'sd_notice' => $ok ? 'state_ok' : 'state_rejected',
      'sd_state'  => $next,
    ]);
  }

  public static function handle_rotate_token() : void {
    if (!is_admin()) wp_die('Forbidden');

    $ride_id = isset($_POST['ride_id']) ? (int) $_POST['ride_id'] : 0;

    $nonce = isset($_POST['sd_ride_nonce']) ? sanitize_text_field((string) $_POST['sd_ride_nonce']) : '';
    if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      self::redirect_back($ride_id, ['sd_notice' => 'nonce_fail']);
    }

    if ($ride_id <= 0 || get_post_type($ride_id) !== self::ride_cpt()) {
      self::redirect_back($ride_id, ['sd_notice' => 'bad_ride']);
    }

    if (!current_user_can('edit_post', $ride_id)) {
      self::redirect_back($ride_id, ['sd_notice' => 'cap_fail']);
    }

    if (!class_exists('SD_Module_RideTokenService')) {
      self::redirect_back($ride_id, ['sd_notice' => 'token_service_missing']);
    }

    $new = (string) SD_Module_RideTokenService::rotate_token($ride_id);

    self::redirect_back($ride_id, [
      'sd_notice' => $new !== '' ? 'token_rotated' : 'token_rotate_failed',
      'sd_token'  => $new !== '' ? substr($new, 0, 8) : '',
    ]);
  }

  private static function redirect_back(int $ride_id, array $args = []) : void {
    if ($ride_id > 0) {
      $url = admin_url('post.php?post=' . $ride_id . '&action=edit');
    } else {
      $url = admin_url('edit.php?post_type=' . self::ride_cpt());
    }

    if (!empty($args)) {
      $url = add_query_arg($args, $url);
    }

    wp_safe_redirect($url);
    exit;
  }

  public static function admin_notices() : void {
    if (!is_admin()) return;
    if (!isset($_GET['sd_notice'])) return;

    $notice = sanitize_text_field((string) $_GET['sd_notice']);

    $class = 'notice notice-info';
    $msg   = '';

    switch ($notice) {
      case 'state_ok':
        $class = 'notice notice-success';
        $msg = 'Ride state updated.';
        break;

      case 'state_rejected':
        $class = 'notice notice-warning';
        $msg = 'State change rejected by governor.';
        break;

      case 'state_missing':
        $class = 'notice notice-warning';
        $msg = 'No next state was selected.';
        break;

      case 'state_service_missing':
        $class = 'notice notice-error';
        $msg = 'Ride state service is not available.';
        break;

      case 'token_rotated':
        $class = 'notice notice-success';
        $hint = isset($_GET['sd_token']) ? sanitize_text_field((string) $_GET['sd_token']) : '';
        $msg = $hint !== '' ? ('Token rotated. New token starts with ' . $hint . '…') : 'Token rotated.';
        break;

      case 'token_rotate_failed':
        $class = 'notice notice-error';
        $msg = 'Token rotation failed.';
        break;

      case 'token_service_missing':
        $class = 'notice notice-error';
        $msg = 'Ride token service is not available.';
        break;

      case 'nonce_fail':
        $class = 'notice notice-error';
        $msg = 'Security check failed.';
        break;

      case 'cap_fail':
        $class = 'notice notice-error';
        $msg = 'Permission denied.';
        break;

      case 'bad_ride':
        $class = 'notice notice-error';
        $msg = 'Invalid ride.';
        break;

      default:
        $msg = 'Done.';
        break;
    }

    echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($msg) . '</p></div>';
  }

  private static function allowed_next_states(string $state) : array {
    if ($state === '' || !class_exists('SD_Ride_State')) {
      return [];
    }

    $allowed = [];
    foreach (SD_Ride_State::all() as $to) {
      if ($to === $state) continue;
      if (SD_Ride_State::can_transition($state, $to)) {
        $allowed[] = $to;
      }
    }

    return $allowed;
  }

  private static function ride_cpt() : string {
    return (class_exists('SD_Module_RideCPT') && defined('SD_Module_RideCPT::CPT'))
      ? SD_Module_RideCPT::CPT
      : 'sd_ride';
  }
}