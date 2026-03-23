<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Core — Kernel runtime
 *
 * Responsibilities:
 * - load registries + utilities
 * - load modules
 * - provide minimal health endpoint/shortcode for verification
 *
 * Doctrine:
 * - tenant-first
 * - sd_* meta only (private: _sd_*)
 * - fail-soft (never fatal)
 */
final class SD_Core {

  private static bool $booted = false;

  public static function boot() : void {
    if (self::$booted) return;
    self::$booted = true;

    require_once SD_KERNEL_PATH . 'includes/sd-meta.php';
    require_once SD_KERNEL_PATH . 'includes/utils.php';
    require_once SD_KERNEL_PATH . 'includes/module-loader.php';

    SD_Module_Loader::load_modules_dir(SD_KERNEL_PATH . 'includes/modules/');

    // Minimal proof-of-life surface
    add_shortcode('sd_kernel_health', [__CLASS__, 'shortcode_health']);
  }

  public static function shortcode_health($atts = []) : string {
    $v = defined('SD_KERNEL_VERSION') ? SD_KERNEL_VERSION : 'unknown';
    $time = gmdate('c');

    return SD_Util::card(
      'SoloDrive Kernel Online',
      [
        'version' => $v,
        'utc'     => $time,
        'meta_ns' => 'sd_* / _sd_*',
        'tenant_id' => SD_Module_TenantResolver::current_tenant_id()
      ]
    );
  }
}