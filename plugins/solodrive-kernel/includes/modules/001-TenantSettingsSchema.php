<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_TenantSettingsSchema
 *
 * Canonical schema for tenant admin/settings sections.
 *
 * Responsibilities:
 * - Define section ownership
 * - Define field types, defaults, required flags
 * - Define enum domains
 * - Provide normalization + validation helpers
 *
 * Notes:
 * - Assumes SD_Meta is already loaded.
 * - Safe to load multiple times.
 * - Intended for sd_tenant post meta only.
 */
if (class_exists('SD_TenantSettingsSchema', false)) { return; }

final class SD_TenantSettingsSchema {

  // ---------------------------------------------------------------------------
  // Section ids
  // ---------------------------------------------------------------------------
  public const SECTION_STOREFRONT   = 'storefront';
  public const SECTION_PRICING      = 'pricing';
  public const SECTION_PROFILE      = 'profile';
  public const SECTION_VEHICLE      = 'vehicle';
  public const SECTION_BASE_LOCATION= 'base_location';
  public const SECTION_BRAND        = 'brand';
  public const SECTION_CALENDAR     = 'calendar';
  public const SECTION_DRIVE_MODE   = 'drive_mode';

  // ---------------------------------------------------------------------------
  // Public API
  // ---------------------------------------------------------------------------

  public static function sections() : array {
    return [
      self::SECTION_STOREFRONT    => self::storefront_section(),
      self::SECTION_PRICING       => self::pricing_section(),
      self::SECTION_PROFILE       => self::profile_section(),
      self::SECTION_VEHICLE       => self::vehicle_section(),
      self::SECTION_BASE_LOCATION => self::base_location_section(),
      self::SECTION_BRAND         => self::brand_section(),
      self::SECTION_CALENDAR      => self::calendar_section(),
      self::SECTION_DRIVE_MODE    => self::drive_mode_section(),
    ];
  }

  public static function has_section(string $section) : bool {
    return isset(self::sections()[$section]);
  }

  public static function section(string $section) : array {
    $all = self::sections();
    return $all[$section] ?? [];
  }

  public static function section_label(string $section) : string {
    $def = self::section($section);
    return (string) ($def['label'] ?? $section);
  }

  public static function section_fields(string $section) : array {
    $def = self::section($section);
    return (array) ($def['fields'] ?? []);
  }

  public static function owned_meta_keys(string $section) : array {
    return array_keys(self::section_fields($section));
  }

  public static function defaults(string $section) : array {
    $out = [];
    foreach (self::section_fields($section) as $key => $field) {
      if (array_key_exists('default', $field)) {
        $out[$key] = $field['default'];
      }
    }
    return $out;
  }

  public static function all_defaults() : array {
    $out = [];
    foreach (array_keys(self::sections()) as $section) {
      $out[$section] = self::defaults($section);
    }
    return $out;
  }

  public static function enum_values(string $section, string $meta_key) : array {
    $field = self::section_fields($section)[$meta_key] ?? [];
    return (array) ($field['enum'] ?? []);
  }

  public static function is_owned_by_section(string $section, string $meta_key) : bool {
    return isset(self::section_fields($section)[$meta_key]);
  }

  public static function field(string $section, string $meta_key) : array {
    return self::section_fields($section)[$meta_key] ?? [];
  }

  /**
   * Normalize a section payload using schema defaults + field types.
   *
   * Returns normalized values for owned keys only.
   */
  public static function normalize(string $section, array $input) : array {
    $fields = self::section_fields($section);
    $out    = [];

    foreach ($fields as $meta_key => $field) {
      $has_input = array_key_exists($meta_key, $input);
      $raw       = $has_input ? $input[$meta_key] : ($field['default'] ?? null);

      if (!$has_input && !array_key_exists('default', $field)) {
        continue;
      }

      $out[$meta_key] = self::normalize_field_value($field, $raw);
    }

    return $out;
  }

