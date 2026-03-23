<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontOperationalContext {
  public int $tenant_id;
  public int $current_ts;
  public bool $within_service_hours = true;
  public int $online_drivers = 0;
  public int $active_rides = 0;
  public int $instant_capacity_remaining = 0;
  public int $stack_slots_remaining = 0;
  public int $waitlist_count = 0;
  public string $manual_override = ''; // '', 'busy', 'closed'

  public function __construct(int $tenant_id, int $current_ts) {
    $this->tenant_id  = $tenant_id;
    $this->current_ts = $current_ts;
  }

  public static function from_array(int $tenant_id, array $data) : self {
    $c = new self($tenant_id, isset($data['current_ts']) ? (int) $data['current_ts'] : time());

    foreach ($data as $key => $value) {
      if (property_exists($c, $key)) {
        $c->{$key} = $value;
      }
    }

    return $c;
  }
}