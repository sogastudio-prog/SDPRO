<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_QuoteService (v1.1)
 *
 * Purpose:
 * - Create quotes canonically and ALWAYS tenant-scope them.
 * - Enforce locked relationship:
 *     1 ride_id -> 1 quote_id
 *
 * Tenant source of truth:
 * - Prefer ride's SD_Meta::TENANT_ID if present
 * - Else fall back to SD_Module_TenantResolver::current_tenant_id()
 *
 * Rules:
 * - Never create an unscoped quote.
 * - Never create a second quote for the same ride.
 * - Keep canonical ride -> quote linkage hot on the ride record.
 */

final class SD_Module_QuoteService {

  public static function create_for_ride(int $ride_id, array $payload = [], string $source = 'kernel') : int {
    if ($ride_id <= 0) return 0;
    if (!class_exists('SD_Module_RideCPT') || get_post_type($ride_id) !== SD_Module_RideCPT::CPT) return 0;
    if (!class_exists('SD_Module_QuoteCPT')) return 0;

    // -----------------------------------------------------------------------
    // Enforce 1 ride -> 1 quote
    // -----------------------------------------------------------------------

    // Prefer canonical ride pointer first.
    $existing = (int) get_post_meta($ride_id, SD_Meta::QUOTE_ID, true);
    if ($existing > 0 && get_post_type($existing) === SD_Module_QuoteCPT::CPT) {
      return $existing;
    }

    // Fall back to older convenience pointer if present.
    $latest = (int) get_post_meta($ride_id, '_sd_latest_quote_id', true);
    if ($latest > 0 && get_post_type($latest) === SD_Module_QuoteCPT::CPT) {
      // Heal canonical pointer.
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, (string) $latest);
      return $latest;
    }

    // Final fallback: query for any existing quote linked to this ride.
    $found = self::find_existing_quote_for_ride($ride_id);
    if ($found > 0) {
      update_post_meta($ride_id, SD_Meta::QUOTE_ID, (string) $found);
      update_post_meta($ride_id, '_sd_latest_quote_id', (string) $found);
      return $found;
    }

    // -----------------------------------------------------------------------
    // Tenant MUST be determined before creating quote
    // -----------------------------------------------------------------------
    $tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0 && class_exists('SD_Module_TenantResolver')) {
      $tenant_id = (int) SD_Module_TenantResolver::current_tenant_id();
    }

    if ($tenant_id <= 0) {
      if (class_exists('SD_Util')) {
        SD_Util::log('quote_create_missing_tenant', [
          'ride_id' => $ride_id,
          'source'  => $source,
        ]);
      }
      return 0;
    }

    // -----------------------------------------------------------------------
    // Create canonical quote
    // -----------------------------------------------------------------------
    $qid = wp_insert_post([
      'post_type'   => SD_Module_QuoteCPT::CPT,
      'post_status' => 'publish',
      'post_title'  => 'Quote - Ride #' . $ride_id . ' - ' . wp_date('Y-m-d H:i:s'),
      'post_author' => get_current_user_id() ?: 0,
    ], true);

    if (is_wp_error($qid) || !$qid) {
      if (class_exists('SD_Util')) {
        SD_Util::log('quote_create_failed', [
          'ride_id'   => $ride_id,
          'tenant_id' => $tenant_id,
          'source'    => $source,
          'error'     => is_wp_error($qid) ? $qid->get_error_message() : 'unknown',
        ]);
      }
      return 0;
    }

    $qid = (int) $qid;

    // Canonical linkage + tenant scoping
    update_post_meta($qid, SD_Meta::RIDE_ID, (string) $ride_id);
    update_post_meta($qid, SD_Meta::TENANT_ID, (string) $tenant_id);

    // Default lifecycle
    if ((string) get_post_meta($qid, SD_Meta::QUOTE_STATUS, true) === '') {
      update_post_meta($qid, SD_Meta::QUOTE_STATUS, 'PROPOSED');
    }

    // Optional private payload snapshot
    if (!empty($payload)) {
      update_post_meta($qid, '_sd_quote_payload', wp_json_encode($payload));
    }

    update_post_meta($qid, '_sd_quote_source', sanitize_key($source));
    update_post_meta($qid, '_sd_quote_created_at', (string) time());

    // Keep ride -> quote pointers hot
    update_post_meta($ride_id, SD_Meta::QUOTE_ID, (string) $qid);
    update_post_meta($ride_id, '_sd_latest_quote_id', (string) $qid);

    if (class_exists('SD_Util')) {
      SD_Util::log('quote_created', [
        'quote_id'   => $qid,
        'ride_id'    => $ride_id,
        'tenant_id'  => $tenant_id,
        'source'     => $source,
      ]);
    }

    return $qid;
  }

  private static function find_existing_quote_for_ride(int $ride_id) : int {
    if ($ride_id <= 0) return 0;

    $q = new \WP_Query([
      'no_found_rows'  => true,
      'post_type'      => SD_Module_QuoteCPT::CPT,
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'orderby'        => 'ID',
      'order'          => 'ASC',
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'     => SD_Meta::RIDE_ID,
        'value'   => (string) $ride_id,
        'compare' => '=',
      ]],
    ]);

    return !empty($q->posts[0]) ? (int) $q->posts[0] : 0;
  }
}