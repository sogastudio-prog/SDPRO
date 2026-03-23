<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_AttemptCPT (v1)
 *
 * Purpose:
 * - Establish sd_attempt as a first-class record.
 *
 * Canon:
 * - Attempts are ALWAYS tenant-scoped via SD_Meta::TENANT_ID.
 * - Attempts are the ONLY Stripe correlation handle (never trip tokens).
 */
final class SD_Module_AttemptCPT {

  public const CPT = 'sd_attempt';

  public static function register() : void {
    add_action('init', [__CLASS__, 'register_cpt']);
  }

  public static function register_cpt() : void {

    $labels = [
      'name'               => 'Attempts',
      'singular_name'      => 'Attempt',
      'add_new'            => 'Add Attempt',
      'add_new_item'       => 'Add New Attempt',
      'edit_item'          => 'Edit Attempt',
      'new_item'           => 'New Attempt',
      'view_item'          => 'View Attempt',
      'search_items'       => 'Search Attempts',
      'not_found'          => 'No attempts found',
      'not_found_in_trash' => 'No attempts found in Trash',
      'menu_name'          => 'Attempts',
    ];

    register_post_type(self::CPT, [
      'labels'             => $labels,
      'public'             => false,
      'show_ui'            => true,
      'show_in_menu'       => true,
      'menu_position'      => 28,
      'menu_icon'          => 'dashicons-shield',
      'supports'           => ['title'],
      'capability_type'    => 'post',
      'map_meta_cap'       => true,
    ]);
  }
}