<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Meta — Canonical constants registry (v2.0)
 *
 * Doctrine:
 * - Public keys use sd_*
 * - Private/system keys use _sd_*
 * - Lead is the root engagement object
 * - Canonical lifecycle:
 *     lead -> quote -> auth-attempt -> ride -> pmt-capture
 * - Platform isolation index:
 *     sd_tenant_id
 * - Tenant operations lineage index:
 *     sd_lead_id
 *
 * Safety:
 * - Must be safe to load multiple times.
 * - If already loaded, return early.
 */

if (class_exists('SD_Meta', false)) { return; }

final class SD_Meta {

  // ---------------------------------------------------------------------------
  // CPT slugs (LOCKED)
  // ---------------------------------------------------------------------------
  public const TENANT_CPT   = 'sd_tenant';
  public const LEAD_CPT     = 'sd_lead';
  public const QUOTE_CPT    = 'sd_quote';
  public const ATTEMPT_CPT  = 'sd_attempt';
  public const RIDE_CPT     = 'sd_ride';
  public const TIMEBLOCK_CPT = 'sd_time_block';

  // ---------------------------------------------------------------------------
  // Shared tenant scoping (REQUIRED on all tenant-scoped records)
  // ---------------------------------------------------------------------------
  public const TENANT_ID = 'sd_tenant_id';

  // ---------------------------------------------------------------------------
  // Shared lineage / relationship keys
  // ---------------------------------------------------------------------------
  public const LEAD_ID    = 'sd_lead_id';   // canonical child -> lead link
  public const QUOTE_ID   = 'sd_quote_id';  // optional convenience linkage
  public const ATTEMPT_ID = 'sd_attempt_id';
  public const RIDE_ID    = 'sd_ride_id';

  // ---------------------------------------------------------------------------
  // Lead timing mode values (LOCKED)
  // ---------------------------------------------------------------------------
  public const LEAD_MODE_ASAP    = 'ASAP';
  public const LEAD_MODE_RESERVE = 'RESERVE';

  // ---------------------------------------------------------------------------
  // Lead lifecycle values (LOCKED)
  // ---------------------------------------------------------------------------
  public const LEAD_CAPTURED      = 'LEAD_CAPTURED';
  public const LEAD_WAITING_QUOTE = 'LEAD_WAITING_QUOTE';
  public const LEAD_OFFERED       = 'LEAD_OFFERED';
  public const LEAD_PROMOTED      = 'LEAD_PROMOTED';
  public const LEAD_DECLINED      = 'LEAD_DECLINED';
  public const LEAD_EXPIRED       = 'LEAD_EXPIRED';
  public const LEAD_AUTH_FAILED   = 'LEAD_AUTH_FAILED';

  // ---------------------------------------------------------------------------
  // Quote lifecycle values (LOCKED)
  // ---------------------------------------------------------------------------
  public const QUOTE_PROPOSED        = 'PROPOSED';
  public const QUOTE_APPROVED        = 'APPROVED';
  public const QUOTE_PRESENTED       = 'PRESENTED';
  public const QUOTE_USER_REJECTED   = 'USER_REJECTED';
  public const QUOTE_USER_TIMEOUT    = 'USER_TIMEOUT';
  public const QUOTE_LEAD_ACCEPTED   = 'LEAD_ACCEPTED';
  public const QUOTE_PAYMENT_PENDING = 'PAYMENT_PENDING';
  public const QUOTE_LEAD_REJECTED   = 'LEAD_REJECTED';
  public const QUOTE_EXPIRED         = 'EXPIRED';
  public const QUOTE_SUPERSEDED      = 'SUPERSEDED';
  public const QUOTE_CANCELLED       = 'CANCELLED';

  // ---------------------------------------------------------------------------
  // Attempt lifecycle values (LOCKED)
  // ---------------------------------------------------------------------------
  public const ATTEMPT_PENDING       = 'PENDING';
  public const ATTEMPT_REQUIRES_USER = 'REQUIRES_USER';
  public const ATTEMPT_PROCESSING    = 'PROCESSING';
  public const ATTEMPT_AUTHORIZED    = 'AUTHORIZED';
  public const ATTEMPT_FAILED        = 'FAILED';
  public const ATTEMPT_EXPIRED       = 'EXPIRED';
  public const ATTEMPT_CANCELLED     = 'CANCELLED';
  public const ATTEMPT_CAPTURED      = 'CAPTURED';

