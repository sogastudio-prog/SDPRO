<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_Admin_QuoteMetaDebug
 *
 * Developer tool:
 * - Shows ALL meta attached to a quote.
 * - Read-only.
 * - Fail-soft if CPT classes drift during refactor.
 */

final class SD_Module_Admin_QuoteMetaDebug {

  public static function register() : void {
    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
  }

  public static function add_metabox() : void {
    add_meta_box(
      'sd_quote_meta_debug',
      'Quote Meta (Debug)',
      [__CLASS__, 'render_metabox'],
      self::quote_cpt(),
      'normal',
      'default'
    );
  }

  public static function render_metabox(\WP_Post $post) : void {
    $meta = get_post_meta($post->ID);

    if (!$meta) {
      echo '<p>No meta found.</p>';
      return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th style="width:260px;">Meta Key</th>';
    echo '<th>Value</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($meta as $key => $values) {
      foreach ($values as $value) {
        if (is_serialized($value)) {
          $value = maybe_unserialize($value);
          $value = '<pre>' . esc_html(print_r($value, true)) . '</pre>';
        } else {
          $value = esc_html((string) $value);
        }

        echo '<tr>';
        echo '<td><code>' . esc_html((string) $key) . '</code></td>';
        echo '<td>' . $value . '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';
  }

  private static function quote_cpt() : string {
    if (class_exists('SD_Module_QuoteCPT') && defined('SD_Module_QuoteCPT::CPT')) {
      return (string) SD_Module_QuoteCPT::CPT;
    }
    if (class_exists('SD_CPT_Quote') && defined('SD_CPT_Quote::CPT')) {
      return (string) SD_CPT_Quote::CPT;
    }
    return 'sd_quote';
  }
}

SD_Module_Admin_QuoteMetaDebug::register();