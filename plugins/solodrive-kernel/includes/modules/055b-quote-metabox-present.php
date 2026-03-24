<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_Admin_QuotePresentMetabox (v1.1)
 *
 * Purpose:
 * - Add a controlled admin action to move a quote to PRESENTED.
 *
 * Canon:
 * - Admin view is read-only by default
 * - State change happens only via admin-post action with nonce
 * - Uses quote state service as the sole writer
 */

final class SD_Module_Admin_QuotePresentMetabox {

  private const NONCE_ACTION = 'sd_quote_present_action';

  public static function register() : void {
    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
    add_action('admin_post_sd_quote_present', [__CLASS__, 'handle_present']);
    add_action('admin_notices', [__CLASS__, 'admin_notices']);
  }

  public static function add_metabox() : void {
    add_meta_box(
      'sd_quote_present_box',
      'Quote Actions',
      [__CLASS__, 'render_metabox'],
      self::quote_cpt(),
      'side',
      'high'
    );
  }

  public static function render_metabox(\WP_Post $post) : void {
    $quote_id = (int) $post->ID;
    if ($quote_id <= 0) return;

    $state = class_exists('SD_Module_QuoteStateService')
      ? SD_Module_QuoteStateService::get($quote_id)
      : (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true);

    $ride_id = (int) get_post_meta($quote_id, SD_Meta::RIDE_ID, true);

    $render_read = function() use ($state, $ride_id) {
      echo '<div class="sd-admin-readonly">';
      echo '<div><strong>Status:</strong> ' . esc_html($state !== '' ? $state : '—') . '</div>';
      echo '<div><strong>Ride:</strong> ' . esc_html($ride_id > 0 ? ('#' . $ride_id) : '—') . '</div>';
      echo '<div style="margin-top:8px;color:#666">Actions are available in Edit mode.</div>';
      echo '</div>';
    };

    $render_edit = function() use ($quote_id, $state) {
      $can_present = self::can_present($state);

      echo '<div class="sd-admin-actions">';

      if (!$can_present) {
        echo '<div style="color:#666;margin-bottom:8px">This quote cannot be presented from its current state.</div>';
      } else {
        $url = add_query_arg([
          'action'   => 'sd_quote_present',
          'quote_id' => (string) $quote_id,
        ], admin_url('admin-post.php'));

        $url = wp_nonce_url($url, self::NONCE_ACTION . ':' . $quote_id);

        echo '<a class="button button-primary" href="' . esc_url($url) . '">Present Quote</a>';
        echo '<div style="margin-top:8px;color:#666">Moves quote to <code>PRESENTED</code> (decision state).</div>';
      }

      echo '</div>';
    };

    if (class_exists('SD_Admin_ViewEditToggle') && method_exists('SD_Admin_ViewEditToggle', 'render')) {
      SD_Admin_ViewEditToggle::render(
        'sd_quote_actions_' . $quote_id,
        $render_read,
        $render_edit,
        [
          'edit_label'   => 'Edit',
          'cancel_label' => 'Cancel',
        ]
      );
      return;
    }

    // Fail-soft fallback if helper is unavailable
    $render_read();
    echo '<hr>';
    $render_edit();
  }

  public static function handle_present() : void {
    $quote_id = isset($_GET['quote_id']) ? absint((string) wp_unslash($_GET['quote_id'])) : 0;
    if ($quote_id <= 0) {
      self::redirect_back(0, 'missing');
    }

    if (get_post_type($quote_id) !== self::quote_cpt()) {
      self::redirect_back($quote_id, 'bad_quote');
    }

    if (!current_user_can('edit_post', $quote_id)) {
      self::redirect_back($quote_id, 'cap_fail');
    }

    $nonce_ok = isset($_GET['_wpnonce']) && wp_verify_nonce(
      (string) wp_unslash($_GET['_wpnonce']),
      self::NONCE_ACTION . ':' . $quote_id
    );

    if (!$nonce_ok) {
      self::redirect_back($quote_id, 'nonce_fail');
    }

    if (!class_exists('SD_Module_QuoteStateService') || !class_exists('SD_Quote_State')) {
      if (class_exists('SD_Util')) {
        SD_Util::log('quote_present_missing_state_service', ['quote_id' => $quote_id]);
      }
      self::redirect_back($quote_id, 'service_missing');
    }

    $current = SD_Module_QuoteStateService::get($quote_id);
    if (!self::can_present($current)) {
      self::redirect_back($quote_id, 'rejected');
    }

    $ok = SD_Module_QuoteStateService::set($quote_id, SD_Meta::QUOTE_PRESENTED, [
      'source' => 'admin_metabox',
      'user'   => get_current_user_id(),
    ]);

    if (class_exists('SD_Util')) {
      SD_Util::log('quote_present_action', [
        'quote_id' => $quote_id,
        'ok'       => $ok ? 1 : 0,
        'user_id'  => get_current_user_id(),
      ]);
    }

    self::redirect_back($quote_id, $ok ? 'ok' : 'rejected');
  }

  private static function redirect_back(int $quote_id, string $result) : void {
    if ($quote_id > 0) {
      $url = get_edit_post_link($quote_id, 'raw');
      if (!$url) {
        $url = admin_url('post.php?post=' . $quote_id . '&action=edit');
      }
    } else {
      $url = admin_url('edit.php?post_type=' . self::quote_cpt());
    }

    $url = add_query_arg([
      'sd_quote_present' => $result,
    ], $url);

    wp_safe_redirect($url, 302);
    exit;
  }

  public static function admin_notices() : void {
    if (!is_admin()) return;
    if (!isset($_GET['sd_quote_present'])) return;

    $notice = sanitize_text_field((string) wp_unslash($_GET['sd_quote_present']));

    $class = 'notice notice-info';
    $msg   = '';

    switch ($notice) {
      case 'ok':
        $class = 'notice notice-success';
        $msg = 'Quote moved to PRESENTED.';
        break;

      case 'rejected':
        $class = 'notice notice-warning';
        $msg = 'Quote could not be presented from its current state.';
        break;

      case 'nonce_fail':
        $class = 'notice notice-error';
        $msg = 'Security check failed.';
        break;

      case 'cap_fail':
        $class = 'notice notice-error';
        $msg = 'Permission denied.';
        break;

      case 'bad_quote':
        $class = 'notice notice-error';
        $msg = 'Invalid quote.';
        break;

      case 'missing':
        $class = 'notice notice-error';
        $msg = 'Missing quote ID.';
        break;

      case 'service_missing':
        $class = 'notice notice-error';
        $msg = 'Quote state service is not available.';
        break;

      default:
        $msg = 'Done.';
        break;
    }

    echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($msg) . '</p></div>';
  }

  private static function can_present(string $state) : bool {
    if ($state === '') return false;
    if (!class_exists('SD_Quote_State')) return false;

    if ($state === SD_Meta::QUOTE_PRESENTED || $state === SD_Meta::QUOTE_PAYMENT_PENDING {
      return false;
    }

    return SD_Quote_State::can_transition($state, SD_Meta::QUOTE_PRESENTED);
  }

  private static function quote_cpt() : string {
    return (class_exists('SD_Module_QuoteCPT') && defined('SD_Module_QuoteCPT::CPT'))
      ? SD_Module_QuoteCPT::CPT
      : 'sd_quote';
  }
}