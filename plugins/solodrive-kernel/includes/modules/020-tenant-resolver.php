<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_TenantResolver (v1.3)
 *
 * Resolution order (LOCKED doctrine):
 * 0) Trip token resolution (sd_trip_token OR /trip/<token>) → ride → sd_tenant_id
 * 1) Query var override (sd_tenant) → tenant meta sd_tenant_slug
 * 2) Hosted handle resolution: {tenant}.solodrive.pro → tenant meta sd_tenant_slug
 * 3) Custom domain exact match (HTTP_HOST) → tenant meta sd_tenant_domain
 * 4) Fallback option SD_Module_TenantCPT::OPT_CURRENT_TENANT_ID (dev/admin convenience only)
 *
 * Rules:
 * - Fail soft (never fatal)
 * - Never cross-bleed tenant data
 */
final class SD_Module_TenantResolver {

  private static int $tenant_id = 0;
  private static bool $resolved = false;

  // Canonical hosted parent zone (LOCKED)
  private const HOSTED_PARENT_DOMAIN = 'solodrive.pro';

  public static function register() : void {
    add_filter('query_vars', [__CLASS__, 'register_query_vars']);

    // Resolve as early as possible for AJAX/REST contexts (CF7 submissions, admin-ajax, wp-json).
    add_action('plugins_loaded', [__CLASS__, 'resolve'], 1);

    // Keep init as a safe backstop.
    add_action('init', [__CLASS__, 'resolve'], 2);
  }

  public static function register_query_vars(array $vars) : array {
    $vars[] = 'sd_tenant';
    $vars[] = 'sd_trip_token';
    return $vars;
  }

  /**
   * Public getter — MUST be safe even if resolve() never ran (e.g., late module load).
   */
  public static function current_tenant_id() : int {
    self::ensure_resolved();
    return self::$tenant_id;
  }

  private static function ensure_resolved() : void {
    if (self::$resolved) return;
    self::resolve();
  }

  public static function resolve() : void {
    // Idempotent: resolve only once per request unless explicitly re-run.
    if (self::$resolved) return;
    self::$resolved = true;

    // 0) Trip token resolution: token → ride → sd_tenant_id
    $token = self::request_trip_token();
    if ($token !== '') {
      $by_token = self::find_tenant_by_trip_token($token);
      if ($by_token > 0) {
        self::$tenant_id = $by_token;
        return;
      }
    }

    // 1) Query var override (slug routing / admin tooling)
    $q = get_query_var('sd_tenant');
    if (is_string($q) && $q !== '') {
      $by_slug = self::find_tenant_by_slug(sanitize_key($q));
      if ($by_slug > 0) {
        self::$tenant_id = $by_slug;
        return;
      }
    }

    // Normalize host (primary: HTTP_HOST)
    $host = self::request_host();
    if ($host === '') {
      self::$tenant_id = self::fallback_tenant_id();
      return;
    }

    // 2) Hosted handle resolution: {handle}.solodrive.pro → sd_tenant_slug
    $handle = self::extract_hosted_handle($host);
    if ($handle !== '') {
      $by_handle = self::find_tenant_by_slug($handle);
      if ($by_handle > 0) {
        self::$tenant_id = $by_handle;
        return;
      }
    }

    // 3) Custom domain exact match: HTTP_HOST → sd_tenant_domain
    $by_domain = self::find_tenant_by_domain($host);
    if ($by_domain > 0) {
      self::$tenant_id = $by_domain;
      return;
    }

    // 4) Fallback option (dev/admin convenience)
    self::$tenant_id = self::fallback_tenant_id();
  }

  // ---------------------------------------------------------------------------
  // Trip token helpers
  // ---------------------------------------------------------------------------

