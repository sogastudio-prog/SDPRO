<?php
if (!defined('ABSPATH')) { exit; }

final class SD_DriverAvailabilityGateway {

  public const ROLE_DRIVER = 'sd_driver';

  /**
   * Driver is considered "online" only when:
   * - user has driver role
   * - user belongs to tenant
   * - status is available or busy
   * - recent heartbeat is within freshness threshold
   */
  public static function count_online_drivers(
    int $tenant_id,
    int $freshness_seconds = 180
  ) : int {
    return count(self::get_online_driver_ids($tenant_id, $freshness_seconds));
  }

  /**
   * Driver is considered "available" when:
   * - online
   * - status = available
   */
  public static function count_available_drivers(
    int $tenant_id,
    int $freshness_seconds = 180
  ) : int {
    return count(self::get_available_driver_ids($tenant_id, $freshness_seconds));
  }

  /**
   * Driver is considered "busy but online" when:
   * - online
   * - status = busy
   */
  public static function count_busy_drivers(
    int $tenant_id,
    int $freshness_seconds = 180
  ) : int {
    return count(self::get_busy_driver_ids($tenant_id, $freshness_seconds));
  }

  /**
   * @return array<int>
   */
  public static function get_online_driver_ids(
    int $tenant_id,
    int $freshness_seconds = 180
  ) : array {
    $users = self::tenant_driver_users($tenant_id);
    if (!$users) {
      return [];
    }

    $now = time();
    $ids = [];

    foreach ($users as $user) {
      $user_id = (int) $user->ID;
      $status  = self::driver_status($user_id);
      $last_ts = self::driver_last_ping_ts($user_id);

      if (!in_array($status, ['available', 'busy'], true)) {
        continue;
      }

      if ($last_ts < 1 || ($now - $last_ts) > $freshness_seconds) {
        continue;
      }

      $ids[] = $user_id;
    }

    return $ids;
  }

  /**
   * @return array<int>
   */
  public static function get_available_driver_ids(
    int $tenant_id,
    int $freshness_seconds = 180
  ) : array {
    $users = self::tenant_driver_users($tenant_id);
    if (!$users) {
      return [];
    }

    $now = time();
    $ids = [];

    foreach ($users as $user) {
      $user_id = (int) $user->ID;
      $status  = self::driver_status($user_id);
      $last_ts = self::driver_last_ping_ts($user_id);

      if ($status !== 'available') {
        continue;
      }

      if ($last_ts < 1 || ($now - $last_ts) > $freshness_seconds) {
        continue;
      }

      $ids[] = $user_id;
    }

    return $ids;
  }

  /**
   * @return array<int>
   */
  public static function get_busy_driver_ids(
    int $tenant_id,
    int $freshness_seconds = 180
  ) : array {
    $users = self::tenant_driver_users($tenant_id);
    if (!$users) {
      return [];
    }

    $now = time();
    $ids = [];

    foreach ($users as $user) {
      $user_id = (int) $user->ID;
      $status  = self::driver_status($user_id);
      $last_ts = self::driver_last_ping_ts($user_id);

      if ($status !== 'busy') {
        continue;
      }

      if ($last_ts < 1 || ($now - $last_ts) > $freshness_seconds) {
        continue;
      }

      $ids[] = $user_id;
    }

    return $ids;
  }

  /**
   * @return array<int,array<string,mixed>>
   */
  public static function get_driver_snapshots(
    int $tenant_id,
    int $freshness_seconds = 180
  ) : array {
    $users = self::tenant_driver_users($tenant_id);
    if (!$users) {
      return [];
    }

    $now = time();
    $rows = [];

    foreach ($users as $user) {
      $user_id = (int) $user->ID;
      $status  = self::driver_status($user_id);
      $last_ts = self::driver_last_ping_ts($user_id);
      $is_fresh = $last_ts > 0 && (($now - $last_ts) <= $freshness_seconds);

      $rows[] = [
        'user_id'     => $user_id,
        'display_name'=> (string) $user->display_name,
        'status'      => $status,
        'last_ping_ts'=> $last_ts,
        'is_online'   => $is_fresh && in_array($status, ['available', 'busy'], true),
        'is_available'=> $is_fresh && $status === 'available',
        'is_busy'     => $is_fresh && $status === 'busy',
      ];
    }

    return $rows;
  }

  /**
   * Uses WP users as canonical driver identity.
   *
   * @return array<int,WP_User>
   */
  private static function tenant_driver_users(int $tenant_id) : array {
    $query = new WP_User_Query([
      'role'       => self::ROLE_DRIVER,
      'number'     => 500,
      'fields'     => 'all',
      'meta_query' => [
        [
          'key'   => 'sd_tenant_id',
          'value' => (string) $tenant_id,
        ],
      ],
    ]);

    $results = $query->get_results();
    return is_array($results) ? $results : [];
  }

  private static function driver_status(int $user_id) : string {
    $status = (string) get_user_meta($user_id, 'sd_driver_status', true);
    if ($status === '') {
      return 'offline';
    }
    return $status;
  }

  private static function driver_last_ping_ts(int $user_id) : int {
    return (int) get_user_meta($user_id, 'sd_driver_last_ping_ts', true);
  }
}