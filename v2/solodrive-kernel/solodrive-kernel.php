<?php
/**
 * Plugin Name: SoloDrive Kernel
 * Description: Core plugin for SoloDrive platform.
 * Version: 0.1.0
 * Author: SoloDrive
 */

if (!defined('ABSPATH')) { exit; }

define('SD_KERNEL_FILE', __FILE__);

define('SD_KERNEL_PATH', plugin_dir_path(__FILE__));
define('SD_KERNEL_URL', plugin_dir_url(__FILE__));

require_once SD_KERNEL_PATH . 'includes/core.php';

// -----------------------------------------------------------------------------
// Activation
// -----------------------------------------------------------------------------

register_activation_hook(SD_KERNEL_FILE, 'sd_kernel_activate');

/**
 * Activation responsibilities (idempotent):
 * - Ensure /trip/<token>/ rewrite is registered, then flush.
 * - Ensure fast token-hash index exists.
 */
function sd_kernel_activate() : void {

  // 1) Rewrites: add our rules before flushing.
  if (class_exists('SD_Module_TripSurface') && method_exists('SD_Module_TripSurface', 'add_rewrite_rules')) {
    SD_Module_TripSurface::add_rewrite_rules();
  }
  flush_rewrite_rules();

  // 2) DB index for token hash lookup.
  sd_kernel_install_trip_token_index();
}

/**
 * Create an index on wp_postmeta for (_sd_trip_token_hash) lookups.
 *
 * Pattern:
 * - meta_key:   _sd_trip_token_hash
 * - meta_value: 64-char hex sha256
 */
function sd_kernel_install_trip_token_index() : void {
  global $wpdb;

  $table = $wpdb->postmeta;
  $index = 'sd_trip_token_hash';

  // Check if index already exists (idempotent).
  $exists = $wpdb->get_var(
    $wpdb->prepare(
      "SELECT COUNT(1)
       FROM INFORMATION_SCHEMA.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = %s
         AND INDEX_NAME = %s",
      $wpdb->postmeta,
      $index
    )
  );

  if ((int) $exists > 0) return;

  // meta_value is LONGTEXT; use prefix length so MySQL can index it.
  // meta_key is short for our key, prefix keeps index small.
  $wpdb->query("CREATE INDEX {$index} ON {$table} (meta_key(32), meta_value(64))");
}

// Boot the kernel
add_action('plugins_loaded', ['SD_Core', 'boot']);