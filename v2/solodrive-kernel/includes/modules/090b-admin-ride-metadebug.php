<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_Admin_RideMetaDebug
 *
 * Developer tool:
 * Shows ALL meta attached to a ride.
 * Read-only.
 */

final class SD_Module_Admin_RideMetaDebug {

  public static function register() : void {

    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
  }

  public static function add_metabox() : void {

    add_meta_box(
      'sd_ride_meta_debug',
      'Ride Meta (Debug)',
      [__CLASS__, 'render_metabox'],
      SD_CPT_Ride::CPT,
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
          $value = esc_html($value);
        }

        echo '<tr>';
        echo '<td><code>' . esc_html($key) . '</code></td>';
        echo '<td>' . $value . '</td>';
        echo '</tr>';
      }

    }

    echo '</tbody></table>';
  }
}
SD_Module_Admin_RideMetaDebug::register();