  /**
   * Validate and normalize a section payload.
   *
   * Returns:
   * [
   *   'ok'         => bool,
   *   'normalized' => array,
   *   'errors'     => [ meta_key => [messages...] ],
   * ]
   */
  public static function validate(string $section, array $input) : array {
    $fields     = self::section_fields($section);
    $normalized = self::normalize($section, $input);
    $errors     = [];

    foreach ($fields as $meta_key => $field) {
      $value = array_key_exists($meta_key, $normalized)
        ? $normalized[$meta_key]
        : ($field['default'] ?? null);

      $field_errors = self::validate_field($meta_key, $field, $value, $normalized);
      if (!empty($field_errors)) {
        $errors[$meta_key] = $field_errors;
      }
    }

    $cross_errors = self::validate_cross_field_rules($section, $normalized);
    foreach ($cross_errors as $meta_key => $messages) {
      if (!isset($errors[$meta_key])) {
        $errors[$meta_key] = [];
      }
      $errors[$meta_key] = array_values(array_unique(array_merge($errors[$meta_key], $messages)));
    }

    return [
      'ok'         => empty($errors),
      'normalized' => $normalized,
      'errors'     => $errors,
    ];
  }

  /**
   * Return a flattened meta_key => section map.
   */
  public static function ownership_map() : array {
    $map = [];
    foreach (self::sections() as $section => $def) {
      foreach ((array) ($def['fields'] ?? []) as $meta_key => $_field) {
        $map[$meta_key] = $section;
      }
    }
    return $map;
  }

  /**
   * Minimal readiness contract for legitimate storefront + quote testing.
   */
  public static function readiness_requirements() : array {
    return [
      'required' => [
        SD_Meta::TENANT_SLUG,
        SD_Meta::STOREFRONT_STATE,
        SD_Meta::STOREFRONT_ENABLED,
        SD_Meta::STOREFRONT_ACCEPTING_REQUESTS,
        SD_Meta::BASE_LOCATION_LABEL,
        SD_Meta::BASE_LOCATION_LAT,
        SD_Meta::BASE_LOCATION_LNG,
        SD_Meta::BASE_LOCATION_RADIUS_M,
        SD_Meta::QUOTE_MODE,
        SD_Meta::PRICING_MODEL,
        SD_Meta::QUOTE_EXPIRY_MINUTES,
        SD_Meta::LEAD_EXPIRY_MINUTES,
        SD_Meta::PROFILE_BUSINESS_NAME,
      ],
      'conditional' => [
        SD_Meta::STRIPE_ACCOUNT_ID         => 'required when payment-forward acceptance is enabled',
        SD_Meta::CALENDAR_TIMEZONE         => 'required when calendar mode is business_hours or schedule_only',
        SD_Meta::STOREFRONT_TIMEZONE       => 'required when storefront hours mode is scheduled',
        SD_Meta::AFTER_HOURS_SURCHARGE_VALUE => 'required when surcharge type is flat or percent',
      ],
    ];
  }

  // ---------------------------------------------------------------------------
  // Section definitions
  // ---------------------------------------------------------------------------

