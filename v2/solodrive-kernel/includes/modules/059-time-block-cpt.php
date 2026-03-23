<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Canon:
 * - Time blocks represent supply/hold/commit capacity.
 * - They are not created by storefront.
 * - They may be OPEN, HELD, COMMITTED, or EXPIRED.
 */
final class SD_Module_TimeBlockCPT {

  public const CPT = 'sd_time_block';

  public static function register() : void {
    register_post_type(self::CPT, [
      'label'               => 'Time Blocks',
      'labels'              => [
        'name'          => 'Time Blocks',
        'singular_name' => 'Time Block',
      ],
      'public'              => false,
      'show_ui'             => true,
      'show_in_menu'        => true,
      'show_in_admin_bar'   => false,
      'show_in_nav_menus'   => false,
      'exclude_from_search' => true,
      'publicly_queryable'  => false,
      'menu_position'       => 26,
      'menu_icon'           => 'dashicons-calendar-alt',
      'supports'            => ['title'],
      'capability_type'     => 'post',
      'map_meta_cap'        => true,
    ]);
  }
}