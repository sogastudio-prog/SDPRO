<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Deprecated.
 *
 * Canon now:
 * - reservation is captured as sd_lead
 * - storefront never creates sd_ride
 * - ride is created only after successful auth
 */
final class SD_ReservationRideCreator {

  public static function create(array $data) : array {
    return [
      'ok'    => false,
      'error' => 'deprecated_reservation_ride_creator',
    ];
  }
}