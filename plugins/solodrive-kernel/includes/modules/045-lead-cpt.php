<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_LeadCPT
 *
 * Canon:
 * - sd_lead is the engagement root.
 * - Quote, attempt, and ride are child artifacts of lead.
 * - The public trip token resolves to lead.
 */
final class SD_Module_LeadCPT {

  public const CPT = 'sd_lead';

  public static function register() : void {
    add_action('init', [__CLASS__, 'register_post_type']);
  }

  public static function register_post_type() : void {
    $labels = [
      'name'               => 'Leads',
      'singular_name'      => 'Lead',
      'add_new'            => 'Add New',
      'add_new_item'       => 'Add New Lead',
      'edit_item'          => 'Edit Lead',
      'new_item'           => 'New Lead',
      'view_item'          => 'View Lead',
      'search_items'       => 'Search Leads',
      'not_found'          => 'No leads found',
      'not_found_in_trash' => 'No leads found in Trash',
      'menu_name'          => 'Leads',
    ];

    register_post_type(self::CPT, [
      'labels'             => $labels,
      'public'             => false,
      'show_ui'            => true,
      'show_in_menu'       => true,
      'menu_position'      => 25,
      'menu_icon'          => 'dashicons-id',
      'supports'           => ['title'],
      'capability_type'    => 'post',
      'map_meta_cap'       => true,
    ]);
  }
}
