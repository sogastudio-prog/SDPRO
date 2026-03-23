<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_TenantReadiness
 *
 * Canonical tenant readiness evaluator.
 *
 * Purpose:
 * - Determine whether a tenant has the minimum required configuration
 *   for legitimate storefront + quote-engine testing
 * - Return missing required fields and non-blocking warnings
 *
 * Notes:
 * - Read-only service
 * - Safe to load multiple times
 * - Assumes SD_Meta, SD_TenantSettingsSchema, and SD_TenantConfig are loaded
 */
if (class_exists('SD_TenantReadiness', false)) { return; }

final class SD_TenantReadiness {

  public static function evaluate(int $tenant_id) : array {
    $tenant_id = absint($tenant_id);

    if ($tenant_id <= 0) {
      return [
        'tenant_id'     => 0,
        'is_ready'      => false,
        'missing'       => ['tenant_id'],
        'warnings'      => [],
        'details'       => [
          'required'    => [
            'tenant_id' => ['ok' => false, 'reason' => 'Invalid tenant ID.'],
          ],
          'conditional' => [],
        ],
        'missing_items' => [
          [
            'meta_key' => 'tenant_id',
            'label'    => 'Tenant',
            'section'  => '_unmapped',
            'reason'   => 'Invalid tenant ID.',
          ],
        ],
      ];
    }

    $required_details    = [];
    $conditional_details = [];
    $missing             = [];
    $warnings            = [];

    $requirements = SD_TenantSettingsSchema::readiness_requirements();
    $required     = (array) ($requirements['required'] ?? []);

    foreach ($required as $meta_key) {
      $value = SD_TenantConfig::get_value($tenant_id, $meta_key, null);
      $ok    = self::has_meaningful_value($value);

      $required_details[$meta_key] = [
        'ok'     => $ok,
        'reason' => $ok ? 'Present.' : 'Required value is missing.',
      ];

      if (!$ok) {
        $missing[] = $meta_key;
      }
    }

    $immediate_booking = SD_TenantConfig::get_value($tenant_id, SD_Meta::STOREFRONT_ALLOWS_IMMEDIATE_BOOKING, '0');
    $requires_quote    = SD_TenantConfig::get_value($tenant_id, SD_Meta::STOREFRONT_REQUIRES_QUOTE, '1');

    if ((string) $immediate_booking === '1' || (string) $requires_quote === '0') {
      $value = SD_TenantConfig::get_value($tenant_id, SD_Meta::STRIPE_ACCOUNT_ID, null);
      $ok    = self::has_meaningful_value($value);

      $conditional_details[SD_Meta::STRIPE_ACCOUNT_ID] = [
        'ok'     => $ok,
        'reason' => $ok ? 'Present.' : 'Required when immediate booking or payment-forward flow is enabled.',
      ];

      if (!$ok) {
        $missing[] = SD_Meta::STRIPE_ACCOUNT_ID;
      }
    } else {
      $conditional_details[SD_Meta::STRIPE_ACCOUNT_ID] = [
        'ok'     => true,
        'reason' => 'Not required in current storefront/payment mode.',
      ];
    }

    $calendar_mode = (string) SD_TenantConfig::get_value($tenant_id, SD_Meta::CALENDAR_MODE, 'always_on');
    $calendar_tz   = SD_TenantConfig::get_value($tenant_id, SD_Meta::CALENDAR_TIMEZONE, null);

    if (in_array($calendar_mode, ['business_hours', 'schedule_only'], true)) {
      $ok = self::has_meaningful_value($calendar_tz);

      $conditional_details[SD_Meta::CALENDAR_TIMEZONE] = [
        'ok'     => $ok,
        'reason' => $ok ? 'Present.' : 'Required when calendar mode is business_hours or schedule_only.',
      ];

      if (!$ok) {
        $missing[] = SD_Meta::CALENDAR_TIMEZONE;
      }
    } else {
      $conditional_details[SD_Meta::CALENDAR_TIMEZONE] = [
        'ok'     => true,
        'reason' => 'Not required when calendar mode is always_on.',
      ];
    }

    $hours_mode = (string) SD_TenantConfig::get_value($tenant_id, SD_Meta::STOREFRONT_HOURS_MODE, 'always_on');
    $store_tz   = SD_TenantConfig::get_value($tenant_id, SD_Meta::STOREFRONT_TIMEZONE, null);

    if ($hours_mode === 'scheduled') {
      $ok = self::has_meaningful_value($store_tz);

      $conditional_details[SD_Meta::STOREFRONT_TIMEZONE] = [
        'ok'     => $ok,
        'reason' => $ok ? 'Present.' : 'Required when storefront hours mode is scheduled.',
      ];

      if (!$ok) {
        $missing[] = SD_Meta::STOREFRONT_TIMEZONE;
      }
    } else {
      $conditional_details[SD_Meta::STOREFRONT_TIMEZONE] = [
        'ok'     => true,
        'reason' => 'Not required when storefront hours mode is always_on.',
      ];
    }

    $surcharge_type  = (string) SD_TenantConfig::get_value($tenant_id, SD_Meta::AFTER_HOURS_SURCHARGE_TYPE, 'none');
    $surcharge_value = SD_TenantConfig::get_value($tenant_id, SD_Meta::AFTER_HOURS_SURCHARGE_VALUE, null);

    if (in_array($surcharge_type, ['flat', 'percent'], true)) {
      $ok = self::has_meaningful_value($surcharge_value);

      $conditional_details[SD_Meta::AFTER_HOURS_SURCHARGE_VALUE] = [
        'ok'     => $ok,
        'reason' => $ok ? 'Present.' : 'Required when after-hours surcharge type is flat or percent.',
      ];

      if (!$ok) {
        $missing[] = SD_Meta::AFTER_HOURS_SURCHARGE_VALUE;
      }
    } else {
      $conditional_details[SD_Meta::AFTER_HOURS_SURCHARGE_VALUE] = [
        'ok'     => true,
        'reason' => 'Not required when after-hours surcharge is disabled.',
      ];
    }

    if (!SD_TenantConfig::has_value($tenant_id, SD_Meta::PROFILE_SUPPORT_PHONE)) {
      $warnings[] = 'Support phone not set.';
    }

    if (!SD_TenantConfig::has_value($tenant_id, SD_Meta::PROFILE_SUPPORT_EMAIL)) {
      $warnings[] = 'Support email not set.';
    }

    if (!SD_TenantConfig::has_value($tenant_id, SD_Meta::VEHICLE_DISPLAY_NAME)) {
      $warnings[] = 'Vehicle display name not set.';
    }

    if (!SD_TenantConfig::has_value($tenant_id, SD_Meta::BRAND_LOGO_ID)) {
      $warnings[] = 'Brand logo not set.';
    }

    if (!SD_TenantConfig::has_value($tenant_id, SD_Meta::BRAND_PRIMARY_COLOR)) {
      $warnings[] = 'Brand primary color not set.';
    }

    if (!SD_TenantConfig::has_value($tenant_id, SD_Meta::PROFILE_DESCRIPTION)) {
      $warnings[] = 'Business description not set.';
    }

    $missing  = array_values(array_unique(array_filter($missing, 'strlen')));
    $is_ready = empty($missing);

    return [
      'tenant_id'     => $tenant_id,
      'is_ready'      => $is_ready,
      'missing'       => $missing,
      'warnings'      => $warnings,
      'details'       => [
        'required'    => $required_details,
        'conditional' => $conditional_details,
      ],
      'missing_items' => self::build_missing_items($missing, $required_details, $conditional_details),
    ];
  }

