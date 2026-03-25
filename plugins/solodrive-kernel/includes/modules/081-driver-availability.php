<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_DriverAvailability {

  public const U_THIRD_PARTY_ACTIVE      = 'sd_driver_third_party_active';
  public const U_THIRD_PARTY_UPDATED_AT  = 'sd_driver_third_party_updated_at';
  public const U_THIRD_PARTY_PROVIDER    = 'sd_driver_third_party_provider';

  public const U_SOLODRIVE_PAUSED        = 'sd_driver_solodrive_paused';
  public const U_SOLODRIVE_PAUSED_AT     = 'sd_driver_solodrive_paused_at';

  /**
   * Toggle third-party occupancy state for a driver.
   *
   * Canon:
   * - third-party occupancy excludes driver from SoloDrive on-demand availability
   * - occupancy changes are ledgered as observed events
   * - no duplicate ledger writes when requested state matches current state
   *
   * @param int    $driver_id
   * @param int    $tenant_id
   * @param bool   $active
   * @param float  $lat
   * @param float  $lng
   * @param string $provider  Optional: uber | lyft | other | unknown
   * @param int    $actor_id  Optional actor id; defaults to driver_id
   */
  public static function set_third_party_state(
    int $driver_id,
    int $tenant_id,
    bool $active,
    float $lat = 0.0,
    float $lng = 0.0,
    string $provider = 'unknown',
    int $actor_id = 0
  ) : bool {
    $driver_id = absint($driver_id);
    $tenant_id = absint($tenant_id);
    $actor_id  = absint($actor_id);

    if ($driver_id <= 0 || $tenant_id <= 0) {
      return false;
    }

    $current = (int) get_user_meta($driver_id, self::U_THIRD_PARTY_ACTIVE, true);
    $next    = $active ? 1 : 0;

    if ($current === $next) {
      return true;
    }

    $provider = self::normalize_provider($provider);
    $now      = time();

    update_user_meta($driver_id, self::U_THIRD_PARTY_ACTIVE, $next);
    update_user_meta($driver_id, self::U_THIRD_PARTY_UPDATED_AT, $now);
    update_user_meta($driver_id, self::U_THIRD_PARTY_PROVIDER, $provider);

    $event_type = $active
      ? SD_TimeSpace_EventType::THIRD_PARTY_STARTED
      : SD_TimeSpace_EventType::THIRD_PARTY_ENDED;

    $payload = [
      'tenant_id'    => $tenant_id,
      'event_type'   => $event_type,
      'truth_class'  => SD_Module_TimeSpaceLedger::TRUTH_OBSERVED,
      'subject_type' => SD_Module_TimeSpaceLedger::SUBJECT_DRIVER,
      'subject_id'   => $driver_id,
      'actor_type'   => ($actor_id > 0 && $actor_id !== $driver_id)
        ? SD_Module_TimeSpaceLedger::ACTOR_OPERATOR
        : SD_Module_TimeSpaceLedger::ACTOR_DRIVER,
      'actor_id'     => ($actor_id > 0) ? $actor_id : $driver_id,
      'driver_id'    => $driver_id,
      'start_ts'     => $now,
      'payload'      => [
        'third_party_active' => (bool) $active,
        'provider'           => $provider,
        'source'             => 'driver_availability',
      ],
    ];

    if (abs($lat) > 0.0001 && abs($lng) > 0.0001) {
      $payload['start_lat'] = $lat;
      $payload['start_lng'] = $lng;
    }

    SD_Module_TimeSpaceLedger::write($payload);

    if (class_exists('SD_Util')) {
      SD_Util::log('driver_third_party_state_changed', [
        'driver_id' => $driver_id,
        'tenant_id' => $tenant_id,
        'active'    => $active,
        'provider'  => $provider,
        'lat'       => $lat,
        'lng'       => $lng,
        'actor_id'  => ($actor_id > 0) ? $actor_id : $driver_id,
      ]);
    }

    return true;
  }

  /**
   * Toggle plain SoloDrive pause state.
   *
   * Canon:
   * - paused means not accepting new SoloDrive work
   * - paused is NOT the same as third-party occupied
   * - pause changes are ledgered as observed events
   */
  public static function set_pause_state(
    int $driver_id,
    int $tenant_id,
    bool $paused,
    float $lat = 0.0,
    float $lng = 0.0,
    int $actor_id = 0
  ) : bool {
    $driver_id = absint($driver_id);
    $tenant_id = absint($tenant_id);
    $actor_id  = absint($actor_id);

    if ($driver_id <= 0 || $tenant_id <= 0) {
      return false;
    }

    $current = (int) get_user_meta($driver_id, self::U_SOLODRIVE_PAUSED, true);
    $next    = $paused ? 1 : 0;

    if ($current === $next) {
      return true;
    }

    $now = time();

    update_user_meta($driver_id, self::U_SOLODRIVE_PAUSED, $next);
    update_user_meta($driver_id, self::U_SOLODRIVE_PAUSED_AT, $now);

    $event_type = $paused
      ? 'DRIVER_PAUSED_STARTED'
      : 'DRIVER_PAUSED_ENDED';

    $payload = [
      'tenant_id'    => $tenant_id,
      'event_type'   => $event_type,
      'truth_class'  => SD_Module_TimeSpaceLedger::TRUTH_OBSERVED,
      'subject_type' => SD_Module_TimeSpaceLedger::SUBJECT_DRIVER,
      'subject_id'   => $driver_id,
      'actor_type'   => ($actor_id > 0 && $actor_id !== $driver_id)
        ? SD_Module_TimeSpaceLedger::ACTOR_OPERATOR
        : SD_Module_TimeSpaceLedger::ACTOR_DRIVER,
      'actor_id'     => ($actor_id > 0) ? $actor_id : $driver_id,
      'driver_id'    => $driver_id,
      'start_ts'     => $now,
      'payload'      => [
        'solodrive_paused' => (bool) $paused,
        'source'           => 'driver_availability',
      ],
    ];

    if (abs($lat) > 0.0001 && abs($lng) > 0.0001) {
      $payload['start_lat'] = $lat;
      $payload['start_lng'] = $lng;
    }

    SD_Module_TimeSpaceLedger::write($payload);

    if (class_exists('SD_Util')) {
      SD_Util::log('driver_pause_state_changed', [
        'driver_id' => $driver_id,
        'tenant_id' => $tenant_id,
        'paused'    => $paused,
        'lat'       => $lat,
        'lng'       => $lng,
        'actor_id'  => ($actor_id > 0) ? $actor_id : $driver_id,
      ]);
    }

    return true;
  }

  public static function is_third_party_active(int $driver_id) : bool {
    $driver_id = absint($driver_id);
    if ($driver_id <= 0) {
      return false;
    }

    return ((int) get_user_meta($driver_id, self::U_THIRD_PARTY_ACTIVE, true) === 1);
  }

  public static function is_paused(int $driver_id) : bool {
    $driver_id = absint($driver_id);
    if ($driver_id <= 0) {
      return false;
    }

    return ((int) get_user_meta($driver_id, self::U_SOLODRIVE_PAUSED, true) === 1);
  }

  public static function current_provider(int $driver_id) : string {
    $driver_id = absint($driver_id);
    if ($driver_id <= 0) {
      return 'unknown';
    }

    return self::normalize_provider((string) get_user_meta($driver_id, self::U_THIRD_PARTY_PROVIDER, true));
  }

  private static function normalize_provider(string $provider) : string {
    $provider = strtolower(trim($provider));

    if (!in_array($provider, ['uber', 'lyft', 'other', 'unknown'], true)) {
      $provider = 'unknown';
    }

    return $provider;
  }
}