<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_DriverAvailability {

  public static function set_third_party_state(int $driver_id, int $tenant_id, bool $active, float $lat = 0.0, float $lng = 0.0) : bool {
    $driver_id = absint($driver_id);
    $tenant_id = absint($tenant_id);

    if ($driver_id <= 0 || $tenant_id <= 0) {
      return false;
    }

    $current = (int) get_user_meta($driver_id, 'sd_driver_third_party_active', true);
    $next    = $active ? 1 : 0;

    if ($current === $next) {
      return true;
    }

    $now = time();

    update_user_meta($driver_id, 'sd_driver_third_party_active', $next);
    update_user_meta($driver_id, 'sd_driver_third_party_updated_at', $now);

    $event_type = $active
      ? SD_TimeSpace_EventType::THIRD_PARTY_STARTED
      : SD_TimeSpace_EventType::THIRD_PARTY_ENDED;

    $payload = [
      'tenant_id'  => $tenant_id,
      'driver_id'  => $driver_id,
      'event_type' => $event_type,
      'start_ts'   => $now,
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
        'lat'       => $lat,
        'lng'       => $lng,
      ]);
    }

    return true;
  }
}