  // ---------------------------------------------------------------------------
  // Ride lifecycle values (LOCKED)
  // ---------------------------------------------------------------------------
  public const RIDE_QUEUED     = 'RIDE_QUEUED';
  public const RIDE_DEADHEAD   = 'RIDE_DEADHEAD';
  public const RIDE_WAITING    = 'RIDE_WAITING';
  public const RIDE_INPROGRESS = 'RIDE_INPROGRESS';
  public const RIDE_ARRIVED    = 'RIDE_ARRIVED';
  public const RIDE_COMPLETE   = 'RIDE_COMPLETE';
  public const RIDE_CANCELLED  = 'RIDE_CANCELLED';

  // ---------------------------------------------------------------------------
  // Storefront state values (LOCKED)
  // ---------------------------------------------------------------------------
  public const STORE_STATE_OPEN   = 'open';
  public const STORE_STATE_BUSY   = 'busy';
  public const STORE_STATE_CLOSED = 'closed';

  // ---------------------------------------------------------------------------
  // Tenant
  // ---------------------------------------------------------------------------
  public const TENANT_DOMAIN     = 'sd_tenant_domain';
  public const TENANT_SLUG       = 'sd_tenant_slug';
  public const STOREFRONT_STATE  = 'sd_storefront_state';
  public const STRIPE_ACCOUNT_ID = 'sd_stripe_account_id';

  // ---------------------------------------------------------------------------
  // Tenant live / operational location snapshot
  // ---------------------------------------------------------------------------
  public const TENANT_LAST_LOCATION_LABEL      = 'sd_tenant_last_location_label';
  public const TENANT_LAST_LOCATION_LAT        = 'sd_tenant_last_location_lat';
  public const TENANT_LAST_LOCATION_LNG        = 'sd_tenant_last_location_lng';
  public const TENANT_LAST_LOCATION_TS         = 'sd_tenant_last_location_ts';
  public const TENANT_LAST_LOCATION_ACCURACY_M = 'sd_tenant_last_location_accuracy_m';

  // ---------------------------------------------------------------------------
  // Tenant intake / UX / integrations
  // ---------------------------------------------------------------------------
  public const INTAKE_REQUEST_PAGE_SLUG = 'sd_intake_request_page_slug';
  public const INTAKE_CF7_FORM_ID       = 'sd_intake_cf7_form_id'; // legacy only

  // Tenant Google settings (only if tenant-specific overrides exist)
  public const GOOGLE_MAPS_BROWSER_KEY  = 'sd_google_maps_browser_key';
  public const GOOGLE_ROUTES_SERVER_KEY = 'sd_google_routes_server_key';

  // ---------------------------------------------------------------------------
  // Tenant settings — Storefront config
  // ---------------------------------------------------------------------------
  public const STOREFRONT_ENABLED                  = 'sd_storefront_enabled';
  public const STOREFRONT_ACCEPTING_REQUESTS       = 'sd_storefront_accepting_requests';
  public const STOREFRONT_REQUEST_MODE             = 'sd_storefront_request_mode'; // quote_only|booking_only|quote_or_booking
  public const STOREFRONT_CLOSURE_MESSAGE          = 'sd_storefront_closure_message';
  public const STOREFRONT_BUSY_MESSAGE             = 'sd_storefront_busy_message';
  public const STOREFRONT_HOURS_MODE               = 'sd_storefront_hours_mode'; // always_on|scheduled
  public const STOREFRONT_TIMEZONE                 = 'sd_storefront_timezone';
  public const STOREFRONT_REQUIRES_QUOTE           = 'sd_storefront_requires_quote';
  public const STOREFRONT_ALLOWS_IMMEDIATE_BOOKING = 'sd_storefront_allows_immediate_booking';
  public const STOREFRONT_BUFFER_MINUTES           = 'sd_storefront_buffer_minutes';

  // ---------------------------------------------------------------------------
  // Tenant settings — Stack / scheduling controls
  // ---------------------------------------------------------------------------
  public const STACK_MAX_CHAIN_LENGTH = 'sd_stack_max_chain_length';
  public const STACK_MAX_WAIT_MINUTES = 'sd_stack_max_wait_minutes';