  public static function badge(int $tenant_id) : array {
    $result = self::evaluate($tenant_id);
    $count  = count((array) $result['missing']);

    return [
      'status'        => $result['is_ready'] ? 'ready' : 'incomplete',
      'label'         => $result['is_ready'] ? 'Ready for testing' : 'Configuration incomplete',
      'count_missing' => $count,
    ];
  }

  public static function missing_by_section(int $tenant_id) : array {
    $result  = self::evaluate($tenant_id);
    $grouped = [];

    foreach ((array) ($result['missing_items'] ?? []) as $item) {
      $section = isset($item['section']) ? (string) $item['section'] : '_unmapped';

      if (!isset($grouped[$section])) {
        $grouped[$section] = [];
      }

      $grouped[$section][] = $item;
    }

    return $grouped;
  }

  public static function missing_items(int $tenant_id) : array {
    $result = self::evaluate($tenant_id);
    return (array) ($result['missing_items'] ?? []);
  }

  public static function check_key(int $tenant_id, string $meta_key) : array {
    $value = SD_TenantConfig::get_value($tenant_id, $meta_key, null);
    $ok    = self::has_meaningful_value($value);

    return [
      'tenant_id' => absint($tenant_id),
      'meta_key'  => $meta_key,
      'ok'        => $ok,
      'value'     => $value,
    ];
  }

  // ---------------------------------------------------------------------------
  // Internal helpers
  // ---------------------------------------------------------------------------

  private static function build_missing_items(array $missing, array $required_details, array $conditional_details) : array {
    $ownership = SD_TenantSettingsSchema::ownership_map();
    $items = [];

    foreach ($missing as $meta_key) {
      $section = isset($ownership[$meta_key]) ? (string) $ownership[$meta_key] : '_unmapped';
      $reason  = 'Required value is missing.';

      if (isset($required_details[$meta_key]['reason'])) {
        $reason = (string) $required_details[$meta_key]['reason'];
      } elseif (isset($conditional_details[$meta_key]['reason'])) {
        $reason = (string) $conditional_details[$meta_key]['reason'];
      }

      $items[] = [
        'meta_key' => $meta_key,
        'label'    => self::meta_key_label($meta_key),
        'section'  => $section,
        'reason'   => $reason,
      ];
    }

    return $items;
  }

