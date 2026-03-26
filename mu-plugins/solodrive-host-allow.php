<?php
/**
 * SoloDrive Host Allow — MU Plugin (hardened)
 *
 * Purpose:
 * - Prevent WordPress canonical redirects from breaking host-based tenancy
 * - Keep public REST URLs on the current request host
 * - Provide optional admin-only debug output when explicitly enabled
 *
 * Notes:
 * - This MU plugin is intentionally early and host-focused.
 * - Tenant resolution itself lives in solodrive-tenant-resolver.php.
 */

if (!defined('ABSPATH')) { exit; }

final class SoloDrive_Host_Allow_MU {

  /**
   * Set to true temporarily if you want admin-only debug query params.
   * Leave false in normal operation.
   */
  private const ALLOW_DEBUG = true;

  public static function register() : void {
    add_filter('redirect_canonical', [__CLASS__, 'disable_canonical_redirects'], 10, 2);
    add_filter('rest_url', [__CLASS__, 'filter_rest_url'], 10, 4);
    add_action('init', [__CLASS__, 'maybe_debug_output'], 1);
  }

  public static function disable_canonical_redirects($redirect, $requested) {
    return false;
  }

  public static function filter_rest_url($url, $path, $blog_id, $scheme) {
    if (self::should_skip_rest_rewrite()) {
      return $url;
    }

    $host = self::request_host();
    if ($host === '') {
      return $url;
    }

    $parts = wp_parse_url($url);
    if (!is_array($parts)) {
      return $url;
    }

    $scheme_now = is_ssl() ? 'https' : (!empty($parts['scheme']) ? $parts['scheme'] : 'https');
    $path_part  = $parts['path'] ?? '/wp-json/';
    $query_part = !empty($parts['query']) ? ('?' . $parts['query']) : '';
    $frag_part  = !empty($parts['fragment']) ? ('#' . $parts['fragment']) : '';

    return $scheme_now . '://' . $host . $path_part . $query_part . $frag_part;
  }

  public static function maybe_debug_output() : void {
    if (!self::ALLOW_DEBUG) {
      return;
    }

    if (!is_user_logged_in() || !current_user_can('manage_options')) {
      return;
    }

    if (isset($_GET['hostcheck'])) {
      nocache_headers();
      header('Content-Type: text/plain; charset=utf-8');
      echo 'HTTP_HOST=' . self::request_host() . "\n";
      exit;
    }

    if (isset($_GET['sd_debug_tenant'])) {
      nocache_headers();
      header('Content-Type: text/plain; charset=utf-8');
      echo 'HTTP_HOST=' . self::request_host() . "\n";
      echo 'SD_TENANT_ID=' . (defined('SD_TENANT_ID') ? (int) SD_TENANT_ID : 0) . "\n";
      echo 'GLOBAL=' . (int) ($GLOBALS['sd_tenant_id'] ?? 0) . "\n";
      echo 'REQUEST_URI=' . (string) ($_SERVER['REQUEST_URI'] ?? '') . "\n";
      exit;
      
    }
  }

  private static function should_skip_rest_rewrite() : bool {
    if (is_admin()) {
      return true;
    }

    if (wp_doing_ajax()) {
      return true;
    }

    if (wp_doing_cron()) {
      return true;
    }

    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
      return true;
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($uri !== '') {
      if (strpos($uri, '/wp-login.php') !== false) {
        return true;
      }
      if (strpos($uri, '/wp-admin/') !== false) {
        return true;
      }
    }

    return false;
  }

  private static function request_host() : string {
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim((string) $_SERVER['HTTP_HOST'])) : '';
    if ($host === '') {
      return '';
    }

    $host = preg_replace('/:\d+$/', '', $host);
    return is_string($host) ? $host : '';
  }
}

SoloDrive_Host_Allow_MU::register();