  // ---------------------------------------------------------------------------
  // Tenant settings — Pricing / quote engine
  // ---------------------------------------------------------------------------
  public const QUOTE_MODE                   = 'sd_quote_mode'; // disabled|manual|automatic|hybrid
  public const PRICING_MODEL                = 'sd_pricing_model'; // flat|distance_time|manual_only
  public const CURRENCY                     = 'sd_currency';
  public const BASE_FARE                    = 'sd_base_fare';
  public const MINIMUM_FARE                 = 'sd_minimum_fare';
  public const PER_MILE_RATE                = 'sd_per_mile_rate';
  public const PER_MINUTE_RATE              = 'sd_per_minute_rate';
  public const WAIT_TIME_PER_MINUTE         = 'sd_wait_time_per_minute';
  public const DEADHEAD_ENABLED             = 'sd_deadhead_enabled';
  public const DEADHEAD_PER_MILE            = 'sd_deadhead_per_mile';
  public const SERVICE_FEE                  = 'sd_service_fee';
  public const QUOTE_EXPIRY_MINUTES         = 'sd_quote_expiry_minutes';
  public const LEAD_EXPIRY_MINUTES          = 'sd_lead_expiry_minutes';
  public const QUOTE_REQUIRES_MANUAL_REVIEW = 'sd_quote_requires_manual_review';
  public const AFTER_HOURS_SURCHARGE_TYPE   = 'sd_after_hours_surcharge_type'; // none|flat|percent
  public const AFTER_HOURS_SURCHARGE_VALUE  = 'sd_after_hours_surcharge_value';

  // ---------------------------------------------------------------------------
  // Tenant settings — Public tenant profile
  // ---------------------------------------------------------------------------
  public const PROFILE_BUSINESS_NAME      = 'sd_profile_business_name';
  public const PROFILE_TAGLINE            = 'sd_profile_tagline';
  public const PROFILE_DESCRIPTION        = 'sd_profile_description';
  public const PROFILE_SUPPORT_PHONE      = 'sd_profile_support_phone';
  public const PROFILE_SUPPORT_EMAIL      = 'sd_profile_support_email';
  public const PROFILE_BOOKING_EMAIL      = 'sd_profile_booking_email';
  public const PROFILE_WEBSITE_URL        = 'sd_profile_website_url';
  public const PROFILE_SERVICE_AREA_LABEL = 'sd_profile_service_area_label';
  public const PROFILE_LICENSE_LABEL      = 'sd_profile_license_label';

  // ---------------------------------------------------------------------------
  // Tenant settings — Vehicle / service info
  // ---------------------------------------------------------------------------
  public const VEHICLE_DISPLAY_NAME        = 'sd_vehicle_display_name';
  public const VEHICLE_SERVICE_CLASS       = 'sd_vehicle_service_class'; // standard|suv|premium|executive|wheelchair|shuttle
  public const VEHICLE_MAKE                = 'sd_vehicle_make';
  public const VEHICLE_MODEL               = 'sd_vehicle_model';
  public const VEHICLE_COLOR               = 'sd_vehicle_color';
  public const VEHICLE_YEAR                = 'sd_vehicle_year';
  public const VEHICLE_PLATE_MASKED        = 'sd_vehicle_plate_masked';
  public const VEHICLE_CAPACITY            = 'sd_vehicle_capacity';
  public const VEHICLE_LUGGAGE_CAPACITY    = 'sd_vehicle_luggage_capacity';
  public const VEHICLE_ACCESSIBILITY_NOTES = 'sd_vehicle_accessibility_notes';

  // ---------------------------------------------------------------------------
  // Tenant base location / service area
  // ---------------------------------------------------------------------------
  public const BASE_LOCATION_LABEL    = 'sd_base_location_label';
  public const BASE_LOCATION_PLACE_ID = 'sd_base_location_place_id';
  public const BASE_LOCATION_LAT      = 'sd_base_location_lat';
  public const BASE_LOCATION_LNG      = 'sd_base_location_lng';
  public const BASE_LOCATION_RADIUS_M = 'sd_base_location_radius_m';