  private static function meta_key_label(string $meta_key) : string {
    $label_map = [];

    self::map_label($label_map, 'SD_Meta::TENANT_SLUG', 'Tenant Slug');
    self::map_label($label_map, 'SD_Meta::TENANT_DOMAIN', 'Tenant Domain');
    self::map_label($label_map, 'SD_Meta::STOREFRONT_STATE', 'Storefront State');
    self::map_label($label_map, 'SD_Meta::STOREFRONT_ENABLED', 'Storefront Enabled');
    self::map_label($label_map, 'SD_Meta::STOREFRONT_ACCEPTING_REQUESTS', 'Accepting Requests');
    self::map_label($label_map, 'SD_Meta::STOREFRONT_REQUEST_MODE', 'Request Mode');
    self::map_label($label_map, 'SD_Meta::STOREFRONT_REQUIRES_QUOTE', 'Requires Quote');
    self::map_label($label_map, 'SD_Meta::STOREFRONT_ALLOWS_IMMEDIATE_BOOKING', 'Allows Immediate Booking');
    self::map_label($label_map, 'SD_Meta::STOREFRONT_HOURS_MODE', 'Storefront Hours Mode');
    self::map_label($label_map, 'SD_Meta::STOREFRONT_TIMEZONE', 'Storefront Timezone');

    self::map_label($label_map, 'SD_Meta::BASE_LOCATION_LABEL', 'Base Location Label');
    self::map_label($label_map, 'SD_Meta::BASE_LOCATION_PLACE_ID', 'Base Location Place ID');
    self::map_label($label_map, 'SD_Meta::BASE_LOCATION_LAT', 'Base Latitude');
    self::map_label($label_map, 'SD_Meta::BASE_LOCATION_LNG', 'Base Longitude');
    self::map_label($label_map, 'SD_Meta::BASE_LOCATION_RADIUS_M', 'Base Radius');
    self::map_label($label_map, 'SD_Meta::SERVICE_RADIUS_MODE', 'Service Radius Mode');
    self::map_label($label_map, 'SD_Meta::PICKUP_RADIUS_M', 'Pickup Radius');
    self::map_label($label_map, 'SD_Meta::DROPOFF_RADIUS_M', 'Dropoff Radius');
    self::map_label($label_map, 'SD_Meta::OUT_OF_AREA_POLICY', 'Out of Area Policy');

    self::map_label($label_map, 'SD_Meta::QUOTE_MODE', 'Quote Mode');
    self::map_label($label_map, 'SD_Meta::PRICING_MODEL', 'Pricing Model');
    self::map_label($label_map, 'SD_Meta::CURRENCY', 'Currency');
    self::map_label($label_map, 'SD_Meta::BASE_FARE', 'Base Fare');
    self::map_label($label_map, 'SD_Meta::MINIMUM_FARE', 'Minimum Fare');
    self::map_label($label_map, 'SD_Meta::PER_MILE_RATE', 'Per Mile Rate');
    self::map_label($label_map, 'SD_Meta::PER_MINUTE_RATE', 'Per Minute Rate');
    self::map_label($label_map, 'SD_Meta::WAIT_TIME_PER_MINUTE', 'Wait Time Per Minute');
    self::map_label($label_map, 'SD_Meta::DEADHEAD_ENABLED', 'Deadhead Enabled');
    self::map_label($label_map, 'SD_Meta::DEADHEAD_PER_MILE', 'Deadhead Per Mile');
    self::map_label($label_map, 'SD_Meta::SERVICE_FEE', 'Service Fee');
    self::map_label($label_map, 'SD_Meta::QUOTE_EXPIRY_MINUTES', 'Quote Expiry Minutes');
    self::map_label($label_map, 'SD_Meta::LEAD_EXPIRY_MINUTES', 'Lead Expiry Minutes');
    self::map_label($label_map, 'SD_Meta::REQUIRES_MANUAL_REVIEW', 'Requires Manual Review');
    self::map_label($label_map, 'SD_Meta::AFTER_HOURS_SURCHARGE_TYPE', 'After Hours Surcharge Type');
    self::map_label($label_map, 'SD_Meta::AFTER_HOURS_SURCHARGE_VALUE', 'After Hours Surcharge Value');

    self::map_label($label_map, 'SD_Meta::STRIPE_ACCOUNT_ID', 'Stripe Connected Account');
    self::map_label($label_map, 'SD_Meta::CALENDAR_TIMEZONE', 'Calendar Timezone');

    if (isset($label_map[$meta_key])) {
      return $label_map[$meta_key];
    }

    $label = preg_replace('/^_?sd_/', '', $meta_key);
    $label = str_replace('_', ' ', (string) $label);
    return ucwords(trim((string) $label));
  }

  private static function map_label(array &$map, string $const_name, string $label) : void {
    if (!defined($const_name)) {
      return;
    }

    $value = constant($const_name);
    if (!is_string($value) || $value === '') {
      return;
    }

    $map[$value] = $label;
  }

  private static function has_meaningful_value($value) : bool {
    if ($value === null || $value === '') {
      return false;
    }

    if (is_array($value)) {
      return !empty($value);
    }

    if (is_string($value)) {
      return trim($value) !== '';
    }

    return true;
  }
}