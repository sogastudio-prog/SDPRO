<?php
if (!defined('ABSPATH')) { exit; }

final class SD_WaitlistConversionService {

  /**
   * Attempt to convert the oldest convertible waitlist entry for a tenant.
   *
   * @return array<string,mixed>
   */
  public static function convert_next_for_tenant(int $tenant_id, SD_StorefrontPolicy $policy) : array {
    $entry = SD_WaitlistRepository::find_oldest_convertible($tenant_id);

    if (!$entry) {
      return [
        'ok'      => false,
        'message' => 'No open waitlist entries found.',
      ];
    }

    if (!$entry->is_convertible()) {
      return [
        'ok'      => false,
        'message' => 'Waitlist entry is not convertible.',
        'entry_id' => $entry->entry_id,
      ];
    }

    $decision = new SD_StorefrontDecision(
      $tenant_id,
      'open',
      SD_StorefrontAvailabilityMode::INSTANT,
      SD_StorefrontReasonCode::OPEN_INSTANT,
      $policy->open_headline ?: 'Book your ride',
      'Capacity became available from waitlist conversion.',
      'BOOK_NOW',
      '',
      $policy->workflow_for_mode(SD_StorefrontAvailabilityMode::INSTANT),
      [
        'source'            => 'waitlist_conversion',
        'waitlist_entry_id' => $entry->entry_id,
      ]
    );

    $payload = [
      'pickup'         => $entry->pickup_text,
      'dropoff'        => $entry->dropoff_text,
      'customer_name'  => '',
      'customer_phone' => self::extract_phone($entry->contact),
      'customer_email' => self::extract_email($entry->contact),
      'waitlist_entry_id' => $entry->entry_id,
    ];

    $result = SD_StorefrontSubmissionController::create_lead_from_storefront($decision, $payload);

    if (empty($result['ok']) || empty($result['ride_id'])) {
      return [
        'ok'       => false,
        'message'  => (string) ($result['message'] ?? 'Failed to create ride lead from waitlist entry.'),
        'entry_id' => $entry->entry_id,
      ];
    }

    $ride_id = (int) $result['ride_id'];

    update_post_meta($ride_id, 'sd_waitlist_entry_id', $entry->entry_id);
    update_post_meta($ride_id, 'sd_lead_source', 'waitlist_conversion');

    SD_WaitlistRepository::mark_converted($entry->entry_id, $ride_id);

    do_action('sd/waitlist/conversion_completed', $entry, $ride_id, $tenant_id);

    return [
      'ok'       => true,
      'entry_id' => $entry->entry_id,
      'ride_id'  => $ride_id,
    ];
  }

  /**
   * Convert as many waitlist entries as current instant capacity allows.
   *
   * @return array<string,mixed>
   */
  public static function convert_up_to_capacity(int $tenant_id, SD_StorefrontPolicy $policy) : array {
    $snapshot = SD_RideCapacityGateway::get_capacity_snapshot($tenant_id, $policy);
    $remaining = (int) ($snapshot['instant_capacity_remaining'] ?? 0);

    if ($remaining < 1) {
      return [
        'ok'        => true,
        'converted' => 0,
        'message'   => 'No instant capacity available.',
      ];
    }

    $converted = 0;
    $ride_ids  = [];
    $entry_ids = [];

    for ($i = 0; $i < $remaining; $i++) {
      $result = self::convert_next_for_tenant($tenant_id, $policy);
      if (empty($result['ok'])) {
        break;
      }

      $converted++;
      $entry_ids[] = (int) $result['entry_id'];
      $ride_ids[]  = (int) $result['ride_id'];
    }

    return [
      'ok'        => true,
      'converted' => $converted,
      'entry_ids' => $entry_ids,
      'ride_ids'  => $ride_ids,
    ];
  }

  private static function extract_email(string $contact) : string {
    $contact = trim($contact);
    return is_email($contact) ? $contact : '';
  }

  private static function extract_phone(string $contact) : string {
    $contact = trim($contact);
    if ($contact === '' || is_email($contact)) {
      return '';
    }
    return $contact;
  }
}