<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_DriverAvailability {

  public static function set_third_party_state(int $driver_id, int $tenant_id, bool $active, float $lat, float $lng) : bool {

    $driver_id = absint($driver_id);
    $tenant_id = absint($tenant_id);

    if ($driver_id <= 0 || $tenant_id <= 0) {
      return false;
    }

    $now = time();

    // Update driver state (simple v1)
    update_user_meta($driver_id, 'sd_driver_third_party_active', $active ? 1 : 0);
    update_user_meta($driver_id, 'sd_driver_third_party_updated_at', $now);

    // Ledger event
    $event_type = $active
      ? SD_TimeSpace_EventType::THIRD_PARTY_STARTED
      : SD_TimeSpace_EventType::THIRD_PARTY_ENDED;

    SD_Module_TimeSpaceLedger::write([
      'tenant_id'  => $tenant_id,
      'driver_id'  => $driver_id,
      'event_type' => $event_type,
      'start_ts'   => $now,
      'start_lat'  => $lat,
      'start_lng'  => $lng,
    ]);

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