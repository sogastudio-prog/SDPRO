<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_KernelGuardrails (v1.2)
 *
 * Purpose:
 * - Enforce meta namespace rules:
 *   - Public keys: sd_*
 *   - Private/system keys: _sd_*
 * - But allow WordPress core to write its own private keys (e.g. _edit_lock)
 *
 * Notes:
 * - This MUST fail soft (never fatal).
 */

if (class_exists('SD_Module_KernelGuardrails', false)) { return; }

final class SD_Module_KernelGuardrails {

  // Allow WP core/private keys that are not ours.
  private static function wp_core_allowlist(string $key) : bool {
    static $allow = [
      '_edit_lock',
      '_edit_last',
      '_wp_old_slug',
      '_wp_trash_meta_status',
      '_wp_trash_meta_time',
      '_thumbnail_id',
      '_menu_item_type',
      '_menu_item_menu_item_parent',
      '_menu_item_object',
      '_menu_item_object_id',
      '_menu_item_target',
      '_menu_item_classes',
      '_menu_item_xfn',
      '_menu_item_url',
    ];
    return in_array($key, $allow, true);
  }

  public static function register() : void {
    add_filter('is_protected_meta', [__CLASS__, 'is_protected_meta'], 10, 3);
    add_filter('sanitize_meta', [__CLASS__, 'sanitize_meta'], 10, 4);
  }

  public static function is_protected_meta($protected, $meta_key, $meta_type) {
    if (!is_string($meta_key)) return $protected;

    // Our private keys are protected (normal WP behavior).
    if (strpos($meta_key, '_sd_') === 0) return true;

    // Allow WP core keys (do not flag).
    if (strpos($meta_key, '_') === 0 && self::wp_core_allowlist($meta_key)) {
      return $protected;
    }

    return $protected;
  }

  public static function sanitize_meta($meta_value, $meta_key, $meta_type, $object_subtype) {
    if (!is_string($meta_key)) return $meta_value;

    // Allow WP core private keys (no logging/no blocking).
    if (strpos($meta_key, '_') === 0 && self::wp_core_allowlist($meta_key)) {
      return $meta_value;
    }

    // Enforce namespace (fail soft: do not fatal; just log once per request per key).
    $is_public_ok  = (strpos($meta_key, 'sd_') === 0);
    $is_private_ok = (strpos($meta_key, '_sd_') === 0);

    if (!$is_public_ok && !$is_private_ok) {
      // Fail soft: just log.
      if (function_exists('error_log')) {
        static $seen = [];
        $k = $meta_type . ':' . $meta_key;
        if (!isset($seen[$k])) {
          $seen[$k] = true;
          error_log('[solodrive] {"sd":true,"event":"meta_key_violation","ctx":{"meta_type":"' . esc_html($meta_type) . '","key":"' . esc_html($meta_key) . '"}}');
        }
      }
    }

    return $meta_value;
  }
}

SD_Module_KernelGuardrails::register();