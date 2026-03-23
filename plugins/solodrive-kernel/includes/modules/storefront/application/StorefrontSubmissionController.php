<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontSubmissionController {

  public const META_STOREFRONT_DECISION_SNAPSHOT = 'sd_storefront_decision_snapshot';
  public const META_WORKFLOW_TYPE                = 'sd_workflow_type';
  public const META_SUBMISSION_TIMESTAMP         = 'sd_submission_timestamp';
  public const META_TENANT_ID                    = 'sd_tenant_id';

  /**
   * v1 normalized lead creation path.
   *
   * @param array<string,mixed> $payload
   * @return array<string,mixed>
   */
  public static function create_lead_from_storefront(
    SD_StorefrontDecision $decision,
    array $payload
  ) : array {

    $ride_id = wp_insert_post([
      'post_type'   => 'sd_ride',
      'post_status' => 'publish',
      'post_title'  => self::build_ride_title($payload),
      'lead_source' => 'waitlist_conversion',
    ], true);

    if (is_wp_error($ride_id)) {
      return [
        'ok'      => false,
        'message' => $ride_id->get_error_message(),
      ];
    }

    update_post_meta($ride_id, self::META_TENANT_ID, $decision->tenant_id);
    update_post_meta($ride_id, self::META_STOREFRONT_DECISION_SNAPSHOT, wp_json_encode($decision->to_array()));
    update_post_meta($ride_id, self::META_WORKFLOW_TYPE, $decision->workflow_type);
    update_post_meta($ride_id, self::META_SUBMISSION_TIMESTAMP, time());

    // Add intake fields as your canonical ride meta evolves.
    self::maybe_write($ride_id, 'sd_pickup_text', $payload['pickup'] ?? '');
    self::maybe_write($ride_id, 'sd_dropoff_text', $payload['dropoff'] ?? '');
    self::maybe_write($ride_id, 'sd_customer_name', $payload['customer_name'] ?? '');
    self::maybe_write($ride_id, 'sd_customer_phone', $payload['customer_phone'] ?? '');
    self::maybe_write($ride_id, 'sd_customer_email', $payload['customer_email'] ?? '');
    self::maybe_write($ride_id, 'sd_lead_source', (string) ($payload['lead_source'] ?? 'storefront'));

if (!empty($payload['waitlist_entry_id'])) {
  update_post_meta($ride_id, 'sd_waitlist_entry_id', (int) $payload['waitlist_entry_id']);
}

    do_action('sd/storefront/lead_created', $ride_id, $decision, $payload);

    return [
      'ok'      => true,
      'ride_id' => (int) $ride_id,
    ];
  }

  /**
   * @param array<string,mixed> $payload
   */
  private static function build_ride_title(array $payload) : string {
    $name = trim((string) ($payload['customer_name'] ?? ''));
    return $name ? sprintf('Storefront Lead — %s', $name) : 'Storefront Lead';
  }

  private static function maybe_write(int $ride_id, string $key, string $value) : void {
    $value = trim($value);
    if ($value !== '') {
      update_post_meta($ride_id, $key, $value);
    }
  }
}