  public const SERVICE_RADIUS_MODE = 'sd_service_radius_mode'; // base_circle|pickup_only|flexible
  public const PICKUP_RADIUS_M     = 'sd_pickup_radius_m';
  public const DROPOFF_RADIUS_M    = 'sd_dropoff_radius_m';
  public const OUT_OF_AREA_POLICY  = 'sd_out_of_area_policy'; // reject|request_quote|allow_with_surcharge

  // ---------------------------------------------------------------------------
  // Tenant settings — Brand
  // ---------------------------------------------------------------------------
  public const BRAND_NAME_SHORT      = 'sd_brand_name_short';
  public const BRAND_LOGO_ID         = 'sd_brand_logo_id';
  public const BRAND_WORDMARK_ID     = 'sd_brand_wordmark_id';
  public const BRAND_PRIMARY_COLOR   = 'sd_brand_primary_color';
  public const BRAND_SECONDARY_COLOR = 'sd_brand_secondary_color';
  public const BRAND_ACCENT_COLOR    = 'sd_brand_accent_color';
  public const BRAND_BUTTON_STYLE    = 'sd_brand_button_style'; // rounded|pill|square
  public const BRAND_THEME_MODE      = 'sd_brand_theme_mode'; // light|dark|auto

  // ---------------------------------------------------------------------------
  // Tenant settings — Calendar / availability
  // ---------------------------------------------------------------------------
  public const CALENDAR_MODE                   = 'sd_calendar_mode'; // always_on|business_hours|schedule_only
  public const CALENDAR_TIMEZONE               = 'sd_calendar_timezone';
  public const HOURS_MONDAY                    = 'sd_hours_monday';
  public const HOURS_TUESDAY                   = 'sd_hours_tuesday';
  public const HOURS_WEDNESDAY                 = 'sd_hours_wednesday';
  public const HOURS_THURSDAY                  = 'sd_hours_thursday';
  public const HOURS_FRIDAY                    = 'sd_hours_friday';
  public const HOURS_SATURDAY                  = 'sd_hours_saturday';
  public const HOURS_SUNDAY                    = 'sd_hours_sunday';
  public const BLACKOUT_DATES_JSON             = 'sd_blackout_dates_json';
  public const SAME_DAY_BOOKING_CUTOFF_MINUTES = 'sd_same_day_booking_cutoff_minutes';
  public const ADVANCE_BOOKING_MAX_DAYS        = 'sd_advance_booking_max_days';

  // ---------------------------------------------------------------------------
  // Tenant settings — Drive mode / live ops posture
  // ---------------------------------------------------------------------------
  public const DRIVE_MODE_ENABLED     = 'sd_drive_mode_enabled';
  public const DRIVE_MODE_STATUS      = 'sd_drive_mode_status'; // online|paused|offline
  public const LIVE_DISPATCH_ENABLED  = 'sd_live_dispatch_enabled';
  public const AUTO_ASSIGN_ENABLED    = 'sd_auto_assign_enabled';
  public const DRIVER_VISIBILITY_MODE = 'sd_driver_visibility_mode'; // tenant_only|assigned_only
  public const OPS_NOTE               = 'sd_ops_note';

  // ---------------------------------------------------------------------------
  // Shared public contact / identity
  // ---------------------------------------------------------------------------
  public const CUSTOMER_PHONE = 'sd_customer_phone';
  public const CUSTOMER_NAME  = 'sd_customer_name';
  public const CONTACT_METHOD = 'sd_contact_method'; // sms|email
  public const CONTACT_VALUE  = 'sd_contact_value';

  // ---------------------------------------------------------------------------
  // Shared intake location fields
  // ---------------------------------------------------------------------------
  public const PICKUP_TEXT      = 'sd_pickup_text';        // non-authoritative display string
  public const DROPOFF_TEXT     = 'sd_dropoff_text';       // non-authoritative display string
  public const PICKUP_PLACE_ID  = 'sd_pickup_place_id';    // authoritative
  public const DROPOFF_PLACE_ID = 'sd_dropoff_place_id';   // authoritative
  public const PICKUP_LAT       = 'sd_pickup_lat';
  public const PICKUP_LNG       = 'sd_pickup_lng';
  public const DROPOFF_LAT      = 'sd_dropoff_lat';
  public const DROPOFF_LNG      = 'sd_dropoff_lng';

