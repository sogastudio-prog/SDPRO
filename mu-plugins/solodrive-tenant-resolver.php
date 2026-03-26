<?php
/**
 * SoloDrive Tenant Resolver — MU Plugin (early, host-safe)
 */

error_log('[sd_tenant_resolver] file_loaded');
add_action('muplugins_loaded', function() {
  error_log('[sd_tenant_resolver] hook_ran');

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
    if (str_ends_with($host, '.solodrive.pro')) {
      $parts = explode('.', $host);
      $candidate = $parts[0] ?? '';
      $candidate = sanitize_key($candidate);
      if ($candidate && $candidate !== 'app' && $candidate !== 'www') {
        $handle = $candidate;
      }
    }
  }

  // EARLY DEBUG: before lookup
  error_log('[sd_tenant_resolver] pre_lookup host=' . $host . ' handle=' . $handle);

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

    if (!empty($q->posts[0])) {
      $tenant_id = (int) $q->posts[0];
      error_log('[sd_tenant_resolver] slug_match handle=' . $handle . ' tenant_id=' . $tenant_id);
    } else {
      error_log('[sd_tenant_resolver] slug_miss handle=' . $handle);
    }
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

    if (!empty($q->posts[0])) {
      $tenant_id = (int) $q->posts[0];
      error_log('[sd_tenant_resolver] domain_match host=' . $host . ' tenant_id=' . $tenant_id);
    } else {
      error_log('[sd_tenant_resolver] domain_miss host=' . $host);
    }
  }

  // Publish resolved tenant id into runtime
  $GLOBALS['sd_tenant_id'] = $tenant_id;

  if (!defined('SD_TENANT_ID')) {
    define('SD_TENANT_ID', $tenant_id);
  }

  // FINAL DEBUG
  error_log('[sd_tenant_resolver] resolved host=' . $host . ' handle=' . $handle . ' tenant_id=' . $tenant_id);

}, 1);