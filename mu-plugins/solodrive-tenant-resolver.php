<?php
/**
 * SoloDrive Tenant Resolver — MU Plugin (early, host-safe)
 *
 * Resolves tenant by:
 * 1) ?sd_tenant=<handle>
 * 2) {handle}.solodrive.pro subdomain
 * 3) exact custom domain match
 *
 * Writes:
 * - global $sd_tenant_id
 * - defines SD_TENANT_ID constant for code that prefers constants
 */

add_action('muplugins_loaded', function() {

  // Do not resolve in wp-admin unless explicitly requested (keeps admin stable).
  if (is_admin() && !defined('DOING_AJAX')) return;

  $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
  $host = preg_replace('/:\d+$/', '', $host); // strip port if present

  $handle = '';
  if (isset($_GET['sd_tenant'])) {
    $handle = sanitize_key((string) wp_unslash($_GET['sd_tenant']));
  }

  // Hosted handle: {handle}.solodrive.pro
  if (!$handle && $host) {
    // Only treat as handle if it ends with ".solodrive.pro"
    if (str_ends_with($host, '.solodrive.pro')) {
      $parts = explode('.', $host);
      $candidate = $parts[0] ?? '';
      $candidate = sanitize_key($candidate);
      if ($candidate && $candidate !== 'app' && $candidate !== 'www') {
        $handle = $candidate;
      }
    }
  }

  // Query by handle first
  $tenant_id = 0;

  if ($handle) {
    $q = new WP_Query([
      'post_type'      => 'sd_tenant',
      'post_status'    => 'publish',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'   => 'sd_tenant_slug',
        'value' => $handle,
      ]],
    ]);
    if (!empty($q->posts[0])) $tenant_id = (int)$q->posts[0];
  }

  // If still not found, try custom domain exact match
  if (!$tenant_id && $host) {
    $q = new WP_Query([
      'post_type'      => 'sd_tenant',
      'post_status'    => 'publish',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'   => 'sd_tenant_domain',
        'value' => $host,
      ]],
    ]);
    if (!empty($q->posts[0])) $tenant_id = (int)$q->posts[0];
  }

  // Publish resolved tenant id into runtime
  $GLOBALS['sd_tenant_id'] = $tenant_id;

  if (!defined('SD_TENANT_ID')) {
    define('SD_TENANT_ID', $tenant_id);
  }

}, 1);