  // ---------------------------------------------------------------------------
  // Lead (ROOT engagement record)
  // ---------------------------------------------------------------------------
  public const TRIP_TOKEN         = 'sd_trip_token';
  public const TRIP_TOKEN_HASH    = 'sd_trip_token_hash';
  public const LEAD_STATUS        = 'sd_lead_status';
  public const REQUEST_MODE       = 'sd_request_mode';     // ASAP|RESERVE
  public const REQUESTED_TS       = 'sd_requested_ts';     // canonical requested pickup timestamp
  public const REQUESTED_DATE     = 'sd_requested_date';   // optional UI decomposition
  public const REQUESTED_TIME     = 'sd_requested_time';   // optional UI decomposition
  public const AVAILABILITY_STATUS = 'sd_availability_status'; // pending|available|unavailable

  public const CURRENT_QUOTE_ID   = 'sd_current_quote_id';
  public const CURRENT_ATTEMPT_ID = 'sd_current_attempt_id';
  public const PROMOTED_RIDE_ID   = 'sd_promoted_ride_id';

  // Legacy / compatibility field; do not use as canonical lead time
  public const PICKUP_SCHEDULED_TS = 'sd_pickup_scheduled_ts';

  // ---------------------------------------------------------------------------
  // Lead minimum capture doctrine
  // ---------------------------------------------------------------------------
  // Required to qualify as LEAD_CAPTURED:
  // - TENANT_ID
  // - PICKUP_PLACE_ID
  // - DROPOFF_PLACE_ID
  // - REQUESTED_TS
  // - CUSTOMER_NAME
  // - CUSTOMER_PHONE (pattern-verified)

  // ---------------------------------------------------------------------------
  // Quote (child of lead)
  // ---------------------------------------------------------------------------
  public const QUOTE_STATUS             = 'sd_quote_status';
  public const QUOTE_TOTAL_CENTS        = 'sd_quote_total_cents';
  public const QUOTE_CURRENCY           = 'sd_quote_currency';
  public const QUOTE_CONFIDENCE         = 'sd_quote_confidence';
  public const QUOTE_PRESENTABLE_TOTAL  = 'sd_quote_presentable_total';

  // ---------------------------------------------------------------------------
  // Attempt (child of lead; may also point to quote and ride)
  // ---------------------------------------------------------------------------
  public const ATTEMPT_STATUS = 'sd_attempt_status';

  // ---------------------------------------------------------------------------
  // Ride (child of lead; operational execution only)
  // ---------------------------------------------------------------------------
  public const RIDE_STATE        = 'sd_ride_state';
  public const RIDE_MODE         = 'sd_ride_mode';        // ON_DEMAND|STACKED|WAITLIST|RESERVED
  public const SERVICE_START_TS  = 'sd_service_start_ts';
  public const SERVICE_END_TS    = 'sd_service_end_ts';
  public const TIME_BLOCK_ID     = 'sd_time_block_id';

  // Reservation-specific
  public const RESERVATION_TOKEN      = 'sd_reservation_token';
  public const RESERVE_NOTES          = 'sd_reserve_notes';
  public const RESERVATION_CREATED_TS = 'sd_reservation_created_ts';

  // Stacking context
  public const STACKED_ON_RIDE_ID = 'sd_stacked_on_ride_id';
  public const STACK_POSITION     = 'sd_stack_position';

  // Cancellation policy snapshot
  public const CANCEL_POLICY_MODE     = 'sd_cancel_policy_mode';
  public const CANCEL_POLICY_SNAPSHOT = 'sd_cancel_policy_snapshot';

  // ---------------------------------------------------------------------------
  // Ride completion metrics
  // ---------------------------------------------------------------------------
  public const TRIP_MILES       = 'sd_trip_miles';
  public const TRIP_MINUTES     = 'sd_trip_minutes';
  public const TOTAL_MILES      = 'sd_total_miles';
  public const TOTAL_MINUTES    = 'sd_total_minutes';
  public const TOTAL_FARE_CENTS = 'sd_total_fare_cents';
  public const TOTAL_CURRENCY   = 'sd_total_currency';

