<?php
if (!defined('ABSPATH')) { exit; }

final class SD_CPT_TimeBlock {

  const CPT = 'sd_time_block';

  public static function register() : void {

    register_post_type(self::CPT, [
      'label' => 'Time Blocks',
      'public' => false,
      'show_ui' => true,
      'show_in_menu' => true,
      'supports' => ['title'],
      'capability_type' => 'post',
      'map_meta_cap' => true,
    ]);
  }
}

add_action('init', ['SD_CPT_TimeBlock', 'register']);