  /**
   * Extract trip token from:
   * - query var sd_trip_token (if rewrite populated)
   * - raw REQUEST_URI path /trip/<token>
   */
  private static function request_trip_token() : string {
    $q = get_query_var('sd_trip_token');
    if (is_string($q) && $q !== '') {
      return self::sanitize_trip_token($q);
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '') return '';

    $path = (string) wp_parse_url($uri, PHP_URL_PATH);
    if ($path === '') return '';

    // Match /trip/<token> or /trip/<token>/
    if (!preg_match('#^/trip/([^/]+)/?$#', $path, $m)) return '';
    return self::sanitize_trip_token((string) ($m[1] ?? ''));
  }

  private static function sanitize_trip_token(string $token) : string {
    $token = trim($token);

    // Allow base62 and base64url-ish tokens.
    // (Keep permissive; DB lookup is the real guard.)
    if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token)) return '';

    return $token;
  }

  private static function find_tenant_by_trip_token(string $token) : int {
    if ($token === '') return 0;

    $q = new \WP_Query([
      'post_type'      => SD_CPT_Ride::CPT,
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::TRIP_TOKEN,
        'value'   => $token,
        'compare' => '=',
      ]],
      'no_found_rows'  => true,
    ]);

    if (empty($q->posts)) return 0;
    $ride_id = (int) $q->posts[0];

    $tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0) return 0;

    // Optional validation: ensure it's really a tenant record.
    if (class_exists('SD_Module_TenantCPT')) {
      if (get_post_type($tenant_id) !== SD_Module_TenantCPT::CPT) return 0;
    }

    return $tenant_id;
  }

  // ---------------------------------------------------------------------------
  // Internals
  // ---------------------------------------------------------------------------

  /**
   * Request host resolver that works in front-end, admin-ajax, and wp-json contexts.
   */
  private static function request_host() : string {
    $host = sanitize_text_field((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = strtolower(preg_replace('/:\d+$/', '', $host)); // strip port if any
    if ($host !== '') return $host;

    // Fallback for some REST/AJAX/proxy cases: infer from Origin/Referer
    $ref = '';
    if (!empty($_SERVER['HTTP_ORIGIN']))  $ref = (string) $_SERVER['HTTP_ORIGIN'];
    if ($ref === '' && !empty($_SERVER['HTTP_REFERER'])) $ref = (string) $_SERVER['HTTP_REFERER'];

    if ($ref !== '') {
      $u = wp_parse_url($ref);
      $h = isset($u['host']) ? strtolower((string) $u['host']) : '';
      $h = strtolower(preg_replace('/:\d+$/', '', $h));
      if ($h !== '') return $h;
    }

    return '';
  }

  private static function extract_hosted_handle(string $host) : string {
    $suffix = '.' . self::HOSTED_PARENT_DOMAIN;
    if (!str_ends_with($host, $suffix)) return '';

    $parts = explode('.', $host);
    $candidate = sanitize_key((string) ($parts[0] ?? ''));

    // Protect reserved subdomains
    if ($candidate === '' || $candidate === 'app' || $candidate === 'www') return '';

    return $candidate;
  }

  private static function fallback_tenant_id() : int {
    $fallback = (int) get_option(SD_Module_TenantCPT::OPT_CURRENT_TENANT_ID, 0);
    if ($fallback > 0 && get_post_type($fallback) === SD_Module_TenantCPT::CPT) {
      return $fallback;
    }
    return 0;
  }

  private static function find_tenant_by_domain(string $domain) : int {
    $q = new \WP_Query([
      'post_type'      => SD_Module_TenantCPT::CPT,
      'post_status'    => 'publish',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::TENANT_DOMAIN,
        'value'   => $domain,
        'compare' => '=',
      ]],
      'no_found_rows'  => true,
    ]);
    return !empty($q->posts) ? (int) $q->posts[0] : 0;
  }

  private static function find_tenant_by_slug(string $slug) : int {
    $q = new \WP_Query([
      'post_type'      => SD_Module_TenantCPT::CPT,
      'post_status'    => 'publish',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::TENANT_SLUG,
        'value'   => $slug,
        'compare' => '=',
      ]],
      'no_found_rows'  => true,
    ]);
    return !empty($q->posts) ? (int) $q->posts[0] : 0;
  }
}

