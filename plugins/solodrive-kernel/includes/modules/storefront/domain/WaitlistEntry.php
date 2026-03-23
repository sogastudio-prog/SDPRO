<?php
if (!defined('ABSPATH')) { exit; }

final class SD_WaitlistEntry {

  public const STATUS_WAITING   = 'waiting';
  public const STATUS_NOTIFIED  = 'notified';
  public const STATUS_CONVERTED = 'converted';
  public const STATUS_EXPIRED   = 'expired';
  public const STATUS_CANCELLED = 'cancelled';

  public int $entry_id;
  public int $tenant_id;
  public string $status;
  public string $pickup_text;
  public string $dropoff_text;
  public string $contact;
  public int $requested_at;
  public int $converted_ride_id;

  /** @var array<string,mixed> */
  public array $meta = [];

  public function __construct(
    int $entry_id,
    int $tenant_id,
    string $status = self::STATUS_WAITING,
    string $pickup_text = '',
    string $dropoff_text = '',
    string $contact = '',
    int $requested_at = 0,
    int $converted_ride_id = 0,
    array $meta = []
  ) {
    $this->entry_id           = $entry_id;
    $this->tenant_id          = $tenant_id;
    $this->status             = $status;
    $this->pickup_text        = $pickup_text;
    $this->dropoff_text       = $dropoff_text;
    $this->contact            = $contact;
    $this->requested_at       = $requested_at;
    $this->converted_ride_id  = $converted_ride_id;
    $this->meta               = $meta;
  }

  public static function from_array(array $row) : self {
    return new self(
      (int) ($row['entry_id'] ?? 0),
      (int) ($row['tenant_id'] ?? 0),
      (string) ($row['status'] ?? self::STATUS_WAITING),
      (string) ($row['pickup_text'] ?? $row['pickup'] ?? ''),
      (string) ($row['dropoff_text'] ?? $row['dropoff'] ?? ''),
      (string) ($row['contact'] ?? ''),
      (int) ($row['requested_at'] ?? 0),
      (int) ($row['converted_ride_id'] ?? 0),
      (array) ($row['meta'] ?? [])
    );
  }

  public function is_open() : bool {
    return in_array($this->status, [self::STATUS_WAITING, self::STATUS_NOTIFIED], true);
  }

  public function is_convertible() : bool {
    return $this->status === self::STATUS_WAITING || $this->status === self::STATUS_NOTIFIED;
  }

  public function to_array() : array {
    return [
      'entry_id'           => $this->entry_id,
      'tenant_id'          => $this->tenant_id,
      'status'             => $this->status,
      'pickup_text'        => $this->pickup_text,
      'dropoff_text'       => $this->dropoff_text,
      'contact'            => $this->contact,
      'requested_at'       => $this->requested_at,
      'converted_ride_id'  => $this->converted_ride_id,
      'meta'               => $this->meta,
    ];
  }
}