  // ---------------------------------------------------------------------------
  // Routing primitives
  // ---------------------------------------------------------------------------
  public const ROUTE_METERS  = 'sd_route_meters';
  public const ROUTE_SECONDS = 'sd_route_seconds';

  // ---------------------------------------------------------------------------
  // Payment / Stripe public identity
  // ---------------------------------------------------------------------------
  public const STRIPE_CUSTOMER_ID         = 'sd_stripe_customer_id';
  public const PAYMENT_METHOD_ID          = 'sd_payment_method_id';
  public const PAYMENT_STRATEGY           = 'sd_payment_strategy'; // IMMEDIATE_AUTHORIZE|SAVE_ONLY|AUTHORIZE_LATER
  public const PAYMENT_REQUIRED_BY_TS     = 'sd_payment_required_by_ts';
  public const SETUP_INTENT_ID            = 'sd_setup_intent_id';
  public const SETUP_INTENT_CLIENT_SECRET = 'sd_setup_intent_client_secret';
  public const PAYMENT_INTENT_ID          = 'sd_payment_intent_id';

  // ---------------------------------------------------------------------------
  // Time Block / availability records
  // ---------------------------------------------------------------------------
  public const BLOCK_TENANT_ID = 'sd_tenant_id';
  public const BLOCK_RIDE_ID   = 'sd_ride_id';
  public const BLOCK_TYPE      = 'sd_block_type';
  public const BLOCK_STATUS    = 'sd_block_status';
  public const BLOCK_START_TS  = 'sd_start_ts';
  public const BLOCK_END_TS    = 'sd_end_ts';

  public const TIMEBLOCK_START_TS     = 'sd_timeblock_start_ts';
  public const TIMEBLOCK_END_TS       = 'sd_timeblock_end_ts';
  public const TIMEBLOCK_CAPACITY     = 'sd_timeblock_capacity';
  public const TIMEBLOCK_SPENT        = 'sd_timeblock_spent';
  public const TIMEBLOCK_STATUS       = 'sd_timeblock_status'; // OPEN|HELD|COMMITTED|EXPIRED|UNAVAILABLE
  public const TIMEBLOCK_DRIVER_ID    = 'sd_timeblock_driver_id';
  public const TIMEBLOCK_LEAD_ID      = 'sd_timeblock_lead_id';
  public const TIMEBLOCK_RIDE_ID      = 'sd_timeblock_ride_id';
  public const TIMEBLOCK_HELD_AT      = 'sd_timeblock_held_at';
  public const TIMEBLOCK_COMMITTED_AT = 'sd_timeblock_committed_at';

  public const TIMEBLOCK_IDS_JSON     = 'sd_timeblock_ids_json';

  public const BLOCK_SOURCE           = 'sd_block_source';
  public const BLOCK_DRIVER_USER_ID   = 'sd_driver_user_id';
  public const BLOCK_PARENT_ID        = 'sd_parent_block_id';
  public const BLOCK_TIMEZONE         = 'sd_timezone';
  public const BLOCK_LABEL            = 'sd_block_label';
  public const BLOCK_NOTE             = 'sd_block_note';

  // ---------------------------------------------------------------------------
  // Private/system fields — intake/audit
  // ---------------------------------------------------------------------------
  public const P_FORM_SNAPSHOT       = '_sd_form_snapshot';
  public const P_FORM_SNAPSHOT_JSON  = '_sd_intake_payload_json';
  public const P_INTAKE_IDEM_KEY     = '_sd_intake_idem_key';
  public const P_TRIP_SURFACE_MODE   = '_sd_trip_surface_mode';
  public const P_AVAILABILITY_REASON = '_sd_availability_reason';

  // ---------------------------------------------------------------------------
  // Private/system fields — token/state audit
  // ---------------------------------------------------------------------------
  public const P_TOKEN_EXPIRES_AT = '_sd_token_expires_at';
  public const P_TOKEN_ROTATED_AT = '_sd_token_rotated_at';
  public const P_STATE_UPDATED_AT = '_sd_ride_state_ts';

