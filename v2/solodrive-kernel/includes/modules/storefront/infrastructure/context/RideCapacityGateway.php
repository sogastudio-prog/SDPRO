<?php
if (!defined('ABSPATH')) { exit; }

final class SD_RideCapacityGateway {

  /**
   * Execution states that occupy driver capacity.
   * Adjust if your canonical ride-state service evolves.
   */
  private const OCCUPYING_RIDE_STATES = [
    'RIDE_QUEUED',
    'RIDE_DEADHEAD',
    'RIDE_WAITING',
    'RIDE_ARRIVED',
    'RIDE_INPROGRESS',
  ];

  /**
   * Returns tenant-scoped ride load + capacity.
   *
   * @return array<string,mixed>
   */
  public static function get_capacity_snapshot(
    int $tenant_id,
    SD_StorefrontPolicy $policy,
    int $freshness_seconds = 180
  ) : array {
    $online_driver_ids    = SD_DriverAvailabilityGateway::get_online_driver_ids($tenant_id, $freshness_seconds);
    $available_driver_ids = SD_DriverAvailabilityGateway::get_available_driver_ids($tenant_id, $freshness_seconds);
    $busy_driver_ids      = SD_DriverAvailabilityGateway::get_busy_driver_ids($tenant_id, $freshness_seconds);

    $driver_load = self::driver_occupancy_map($tenant_id);

    $active_rides_total = array_sum($driver_load);
    $available_driver_count = count($available_driver_ids);
    $busy_driver_count = count($busy_driver_ids);
    $online_driver_count = count($online_driver_ids);

    $instant_capacity_remaining = 0;
    $stack_slots_remaining = 0;

    foreach ($available_driver_ids as $driver_id) {
      $load = (int) ($driver_load[$driver_id] ?? 0);

      if ($load < max(1, (int) $policy->max_active_rides_per_driver)) {
        $instant_capacity_remaining++;
      }

      $stack_room = max(
        0,
        ((int) $policy->max_active_rides_per_driver + (int) $policy->max_stacked_rides_per_driver) - $load
      );

      if ($stack_room > 0) {
        $stack_slots_remaining += $stack_room - max(
          0,
          (int) $policy->max_active_rides_per_driver - $load
        );
      }
    }

    foreach ($busy_driver_ids as $driver_id) {
      $load = (int) ($driver_load[$driver_id] ?? 0);

      $max_total = (int) $policy->max_active_rides_per_driver + (int) $policy->max_stacked_rides_per_driver;
      if ($max_total > $load) {
        $stack_slots_remaining += ($max_total - $load);
      }
    }

    return [
      'tenant_id'                   => $tenant_id,
      'online_driver_count'         => $online_driver_count,
      'available_driver_count'      => $available_driver_count,
      'busy_driver_count'           => $busy_driver_count,
      'active_rides'                => $active_rides_total,
      'instant_capacity_remaining'  => max(0, $instant_capacity_remaining),
      'stack_slots_remaining'       => max(0, $stack_slots_remaining),
      'driver_occupancy'            => $driver_load,
    ];
  }

  public static function count_active_rides(int $tenant_id) : int {
    return array_sum(self::driver_occupancy_map($tenant_id));
  }

  /**
   * Counts active rides assigned to drivers for this tenant.
   *
   * Expected meta:
   * - sd_tenant_id
   * - sd_driver_user_id
   * - state
   *
   * @return array<int,int> driver_user_id => ride_count
   */
  public static function driver_occupancy_map(int $tenant_id) : array {
    $ids = get_posts([
      'post_type'      => 'sd_ride',
      'post_status'    => 'any',
      'posts_per_page' => 500,
      'fields'         => 'ids',
      'meta_query'     => [
        'relation' => 'AND',
        [
          'key'   => 'sd_tenant_id',
          'value' => (string) $tenant_id,
        ],
        [
          'key'     => 'state',
          'value'   => self::OCCUPYING_RIDE_STATES,
          'compare' => 'IN',
        ],
      ],
    ]);

    $map = [];

    foreach ($ids as $ride_id) {
      $driver_id = (int) get_post_meta((int) $ride_id, 'sd_driver_user_id', true);
      if ($driver_id < 1) {
        continue;
      }

      if (!isset($map[$driver_id])) {
        $map[$driver_id] = 0;
      }

      $map[$driver_id]++;
    }

    return $map;
  }
}