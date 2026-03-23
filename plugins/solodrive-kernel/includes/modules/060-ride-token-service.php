<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_RideTokenService (v1.2)
 *
 * Purpose:
 * - Generates high-entropy trip tokens
 * - Stores canonical token meta on sd_ride
 * - Maintains token hash + fast token index
 * - Manages expiry + rotation
 *
 * Canon:
 * - Ride must already be tenant-scoped.
 * - Token index is the fast path for /trip/<token> resolution.
 * - Record tenant remains source of truth.
 */

final class SD_Module_RideTokenService {

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

  public static function assign_token(int $ride_id) : string {
    if ($ride_id <= 0 || get_post_type($ride_id) !== 'sd_ride') {
      return '';
    }

    $tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0) {
      return '';
    }

    $existing = (string) get_post_meta($ride_id, SD_Meta::TRIP_TOKEN, true);
    if ($existing !== '') {
      $hash = SD_Module_TripTokenIndex::hash_token($existing);
      update_post_meta($ride_id, SD_Meta::TRIP_TOKEN_HASH, $hash);
      SD_Module_TripTokenIndex::upsert($existing, $ride_id, $tenant_id);
      return $existing;
    }

    $token = self::generate_token();

    update_post_meta($ride_id, SD_Meta::TRIP_TOKEN, $token);
    update_post_meta($ride_id, SD_Meta::TRIP_TOKEN_HASH, SD_Module_TripTokenIndex::hash_token($token));

    delete_post_meta($ride_id, SD_Meta::P_TOKEN_EXPIRES_AT);

    SD_Module_TripTokenIndex::upsert($token, $ride_id, $tenant_id);

    return $token;
  }

  public static function rotate_token(int $ride_id) : string {
    if ($ride_id <= 0 || get_post_type($ride_id) !== 'sd_ride') {
      return '';
    }

    $tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    if ($tenant_id <= 0) {
      return '';
    }

    $new = self::generate_token();

    update_post_meta($ride_id, SD_Meta::TRIP_TOKEN, $new);
    update_post_meta($ride_id, SD_Meta::TRIP_TOKEN_HASH, SD_Module_TripTokenIndex::hash_token($new));
    update_post_meta($ride_id, SD_Meta::P_TOKEN_ROTATED_AT, time());

    delete_post_meta($ride_id, SD_Meta::P_TOKEN_EXPIRES_AT);

    SD_Module_TripTokenIndex::upsert($new, $ride_id, $tenant_id);

    return $new;
  }
}