  private static function storefront_section() : array {
    return [
      'label' => 'Storefront Config',
      'fields' => [
        SD_Meta::TENANT_SLUG => [
          'type'      => 'slug',
          'required'  => true,
          'max_len'   => 80,
          'unique'    => true,
        ],
        SD_Meta::TENANT_DOMAIN => [
          'type'      => 'domain',
          'required'  => false,
          'max_len'   => 190,
          'unique'    => true,
        ],
        SD_Meta::STOREFRONT_STATE => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'open',
          'enum'      => ['open', 'busy', 'closed'],
        ],
        SD_Meta::STOREFRONT_ENABLED => [
          'type'      => 'bool',
          'required'  => true,
          'default'   => '1',
        ],
        SD_Meta::STOREFRONT_ACCEPTING_REQUESTS => [
          'type'      => 'bool',
          'required'  => true,
          'default'   => '1',
        ],
        SD_Meta::STOREFRONT_REQUEST_MODE => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'quote_only',
          'enum'      => ['quote_only', 'booking_only', 'quote_or_booking'],
        ],
        SD_Meta::STOREFRONT_CLOSURE_MESSAGE => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 280,
        ],
        SD_Meta::STOREFRONT_BUSY_MESSAGE => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 280,
        ],
        SD_Meta::STOREFRONT_HOURS_MODE => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'always_on',
          'enum'      => ['always_on', 'scheduled'],
        ],
        SD_Meta::STOREFRONT_TIMEZONE => [
          'type'      => 'timezone',
          'required'  => false,
          'default'   => '',
          'max_len'   => 64,
        ],
        SD_Meta::STOREFRONT_REQUIRES_QUOTE => [
          'type'      => 'bool',
          'required'  => true,
          'default'   => '1',
        ],
        SD_Meta::STOREFRONT_ALLOWS_IMMEDIATE_BOOKING => [
          'type'      => 'bool',
          'required'  => true,
          'default'   => '0',
        ],
      ],
    ];
  }

  private static function pricing_section() : array {
    return [
      'label' => 'Pricing Config',
      'fields' => [
        SD_Meta::QUOTE_MODE => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'manual',
          'enum'      => ['disabled', 'manual', 'automatic', 'hybrid'],
        ],
        SD_Meta::PRICING_MODEL => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'distance_time',
          'enum'      => ['flat', 'distance_time', 'manual_only'],
        ],
        SD_Meta::CURRENCY => [
          'type'      => 'currency',
          'required'  => true,
          'default'   => 'USD',
          'max_len'   => 3,
        ],
        SD_Meta::BASE_FARE => [
          'type'      => 'money',
          'required'  => false,
          'default'   => '',
          'min'       => 0,
        ],
        SD_Meta::MINIMUM_FARE => [
          'type'      => 'money',
          'required'  => false,
          'default'   => '',
          'min'       => 0,
        ],
        SD_Meta::PER_MILE_RATE => [
          'type'      => 'money',
          'required'  => false,
          'default'   => '',
          'min'       => 0,
        ],
        SD_Meta::PER_MINUTE_RATE => [
          'type'      => 'money',
          'required'  => false,
          'default'   => '',
          'min'       => 0,
        ],
        SD_Meta::WAIT_TIME_PER_MINUTE => [
          'type'      => 'money',
          'required'  => false,
          'default'   => '',
          'min'       => 0,
        ],
        SD_Meta::DEADHEAD_ENABLED => [
          'type'      => 'bool',
          'required'  => true,
          'default'   => '0',
        ],
        SD_Meta::DEADHEAD_PER_MILE => [
          'type'      => 'money',
          'required'  => false,
          'default'   => '',
          'min'       => 0,
        ],
        SD_Meta::SERVICE_FEE => [
          'type'      => 'money',
          'required'  => false,
          'default'   => '',
          'min'       => 0,
        ],
        SD_Meta::QUOTE_EXPIRY_MINUTES => [
          'type'      => 'int',
          'required'  => true,
          'default'   => 30,
          'min'       => 1,
          'max'       => 1440,
        ],
        SD_Meta::LEAD_EXPIRY_MINUTES => [
          'type'      => 'int',
          'required'  => true,
          'default'   => 15,
          'min'       => 1,
          'max'       => 1440,
        ],
        SD_Meta::QUOTE_REQUIRES_MANUAL_REVIEW => [
          'type'      => 'bool',
          'required'  => true,
          'default'   => '0',
        ],
        SD_Meta::AFTER_HOURS_SURCHARGE_TYPE => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'none',
          'enum'      => ['none', 'flat', 'percent'],
        ],
        SD_Meta::AFTER_HOURS_SURCHARGE_VALUE => [
          'type'      => 'money',
          'required'  => false,
          'default'   => '',
          'min'       => 0,
        ],
      ],
    ];
  }

  private static function profile_section() : array {
    return [
      'label' => 'Tenant Profile',
      'fields' => [
        SD_Meta::PROFILE_BUSINESS_NAME => [
          'type'      => 'string',
          'required'  => true,
          'default'   => '',
          'max_len'   => 120,
        ],
        SD_Meta::PROFILE_TAGLINE => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 120,
        ],
        SD_Meta::PROFILE_DESCRIPTION => [
          'type'      => 'textarea',
          'required'  => false,
          'default'   => '',
          'max_len'   => 1000,
        ],
        SD_Meta::PROFILE_SUPPORT_PHONE => [
          'type'      => 'phone',
          'required'  => false,
          'default'   => '',
          'max_len'   => 32,
        ],
        SD_Meta::PROFILE_SUPPORT_EMAIL => [
          'type'      => 'email',
          'required'  => false,
          'default'   => '',
          'max_len'   => 190,
        ],
        SD_Meta::PROFILE_BOOKING_EMAIL => [
          'type'      => 'email',
          'required'  => false,
          'default'   => '',
          'max_len'   => 190,
        ],
        SD_Meta::PROFILE_WEBSITE_URL => [
          'type'      => 'url',
          'required'  => false,
          'default'   => '',
          'max_len'   => 255,
        ],
        SD_Meta::PROFILE_SERVICE_AREA_LABEL => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 120,
        ],
        SD_Meta::PROFILE_LICENSE_LABEL => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 120,
        ],
      ],
    ];
  }

  private static function vehicle_section() : array {
    return [
      'label' => 'Automobile Info',
      'fields' => [
        SD_Meta::VEHICLE_DISPLAY_NAME => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 120,
        ],
        SD_Meta::VEHICLE_SERVICE_CLASS => [
          'type'      => 'enum',
          'required'  => false,
          'default'   => 'standard',
          'enum'      => ['standard', 'suv', 'premium', 'executive', 'wheelchair', 'shuttle'],
        ],
        SD_Meta::VEHICLE_MAKE => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 60,
        ],
        SD_Meta::VEHICLE_MODEL => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 60,
        ],
        SD_Meta::VEHICLE_COLOR => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 40,
        ],
        SD_Meta::VEHICLE_YEAR => [
          'type'      => 'int',
          'required'  => false,
          'default'   => '',
          'min'       => 1980,
          'max'       => ((int) gmdate('Y')) + 1,
        ],
        SD_Meta::VEHICLE_PLATE_MASKED => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 16,
        ],
        SD_Meta::VEHICLE_CAPACITY => [
          'type'      => 'int',
          'required'  => false,
          'default'   => '',
          'min'       => 0,
          'max'       => 99,
        ],
        SD_Meta::VEHICLE_LUGGAGE_CAPACITY => [
          'type'      => 'int',
          'required'  => false,
          'default'   => '',
          'min'       => 0,
          'max'       => 99,
        ],
        SD_Meta::VEHICLE_ACCESSIBILITY_NOTES => [
          'type'      => 'textarea',
          'required'  => false,
          'default'   => '',
          'max_len'   => 500,
        ],
      ],
    ];
  }

  private static function base_location_section() : array {
    return [
      'label' => 'Base Location',
      'fields' => [
        SD_Meta::BASE_LOCATION_LABEL => [
          'type'      => 'string',
          'required'  => true,
          'default'   => '',
          'max_len'   => 190,
        ],
        SD_Meta::BASE_LOCATION_PLACE_ID => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 255,
        ],
        SD_Meta::BASE_LOCATION_LAT => [
          'type'      => 'lat',
          'required'  => true,
          'default'   => '',
        ],
        SD_Meta::BASE_LOCATION_LNG => [
          'type'      => 'lng',
          'required'  => true,
          'default'   => '',
        ],
        SD_Meta::BASE_LOCATION_RADIUS_M => [
          'type'      => 'int',
          'required'  => true,
          'default'   => 40000,
          'min'       => 1,
          'max'       => 1000000,
        ],
        SD_Meta::SERVICE_RADIUS_MODE => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'base_circle',
          'enum'      => ['base_circle', 'pickup_only', 'flexible'],
        ],
        SD_Meta::PICKUP_RADIUS_M => [
          'type'      => 'int',
          'required'  => false,
          'default'   => '',
          'min'       => 1,
          'max'       => 1000000,
        ],
        SD_Meta::DROPOFF_RADIUS_M => [
          'type'      => 'int',
          'required'  => false,
          'default'   => '',
          'min'       => 1,
          'max'       => 1000000,
        ],
        SD_Meta::OUT_OF_AREA_POLICY => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'request_quote',
          'enum'      => ['reject', 'request_quote', 'allow_with_surcharge'],
        ],
      ],
    ];
  }

  private static function brand_section() : array {
    return [
      'label' => 'Brand Config',
      'fields' => [
        SD_Meta::BRAND_NAME_SHORT => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 60,
        ],
        SD_Meta::BRAND_LOGO_ID => [
          'type'      => 'attachment_id',
          'required'  => false,
          'default'   => '',
          'min'       => 1,
        ],
        SD_Meta::BRAND_WORDMARK_ID => [
          'type'      => 'attachment_id',
          'required'  => false,
          'default'   => '',
          'min'       => 1,
        ],
        SD_Meta::BRAND_PRIMARY_COLOR => [
          'type'      => 'hex_color',
          'required'  => false,
          'default'   => '',
          'max_len'   => 7,
        ],
        SD_Meta::BRAND_SECONDARY_COLOR => [
          'type'      => 'hex_color',
          'required'  => false,
          'default'   => '',
          'max_len'   => 7,
        ],
        SD_Meta::BRAND_ACCENT_COLOR => [
          'type'      => 'hex_color',
          'required'  => false,
          'default'   => '',
          'max_len'   => 7,
        ],
        SD_Meta::BRAND_BUTTON_STYLE => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'rounded',
          'enum'      => ['rounded', 'pill', 'square'],
        ],
        SD_Meta::BRAND_THEME_MODE => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'light',
          'enum'      => ['light', 'dark', 'auto'],
        ],
      ],
    ];
  }

  private static function calendar_section() : array {
    return [
      'label' => 'Calendar',
      'fields' => [
        SD_Meta::CALENDAR_MODE => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'always_on',
          'enum'      => ['always_on', 'business_hours', 'schedule_only'],
        ],
        SD_Meta::CALENDAR_TIMEZONE => [
          'type'      => 'timezone',
          'required'  => false,
          'default'   => '',
          'max_len'   => 64,
        ],
        SD_Meta::HOURS_MONDAY => [
          'type'      => 'json',
          'required'  => false,
          'default'   => '',
        ],
        SD_Meta::HOURS_TUESDAY => [
          'type'      => 'json',
          'required'  => false,
          'default'   => '',
        ],
        SD_Meta::HOURS_WEDNESDAY => [
          'type'      => 'json',
          'required'  => false,
          'default'   => '',
        ],
        SD_Meta::HOURS_THURSDAY => [
          'type'      => 'json',
          'required'  => false,
          'default'   => '',
        ],
        SD_Meta::HOURS_FRIDAY => [
          'type'      => 'json',
          'required'  => false,
          'default'   => '',
        ],
        SD_Meta::HOURS_SATURDAY => [
          'type'      => 'json',
          'required'  => false,
          'default'   => '',
        ],
        SD_Meta::HOURS_SUNDAY => [
          'type'      => 'json',
          'required'  => false,
          'default'   => '',
        ],
        SD_Meta::BLACKOUT_DATES_JSON => [
          'type'      => 'json',
          'required'  => false,
          'default'   => '',
        ],
        SD_Meta::SAME_DAY_BOOKING_CUTOFF_MINUTES => [
          'type'      => 'int',
          'required'  => false,
          'default'   => 0,
          'min'       => 0,
          'max'       => 1440,
        ],
        SD_Meta::ADVANCE_BOOKING_MAX_DAYS => [
          'type'      => 'int',
          'required'  => false,
          'default'   => 0,
          'min'       => 0,
          'max'       => 3650,
        ],
      ],
    ];
  }

  private static function drive_mode_section() : array {
    return [
      'label' => 'Drive Mode',
      'fields' => [
        SD_Meta::DRIVE_MODE_ENABLED => [
          'type'      => 'bool',
          'required'  => true,
          'default'   => '0',
        ],
        SD_Meta::DRIVE_MODE_STATUS => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'offline',
          'enum'      => ['online', 'paused', 'offline'],
        ],
        SD_Meta::LIVE_DISPATCH_ENABLED => [
          'type'      => 'bool',
          'required'  => true,
          'default'   => '0',
        ],
        SD_Meta::AUTO_ASSIGN_ENABLED => [
          'type'      => 'bool',
          'required'  => true,
          'default'   => '0',
        ],
        SD_Meta::DRIVER_VISIBILITY_MODE => [
          'type'      => 'enum',
          'required'  => true,
          'default'   => 'tenant_only',
          'enum'      => ['tenant_only', 'assigned_only'],
        ],
        SD_Meta::OPS_NOTE => [
          'type'      => 'string',
          'required'  => false,
          'default'   => '',
          'max_len'   => 280,
        ],

        // -------------------------------------------------------------------
        // Mirrored runtime / diagnostics fields
        // -------------------------------------------------------------------
        SD_Meta::TENANT_LAST_LOCATION_LAT => [
          'type'      => 'lat',
          'required'  => false,
          'default'   => '',
          'readonly'  => true,
          'derived'   => true,
        ],
        SD_Meta::TENANT_LAST_LOCATION_LNG => [
          'type'      => 'lng',
          'required'  => false,
          'default'   => '',
          'readonly'  => true,
          'derived'   => true,
        ],
        SD_Meta::TENANT_LAST_LOCATION_TS => [
          'type'      => 'int',
          'required'  => false,
          'default'   => '',
          'readonly'  => true,
          'derived'   => true,
          'min'       => 1,
        ],
        SD_Meta::TENANT_LAST_LOCATION_ACCURACY_M => [
          'type'      => 'money',
          'required'  => false,
          'default'   => '',
          'readonly'  => true,
          'derived'   => true,
          'min'       => 0,
        ],
      ],
    ];
  }

  // ---------------------------------------------------------------------------
  // Normalization
  // ---------------------------------------------------------------------------

  private static function normalize_field_value(array $field, $value) {
    $type = (string) ($field['type'] ?? 'string');

    if (is_string($value)) {
      $value = trim($value);
    }

    switch ($type) {
      case 'bool':
        return self::normalize_bool($value);

      case 'int':
      case 'attachment_id':
        if ($value === '' || $value === null) {
          return '';
        }
        return (int) $value;

      case 'money':
        return self::normalize_money($value);

      case 'currency':
        return strtoupper(substr((string) $value, 0, 3));

      case 'slug':
        return sanitize_title((string) $value);

      case 'domain':
        return self::normalize_domain($value);

      case 'timezone':
        return self::normalize_timezone($value);

      case 'email':
        return sanitize_email((string) $value);

      case 'url':
        return esc_url_raw((string) $value);

      case 'phone':
        return self::normalize_phone($value);

      case 'hex_color':
        return self::normalize_hex_color($value);

      case 'lat':
      case 'lng':
        if ($value === '' || $value === null) {
          return '';
        }
        return self::normalize_decimal_string($value, 6);

      case 'json':
        return self::normalize_jsonish($value);

      case 'textarea':
        return self::normalize_textarea($value);

      case 'enum':
      case 'string':
      default:
        return sanitize_text_field((string) $value);
    }
  }

  private static function normalize_bool($value) : string {
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    $value = strtolower((string) $value);
    return in_array($value, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
  }

  private static function normalize_money($value) : string {
    if ($value === '' || $value === null) {
      return '';
    }

    $value = preg_replace('/[^0-9\.\-]/', '', (string) $value);
    if ($value === '' || !is_numeric($value)) {
      return '';
    }

    return number_format((float) $value, 2, '.', '');
  }

  private static function normalize_decimal_string($value, int $precision = 6) : string {
    $value = preg_replace('/[^0-9\.\-]/', '', (string) $value);
    if ($value === '' || !is_numeric($value)) {
      return '';
    }
    return number_format((float) $value, $precision, '.', '');
  }

  private static function normalize_domain($value) : string {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('#^https?://#', '', $value);
    $value = preg_replace('#/.*$#', '', $value);
    return sanitize_text_field($value);
  }

  private static function normalize_timezone($value) : string {
    return trim((string) $value);
  }

  private static function normalize_phone($value) : string {
    $value = trim((string) $value);
    $value = preg_replace('/[^0-9\+\-\(\)\.\s]/', '', $value);
    return (string) $value;
  }

  private static function normalize_hex_color($value) : string {
    $value = trim((string) $value);
    if ($value === '') {
      return '';
    }
    if ($value[0] !== '#') {
      $value = '#' . $value;
    }
    $value = strtoupper($value);
    return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : '';
  }

  private static function normalize_jsonish($value) : string {
    if ($value === '' || $value === null) {
      return '';
    }

    if (is_array($value) || is_object($value)) {
      $encoded = wp_json_encode($value);
      return is_string($encoded) ? $encoded : '';
    }

    $value = trim((string) $value);
    if ($value === '') {
      return '';
    }

    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE) {
      $encoded = wp_json_encode($decoded);
      return is_string($encoded) ? $encoded : '';
    }

    return '';
  }

  private static function normalize_textarea($value) : string {
    $value = (string) $value;
    $value = wp_kses_post($value);
    return trim($value);
  }

  

  // ---------------------------------------------------------------------------
  // Validation
  // ---------------------------------------------------------------------------

  private static function validate_field(string $meta_key, array $field, $value, array $normalized) : array {
    $errors   = [];
    $type     = (string) ($field['type'] ?? 'string');
    $required = !empty($field['required']);

    if ($required && self::is_empty_value($value)) {
      $errors[] = 'This field is required.';
      return $errors;
    }

    if (self::is_empty_value($value)) {
      return $errors;
    }

    if (isset($field['max_len']) && is_string($value) && mb_strlen($value) > (int) $field['max_len']) {
      $errors[] = 'Value exceeds maximum length.';
    }

    switch ($type) {
      case 'enum':
        $enum = (array) ($field['enum'] ?? []);
        if (!in_array((string) $value, $enum, true)) {
          $errors[] = 'Invalid value.';
        }
        break;

      case 'int':
      case 'attachment_id':
        if (!is_int($value)) {
          $errors[] = 'Must be an integer.';
          break;
        }
        if (isset($field['min']) && $value < (int) $field['min']) {
          $errors[] = 'Must be greater than or equal to ' . (int) $field['min'] . '.';
        }
        if (isset($field['max']) && $value > (int) $field['max']) {
          $errors[] = 'Must be less than or equal to ' . (int) $field['max'] . '.';
        }
        break;

      case 'money':
        if (!preg_match('/^\d+(\.\d{2})$/', (string) $value)) {
          $errors[] = 'Must be a valid amount with 2 decimal places.';
          break;
        }
        if (isset($field['min']) && (float) $value < (float) $field['min']) {
          $errors[] = 'Must be greater than or equal to ' . $field['min'] . '.';
        }
        break;

      case 'currency':
        if (!preg_match('/^[A-Z]{3}$/', (string) $value)) {
          $errors[] = 'Must be a 3-letter currency code.';
        }
        break;

      case 'slug':
        if (!preg_match('/^[a-z0-9\-]+$/', (string) $value)) {
          $errors[] = 'Must be a lowercase slug.';
        }
        break;

      case 'domain':
        if (!preg_match('/^[a-z0-9][a-z0-9\.\-]*[a-z0-9]$/', (string) $value) || strpos((string) $value, '.') === false) {
          $errors[] = 'Must be a valid host/domain.';
        }
        break;

      case 'timezone':
        if (!in_array((string) $value, timezone_identifiers_list(), true)) {
          $errors[] = 'Must be a valid timezone identifier.';
        }
        break;

      case 'email':
        if (!is_email((string) $value)) {
          $errors[] = 'Must be a valid email address.';
        }
        break;

      case 'url':
        if (!filter_var((string) $value, FILTER_VALIDATE_URL)) {
          $errors[] = 'Must be a valid URL.';
        }
        break;

      case 'hex_color':
        if (!preg_match('/^#[0-9A-F]{6}$/', (string) $value)) {
          $errors[] = 'Must be a valid hex color.';
        }
        break;

      case 'lat':
        if (!is_numeric($value) || (float) $value < -90 || (float) $value > 90) {
          $errors[] = 'Must be a valid latitude.';
        }
        break;

      case 'lng':
        if (!is_numeric($value) || (float) $value < -180 || (float) $value > 180) {
          $errors[] = 'Must be a valid longitude.';
        }
        break;

      case 'json':
        json_decode((string) $value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
          $errors[] = 'Must be valid JSON.';
        }
        break;
    }

    return $errors;
  }

  private static function validate_cross_field_rules(string $section, array $values) : array {
    $errors = [];

    if ($section === self::SECTION_STOREFRONT) {
      $hours_mode = (string) ($values[SD_Meta::STOREFRONT_HOURS_MODE] ?? '');
      $tz         = (string) ($values[SD_Meta::STOREFRONT_TIMEZONE] ?? '');
      $mode       = (string) ($values[SD_Meta::STOREFRONT_REQUEST_MODE] ?? '');
      $booking    = (string) ($values[SD_Meta::STOREFRONT_ALLOWS_IMMEDIATE_BOOKING] ?? '0');

      if ($hours_mode === 'scheduled' && $tz === '') {
        $errors[SD_Meta::STOREFRONT_TIMEZONE][] = 'Timezone is required when hours mode is scheduled.';
      }

      if ($booking === '1' && $mode === 'quote_only') {
        $errors[SD_Meta::STOREFRONT_ALLOWS_IMMEDIATE_BOOKING][] = 'Immediate booking cannot be enabled when request mode is quote_only.';
      }
    }

    if ($section === self::SECTION_PRICING) {
      $pricing_model = (string) ($values[SD_Meta::PRICING_MODEL] ?? '');
      $quote_mode    = (string) ($values[SD_Meta::QUOTE_MODE] ?? '');
      $deadhead_on   = (string) ($values[SD_Meta::DEADHEAD_ENABLED] ?? '0');
      $deadhead_rate = $values[SD_Meta::DEADHEAD_PER_MILE] ?? '';
      $s_type        = (string) ($values[SD_Meta::AFTER_HOURS_SURCHARGE_TYPE] ?? 'none');
      $s_value       = $values[SD_Meta::AFTER_HOURS_SURCHARGE_VALUE] ?? '';

      $base_fare     = $values[SD_Meta::BASE_FARE] ?? '';
      $min_fare      = $values[SD_Meta::MINIMUM_FARE] ?? '';
      $per_mile      = $values[SD_Meta::PER_MILE_RATE] ?? '';
      $per_minute    = $values[SD_Meta::PER_MINUTE_RATE] ?? '';

      if ($pricing_model === 'manual_only' && $quote_mode === 'automatic') {
        $errors[SD_Meta::QUOTE_MODE][] = 'Quote mode automatic is not allowed when pricing model is manual_only.';
      }

      if ($pricing_model === 'distance_time') {
        $has_base_or_min = ($base_fare !== '' || $min_fare !== '');
        $has_rates       = ($per_mile !== '' || $per_minute !== '');

        if (!$has_base_or_min && !$has_rates) {
          $errors[SD_Meta::PRICING_MODEL][] = 'Distance/time pricing requires a base/minimum fare or mileage/minute rates.';
        }
      }

      if ($deadhead_on === '1' && $deadhead_rate === '') {
        $errors[SD_Meta::DEADHEAD_PER_MILE][] = 'Deadhead per-mile rate is required when deadhead is enabled.';
      }

      if (in_array($s_type, ['flat', 'percent'], true) && $s_value === '') {
        $errors[SD_Meta::AFTER_HOURS_SURCHARGE_VALUE][] = 'Surcharge value is required when after-hours surcharge is enabled.';
      }
    }

    if ($section === self::SECTION_BASE_LOCATION) {
      $mode = (string) ($values[SD_Meta::SERVICE_RADIUS_MODE] ?? '');

      if ($mode === 'pickup_only' && ($values[SD_Meta::PICKUP_RADIUS_M] ?? '') === '') {
        $errors[SD_Meta::PICKUP_RADIUS_M][] = 'Pickup radius is required when service radius mode is pickup_only.';
      }

      if ($mode === 'flexible') {
        if (($values[SD_Meta::PICKUP_RADIUS_M] ?? '') === '') {
          $errors[SD_Meta::PICKUP_RADIUS_M][] = 'Pickup radius is required when service radius mode is flexible.';
        }
        if (($values[SD_Meta::DROPOFF_RADIUS_M] ?? '') === '') {
          $errors[SD_Meta::DROPOFF_RADIUS_M][] = 'Dropoff radius is required when service radius mode is flexible.';
        }
      }
    }

    if ($section === self::SECTION_CALENDAR) {
      $mode = (string) ($values[SD_Meta::CALENDAR_MODE] ?? '');
      $tz   = (string) ($values[SD_Meta::CALENDAR_TIMEZONE] ?? '');

      if (in_array($mode, ['business_hours', 'schedule_only'], true) && $tz === '') {
        $errors[SD_Meta::CALENDAR_TIMEZONE][] = 'Timezone is required when calendar mode is business_hours or schedule_only.';
      }
    }

    return $errors;
  }

  private static function is_empty_value($value) : bool {
    return $value === '' || $value === null || $value === [];
  }
}