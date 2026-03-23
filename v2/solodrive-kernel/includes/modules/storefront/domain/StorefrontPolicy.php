<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontPolicy {

  public int $tenant_id;

  // Availability
  public bool $storefront_enabled = true;
  public string $manual_mode = ''; // '', 'busy', 'closed'
  public bool $on_demand_enabled = true;
  public bool $stacked_enabled = false;
  public bool $waitlist_enabled = false;
  public bool $reservations_enabled = true;

  // Capacity
  public int $max_active_rides_per_driver = 1;
  public int $max_stacked_rides_per_driver = 0;
  public int $waitlist_limit = 0;
  public bool $auto_close_if_no_drivers = false;

  // Hours
  public array $weekly_hours = [];
  public array $holiday_overrides = [];
  public array $manual_closures = [];

  // Messaging
  public string $open_headline = 'Book your ride';
  public string $busy_headline = 'We are busy right now';
  public string $closed_headline = 'We are currently closed';
  public string $resume_message = '';
  public string $no_driver_message = 'No drivers are currently available.';

  // Workflow bindings
  public string $instant_workflow = 'cf7.instant';
  public string $stacked_workflow = 'cf7.stacked';
  public string $waitlist_workflow = 'cf7.waitlist';
  public string $reservation_workflow = 'cf7.reservation';

  public function __construct(int $tenant_id) {
    $this->tenant_id = $tenant_id;
  }

  public static function from_array(int $tenant_id, array $data) : self {
    $p = new self($tenant_id);

    foreach ($data as $key => $value) {
      if (property_exists($p, $key)) {
        $p->{$key} = $value;
      }
    }

    return $p;
  }

  public function workflow_for_mode(string $mode) : string {
    switch ($mode) {
      case SD_StorefrontAvailabilityMode::INSTANT:
        return $this->instant_workflow;

      case SD_StorefrontAvailabilityMode::STACKED_ASAP:
        return $this->stacked_workflow;

      case SD_StorefrontAvailabilityMode::WAITLIST:
        return $this->waitlist_workflow;

      case SD_StorefrontAvailabilityMode::RESERVE_ONLY:
        return $this->reservation_workflow;

      default:
        return '';
    }
  }
}