  // ---------------------------------------------------------------------------
  // Private/system fields — routing compute
  // ---------------------------------------------------------------------------
  public const P_ROUTE_JOB_STATE   = '_sd_route_job_state'; // queued|running|ok|error
  public const P_ROUTE_POLYLINE    = '_sd_route_polyline';
  public const P_ROUTE_PROVIDER    = '_sd_route_provider';
  public const P_ROUTE_COMPUTED_AT = '_sd_route_computed_at';
  public const P_ROUTE_ERROR       = '_sd_route_error';

  // ---------------------------------------------------------------------------
  // Private/system fields — lead/quote build state
  // ---------------------------------------------------------------------------
  public const P_QUOTE_DRAFT_JSON        = '_sd_quote_draft_json';
  public const P_QUOTE_BUILT_AT          = '_sd_quote_built_at';
  public const P_QUOTE_BUILD_ERROR       = '_sd_quote_build_error';
  public const P_QUOTE_JOB_STATE         = '_sd_quote_job_state'; // queued|running|ok|error
  public const P_QUOTE_STATUS_UPDATED_AT = '_sd_quote_status_updated_at';
  public const P_QUOTE_PRESENTED_AT      = '_sd_quote_presented_at';
  public const P_QUOTE_APPROVED_AT       = '_sd_quote_approved_at';
  public const P_QUOTE_ACCEPTED_AT       = '_sd_quote_accepted_at';
  public const P_QUOTE_REJECTED_AT       = '_sd_quote_rejected_at';
  public const P_QUOTE_EXPIRES_AT        = '_sd_quote_expires_at';

  public const P_QUOTE_TENANT_DECISION      = '_sd_quote_tenant_decision';
  public const P_QUOTE_TENANT_DECISION_NOTE = '_sd_quote_tenant_decision_note';
  public const P_QUOTE_TENANT_DECISION_BY   = '_sd_quote_tenant_decision_by';

  public const P_QUOTE_LEAD_ACCEPTED_AT = '_sd_quote_lead_accepted_at';
  public const P_QUOTE_LEAD_REJECTED_AT = '_sd_quote_lead_rejected_at';
  public const P_QUOTE_USER_TIMEOUT_AT  = '_sd_quote_user_timeout_at';

  // ---------------------------------------------------------------------------
  // Private/system fields — attempts / Stripe correlation
  // ---------------------------------------------------------------------------
  public const P_ATTEMPT_STATUS        = '_sd_attempt_status';
  public const P_ATTEMPT_LEAD_ID       = '_sd_attempt_lead_id';
  public const P_ATTEMPT_QUOTE_ID      = '_sd_attempt_quote_id';
  public const P_ATTEMPT_RIDE_ID       = '_sd_attempt_ride_id';
  public const P_ATTEMPT_CREATED_AT    = '_sd_attempt_created_at';
  public const P_ATTEMPT_STATUS_TS     = '_sd_attempt_status_ts';
  public const P_ATTEMPT_AUTHORIZED_AT = '_sd_attempt_authorized_at';
  public const P_ATTEMPT_ERROR         = '_sd_attempt_error';

  public const P_STRIPE_SESSION_ID     = '_sd_stripe_session_id';
  public const P_STRIPE_PAYMENT_INTENT = '_sd_stripe_payment_intent';
  public const P_STRIPE_LAST_EVENT_ID  = '_sd_stripe_last_event_id';
  public const P_STRIPE_CAPTURED_AT    = '_sd_stripe_captured_at';
  public const P_STRIPE_CAPTURE_ERROR  = '_sd_stripe_capture_error';

  // ---------------------------------------------------------------------------
  // Private/system fields — scheduling conflict guards
  // ---------------------------------------------------------------------------
  public const P_BLOCK_CONFLICT    = '_sd_block_conflict';
  public const P_BLOCK_CONFLICT_AT = '_sd_block_conflict_at';

  // ---------------------------------------------------------------------------
// Locked / Accrued Revenue (ride-level, progressive accrual)
// ---------------------------------------------------------------------------
public const LOCKED_MILES         = 'sd_locked_miles';
public const LOCKED_MINUTES       = 'sd_locked_minutes';
public const LOCKED_FARE_CENTS    = 'sd_locked_fare_cents';
public const LOCKED_UPDATED_AT    = 'sd_locked_updated_at';// ---------------------------------------------------------------------------


}