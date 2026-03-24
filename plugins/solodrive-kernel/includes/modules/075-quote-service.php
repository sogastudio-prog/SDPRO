<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Canon quote creator:
 * - one active quote path per lead
 * - quote is child of lead
 * - quote is created only after lead is AVAILABLE
 */
final class SD_Module_QuoteService {

  public static function create_for_lead(int $lead_id, array $payload = [], string $source = 'kernel') : int {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) return 0;
    if (!class_exists('SD_Module_LeadCPT') || get_post_type($lead_id) !== SD_Module_LeadCPT::CPT) return 0;

    $tenant_id = (int) get_post_meta($lead_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0) {
      self::log('quote_create_missing_tenant', [
        'lead_id' => $lead_id,
        'source'  => $source,
      ]);
      return 0;
    }

    $lead_status = (string) get_post_meta($lead_id, SD_Meta::LEAD_STATUS, true);
    if (!in_array($lead_status, ['LEAD_AVAILABLE', 'LEAD_QUOTING', 'LEAD_QUOTED'], true)) {
      self::log('quote_create_rejected_bad_lead_state', [
        'lead_id'      => $lead_id,
        'lead_status'  => $lead_status,
        'source'       => $source,
      ]);
      return 0;
    }

    $existing_id = self::find_active_quote_for_lead($lead_id);
    if ($existing_id > 0) {
      update_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, $existing_id);
      return $existing_id;
    }

    update_post_meta($lead_id, SD_Meta::LEAD_STATUS, 'LEAD_QUOTING');
    update_post_meta($lead_id, SD_Meta::P_STATE_UPDATED_AT, time());

    $quote_id = wp_insert_post([
      'post_type'   => SD_Module_QuoteCPT::CPT,
      'post_status' => 'publish',
      'post_title'  => 'Quote — Lead #' . $lead_id . ' — ' . wp_date('Y-m-d H:i:s'),
      'post_author' => get_current_user_id() ?: 0,
    ], true);

    if (is_wp_error($quote_id) || !$quote_id) {
      self::log('quote_create_failed', [
        'lead_id'   => $lead_id,
        'tenant_id' => $tenant_id,
        'source'    => $source,
        'error'     => is_wp_error($quote_id) ? $quote_id->get_error_message() : 'unknown',
      ]);
      return 0;
    }

    $quote_id = (int) $quote_id;

    update_post_meta($quote_id, SD_Meta::TENANT_ID, $tenant_id);
    update_post_meta($quote_id, SD_Meta::LEAD_ID, $lead_id);
    update_post_meta($quote_id, SD_Meta::QUOTE_STATUS, SD_Quote_State::DRAFT);

    if (!empty($payload)) {
      update_post_meta($quote_id, '_sd_quote_payload', wp_json_encode($payload));
    }

    update_post_meta($quote_id, '_sd_quote_source', sanitize_key($source));
    update_post_meta($quote_id, '_sd_quote_created_at', time());

    update_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, $quote_id);

    self::log('quote_created', [
      'quote_id'   => $quote_id,
      'lead_id'    => $lead_id,
      'tenant_id'  => $tenant_id,
      'source'     => $source,
    ]);

    return $quote_id;
  }

  public static function find_active_quote_for_lead(int $lead_id) : int {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) return 0;

    $current = (int) get_post_meta($lead_id, SD_Meta::CURRENT_QUOTE_ID, true);
    if ($current > 0 && get_post_type($current) === SD_Module_QuoteCPT::CPT) {
      $status = (string) get_post_meta($current, SD_Meta::QUOTE_STATUS, true);
      if (!in_array($status, ['SUPERSEDED', 'REJECTED', 'EXPIRED', 'CANCELLED'], true)) {
        return $current;
      }
    }

    $q = new \WP_Query([
      'post_type'      => SD_Module_QuoteCPT::CPT,
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'orderby'        => 'ID',
      'order'          => 'DESC',
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'   => SD_Meta::LEAD_ID,
          'value' => $lead_id,
          'type'  => 'NUMERIC',
        ],
      ],
    ]);

    if (empty($q->posts[0])) {
      return 0;
    }

    $quote_id = (int) $q->posts[0];
    $status   = (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true);

    if (in_array($status, ['SUPERSEDED', 'REJECTED', 'EXPIRED', 'CANCELLED'], true)) {
      return 0;
    }

    return $quote_id;
  }

  public static function supersede_active_quote(int $lead_id, int $except_quote_id = 0) : void {
    $lead_id = absint($lead_id);
    $except_quote_id = absint($except_quote_id);

    if ($lead_id <= 0) return;

    $q = new \WP_Query([
      'post_type'      => SD_Module_QuoteCPT::CPT,
      'post_status'    => 'any',
      'posts_per_page' => 20,
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'   => SD_Meta::LEAD_ID,
          'value' => $lead_id,
          'type'  => 'NUMERIC',
        ],
      ],
    ]);

    foreach ((array) $q->posts as $quote_id) {
      $quote_id = (int) $quote_id;
      if ($quote_id === $except_quote_id) continue;

      $status = (string) get_post_meta($quote_id, SD_Meta::QUOTE_STATUS, true);
      if (in_array($status, ['DRAFT', 'PENDING_OPERATOR', 'APPROVED', 'PRESENTED'], true)) {
        SD_Module_QuoteStateService::set($quote_id, SD_Meta::QUOTE_SUPERSEDED, [
          'lead_id' => $lead_id,
          'reason'  => 'new_active_quote',
        ]);
      }
    }
  }

  private static function log(string $event, array $ctx = []) : void {
    if (class_exists('SD_Util')) {
      SD_Util::log($event, $ctx);
    }
  }
}