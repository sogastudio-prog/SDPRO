<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_TimeSpaceEventCPT {

  public const CPT = 'sd_ts_event';

  public static function register() : void {
    register_post_type(self::CPT, [
      'label'               => 'Time-Space Events',
      'public'              => false,
      'show_ui'             => true,
      'show_in_menu'        => false,
      'exclude_from_search' => true,
      'publicly_queryable'  => false,
      'supports'            => ['title'],
    ]);
  }
}