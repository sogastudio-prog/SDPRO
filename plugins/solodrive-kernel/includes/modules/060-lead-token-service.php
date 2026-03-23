<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_LeadTokenService
 *
 * Canon:
 * - Tokens belong to sd_lead.
 * - /trip/<token> resolves to lead.
 */
final class SD_Module_LeadTokenService {

  private const TOKEN_LENGTH = 24;

  public static function register() : void {
    // no hooks
  }

  public static function generate_token() : string {
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $len   = strlen($chars);
    $token = '';

    for ($i = 0; $i < self::TOKEN_LENGTH; $i++) {
      $token .= $chars[random_int(0, $len - 1)];
    }

    return $token;
  }

  public static function assign_token(int $lead_id) : string {
    if ($lead_id <= 0 || get_post_type($lead_id) !== 'sd_lead') {
      return '';
    }

    $tenant_id = (int) get_post_meta($lead_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0) {
      return '';
    }

    $existing = (string) get_post_meta($lead_id, SD_Meta::TRIP_TOKEN, true);
    if ($existing !== '') {
      update_post_meta($lead_id, SD_Meta::TRIP_TOKEN_HASH, SD_Module_TripTokenIndex::hash_token($existing));
      SD_Module_TripTokenIndex::upsert($existing, $lead_id, $tenant_id);
      return $existing;
    }

    $token = self::generate_token();

    update_post_meta($lead_id, SD_Meta::TRIP_TOKEN, $token);
    update_post_meta($lead_id, SD_Meta::TRIP_TOKEN_HASH, SD_Module_TripTokenIndex::hash_token($token));
    delete_post_meta($lead_id, SD_Meta::P_TOKEN_EXPIRES_AT);

    SD_Module_TripTokenIndex::upsert($token, $lead_id, $tenant_id);

    return $token;
  }

  public static function rotate_token(int $lead_id) : string {
    if ($lead_id <= 0 || get_post_type($lead_id) !== 'sd_lead') {
      return '';
    }

    $tenant_id = (int) get_post_meta($lead_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0) {
      return '';
    }

    $new = self::generate_token();

    update_post_meta($lead_id, SD_Meta::TRIP_TOKEN, $new);
    update_post_meta($lead_id, SD_Meta::TRIP_TOKEN_HASH, SD_Module_TripTokenIndex::hash_token($new));
    update_post_meta($lead_id, SD_Meta::P_TOKEN_ROTATED_AT, time());
    delete_post_meta($lead_id, SD_Meta::P_TOKEN_EXPIRES_AT);

    SD_Module_TripTokenIndex::upsert($new, $lead_id, $tenant_id);

    return $new;
  }
}
