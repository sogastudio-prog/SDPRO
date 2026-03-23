<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_RideRequestIntakeCF7 (v1.4)
 *
 * Purpose:
 * - Public ride-request intake via Contact Form 7.
 * - Requires pickup/dropoff Place IDs from Places Autocomplete.
 * - Captures BOTH place_id and lat/lng snapshots for pickup/dropoff.
 * - Creates sd_lead + trip token.
 * - Returns redirect_url in CF7 JSON to /trip/<token>/.
 *
 * Canon:
 * - Tenant MUST be resolved; never create unscoped records.
 * - Public meta uses sd_*; private uses _sd_*.
 * - Store logistics, not pricing.
 * - Address primitives should preserve place_id + lat/lng.
 */
final class SD_Module_RideRequestIntakeCF7 {

  private const DEFAULT_REQUEST_PAGE_SLUG = 'request-a-ride';

  public static function register() : void {
    add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_request_page_assets']);
    add_action('wpcf7_enqueue_scripts', [__CLASS__, 'enqueue_request_page_assets']);

    add_filter('wpcf7_form_hidden_fields', [__CLASS__, 'cf7_hidden_fields'], 20, 1);
    add_filter('wpcf7_form_tag', [__CLASS__, 'cf7_form_tag_default_values'], 20, 2);

    add_filter('wpcf7_validate', [__CLASS__, 'cf7_validate_ride_request'], 10, 2);
    add_action('wpcf7_before_send_mail', [__CLASS__, 'cf7_before_send_mail'], 10, 1);

    add_filter('wpcf7_special_mail_tags', [__CLASS__, 'cf7_special_mail_tags'], 10, 3);
    add_filter('wpcf7_feedback_response', [__CLASS__, 'cf7_add_redirect_to_feedback'], 20, 2);
  }

  // ---------------------------------------------------------------------------
  // Debug
  // ---------------------------------------------------------------------------

  private static function debug_enabled() : bool {
    return (defined('SD_INTAKE_DEBUG') && SD_INTAKE_DEBUG) || (defined('WP_DEBUG') && WP_DEBUG);
  }

  private static function dbg(string $event, array $ctx = []) : void {
    if (!self::debug_enabled()) return;
    if (!function_exists('error_log')) return;

    error_log('[solodrive] ' . wp_json_encode([
      'sd'    => true,
      'event' => $event,
      'ts'    => gmdate('c'),
      'ctx'   => $ctx,
    ]));
  }

  // ---------------------------------------------------------------------------
  // CF7 context helpers
  // ---------------------------------------------------------------------------

  private static function cf7_context(string $key, $default = null) {
    if (!isset($GLOBALS['sd_cf7_context']) || !is_array($GLOBALS['sd_cf7_context'])) {
      $GLOBALS['sd_cf7_context'] = [];
    }
    return array_key_exists($key, $GLOBALS['sd_cf7_context']) ? $GLOBALS['sd_cf7_context'][$key] : $default;
  }

  private static function cf7_set_context(string $key, $value) : void {
    if (!isset($GLOBALS['sd_cf7_context']) || !is_array($GLOBALS['sd_cf7_context'])) {
      $GLOBALS['sd_cf7_context'] = [];
    }
    $GLOBALS['sd_cf7_context'][$key] = $value;
  }

  private static function cf7_submission_posted() : array {
    if (!class_exists('WPCF7_Submission')) return [];
    $submission = \WPCF7_Submission::get_instance();
    if (!$submission) return [];
    $posted = $submission->get_posted_data();
    return is_array($posted) ? $posted : [];
  }

  private static function cf7_get(string $name, $default = '') {
    $posted = self::cf7_submission_posted();
    if (!$posted || !array_key_exists($name, $posted)) return $default;

    $val = $posted[$name];
    if (is_array($val)) $val = reset($val);
    $val = is_string($val) ? trim(wp_unslash($val)) : $val;

    return ($val === '' || $val === null) ? $default : $val;
  }

  private static function cf7_get_float(string $name, float $default = 0.0) : float {
    $val = self::cf7_get($name, '');
    if ($val === '' || !is_numeric($val)) return $default;
    return (float) $val;
  }

  // ---------------------------------------------------------------------------
  // Tenant resolution
  // ---------------------------------------------------------------------------

  private static function current_tenant_id() : int {

    $tid = class_exists('SD_Module_TenantResolver')
      ? (int) SD_Module_TenantResolver::current_tenant_id()
      : 0;

    if ($tid > 0) return $tid;

    $posted_tid = (int) self::cf7_get('sd_tenant_id', 0);
    if ($posted_tid > 0 && class_exists('SD_Module_TenantCPT')) {
      if (get_post_type($posted_tid) === SD_Module_TenantCPT::CPT) {
        return $posted_tid;
      }
    }

    if (class_exists('SD_Module_TenantCPT')) {
      $opt = (int) get_option(SD_Module_TenantCPT::OPT_CURRENT_TENANT_ID, 0);
      if ($opt > 0 && get_post_type($opt) === SD_Module_TenantCPT::CPT) {
        return $opt;
      }
    }

    return 0;
  }

  private static function request_page_slug() : string {
    $tenant_id = self::current_tenant_id();
    $slug = (string) apply_filters('sd_intake_request_page_slug', self::DEFAULT_REQUEST_PAGE_SLUG, $tenant_id);
    $slug = sanitize_title($slug);
    return $slug !== '' ? $slug : self::DEFAULT_REQUEST_PAGE_SLUG;
  }

  private static function tenant_base_location_cfg(int $tenant_id) : array {
    if ($tenant_id <= 0) return ['lat' => null, 'lng' => null, 'radius_m' => null];

    $lat = trim((string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LAT, true));
    $lng = trim((string) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_LNG, true));
    $rad = (int) get_post_meta($tenant_id, SD_Meta::BASE_LOCATION_RADIUS_M, true);

    if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) {
      return ['lat' => null, 'lng' => null, 'radius_m' => null];
    }

    if ($rad <= 0) $rad = 40000;

    return [
      'lat'      => (float) $lat,
      'lng'      => (float) $lng,
      'radius_m' => (int) $rad,
    ];
  }

  // ---------------------------------------------------------------------------
  // CF7 form detection
  // ---------------------------------------------------------------------------

  private static function cf7_form_id() : int {
    if (defined('SD_CF7_FORM_ID_RIDE_REQUEST')) {
      $v = (int) SD_CF7_FORM_ID_RIDE_REQUEST;
      return $v > 0 ? $v : 0;
    }
    return 0;
  }

  private static function cf7_is_ride_form($contact_form) : bool {

    $id_cfg = self::cf7_form_id();
    if ($id_cfg > 0 && $contact_form && is_object($contact_form) && method_exists($contact_form, 'id')) {
      $id_actual = (int) $contact_form->id();
      if ($id_actual > 0 && $id_actual === $id_cfg) return true;
    }

    $need = ['pickup_address','dropoff_address','pickup_place_id','dropoff_place_id','customer_phone'];

    if ($contact_form && is_object($contact_form) && method_exists($contact_form, 'prop')) {
      $tpl = (string) $contact_form->prop('form');
      if ($tpl !== '') {
        $hits = 0;
        foreach ($need as $k) {
          if (stripos($tpl, $k) !== false) $hits++;
        }
        if ($hits >= 4) return true;
      }
    }

    $posted = self::cf7_submission_posted();
    if ($posted) {
      foreach ($need as $k) {
        if (!array_key_exists($k, $posted)) return false;
      }
      return true;
    }

    return false;
  }

  // ---------------------------------------------------------------------------
  // CF7 validation
  // ---------------------------------------------------------------------------

  public static function cf7_validate_ride_request($result, $tags) {
    if (!class_exists('WPCF7_ContactForm')) return $result;

    $contact_form = \WPCF7_ContactForm::get_current();
    if (!self::cf7_is_ride_form($contact_form)) return $result;

    $tenant_id = self::current_tenant_id();
    if ($tenant_id <= 0) {
      $result->invalidate('_wpcf7', 'This service is not configured yet. Please try again later.');
      self::dbg('intake_block_no_tenant', []);
      return $result;
    }

    $pickup_place_id  = (string) self::cf7_get('pickup_place_id', '');
    $dropoff_place_id = (string) self::cf7_get('dropoff_place_id', '');

    if ($pickup_place_id === '') {
      $result->invalidate('pickup_address', 'Please choose a pickup location from the suggestions list.');
    }
    if ($dropoff_place_id === '') {
      $result->invalidate('dropoff_address', 'Please choose a destination from the suggestions list.');
    }

    $customer_phone = (string) self::cf7_get('customer_phone', '');
    if ($customer_phone === '' || !preg_match('/\d{7,}/', $customer_phone)) {
      $result->invalidate('customer_phone', 'Please enter a valid mobile phone number.');
    }

    $request_mode = strtoupper((string) self::cf7_get('sd_request_mode', self::cf7_get('request_mode', 'ASAP')));
    if (!in_array($request_mode, ['ASAP', 'RESERVE', 'RESERVATION'], true)) {
      $result->invalidate('_wpcf7', 'Invalid request mode.');
    }

    if (in_array($request_mode, ['RESERVE', 'RESERVATION'], true)) {
      $reserve_date = (string) self::cf7_get('reserve_date', '');
      $reserve_time = (string) self::cf7_get('reserve_time', '');
      if ($reserve_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reserve_date)) {
        $result->invalidate('reserve_date', 'Please choose a valid reservation date.');
      }
      if ($reserve_time === '' || !preg_match('/^\d{2}:\d{2}$/', $reserve_time)) {
        $result->invalidate('reserve_time', 'Please choose a valid reservation time.');
      }
    }

    if ($pickup_place_id !== '' && $dropoff_place_id !== '' && $pickup_place_id === $dropoff_place_id) {
      $result->invalidate('dropoff_address', 'Destination must be different than pickup.');
    }

    return $result;
  }

  // ---------------------------------------------------------------------------
  // Create lead + token
  // ---------------------------------------------------------------------------

  public static function cf7_before_send_mail($contact_form) : void {
    if (!class_exists('WPCF7_Submission')) return;
    if (!self::cf7_is_ride_form($contact_form)) return;

    $tenant_id = self::current_tenant_id();
    if ($tenant_id <= 0) {
      self::dbg('intake_create_block_no_tenant', []);
      return;
    }

    $submission = \WPCF7_Submission::get_instance();
    if (!$submission) {
      self::dbg('intake_create_no_submission', []);
      return;
    }

    self::dbg('intake_create_enter', [
      'is_ride_form'      => true,
      'tenant_id'         => $tenant_id,
      'posted_keys'       => array_keys((array) $submission->get_posted_data()),
      'pickup_place_id'   => (string) self::cf7_get('pickup_place_id', ''),
      'dropoff_place_id'  => (string) self::cf7_get('dropoff_place_id', ''),
      'pickup_lat'        => (string) self::cf7_get('pickup_lat', ''),
      'pickup_lng'        => (string) self::cf7_get('pickup_lng', ''),
      'dropoff_lat'       => (string) self::cf7_get('dropoff_lat', ''),
      'dropoff_lng'       => (string) self::cf7_get('dropoff_lng', ''),
    ]);

    $created = self::cf7_create_lead_record($submission, $tenant_id);

    if (!$created['ok']) {
      self::cf7_set_context('sd_trip_token', '');
      self::cf7_set_context('sd_trip_url', '');
      self::cf7_set_context('lead_id', 0);

      self::dbg('intake_create_failed', [
        'tenant_id' => $tenant_id,
        'error'     => (string) ($created['error'] ?? 'unknown'),
        'lead_id'   => (int) ($created['lead_id'] ?? 0),
      ]);

      return;
    }

    self::cf7_set_context('sd_trip_token', (string) $created['token']);
    self::cf7_set_context('sd_trip_url', (string) $created['trip_url']);
    self::cf7_set_context('lead_id', (int) $created['lead_id']);

    self::dbg('intake_create_ok', [
      'tenant_id' => $tenant_id,
      'lead_id'   => (int) $created['lead_id'],
      'trip_url'  => (string) $created['trip_url'],
    ]);
  }

  private static function cf7_create_lead_record($submission, int $tenant_id) : array {
    $posted = $submission->get_posted_data();
    if (!is_array($posted)) {
      return ['ok'=>false,'lead_id'=>0,'token'=>'','trip_url'=>'','error'=>'No submission payload.'];
    }

    if (!class_exists('SD_Module_LeadService')) {
      return ['ok'=>false,'lead_id'=>0,'token'=>'','trip_url'=>'','error'=>'Lead service unavailable.'];
    }

    return SD_Module_LeadService::create_from_intake($posted, $tenant_id);
  }

  // ---------------------------------------------------------------------------
  // Mail tags
  // ---------------------------------------------------------------------------

  public static function cf7_special_mail_tags($output, $name, $html) {
    $name = trim((string) $name);

    if ($name === 'sd_trip_token') {
      $token = (string) self::cf7_context('sd_trip_token', '');
      return $token !== '' ? $token : $output;
    }

    if ($name === 'sd_trip_url') {
      $url = (string) self::cf7_context('sd_trip_url', '');
      return $url !== '' ? esc_url_raw($url) : $output;
    }

    return $output;
  }

  // ---------------------------------------------------------------------------
  // Redirect injection
  // ---------------------------------------------------------------------------

  public static function cf7_add_redirect_to_feedback($response, $result) {
    $contact_form = null;

    if (is_object($result)) {
      if (property_exists($result, 'contact_form')) {
        $contact_form = $result->contact_form;
      } elseif ($result instanceof \WPCF7_ContactForm) {
        $contact_form = $result;
      }
    }

    if (!$contact_form && class_exists('WPCF7_ContactForm')) {
      $contact_form = \WPCF7_ContactForm::get_current();
    }

    if (!self::cf7_is_ride_form($contact_form)) return $response;

    $url   = (string) self::cf7_context('sd_trip_url', '');
    $token = (string) self::cf7_context('sd_trip_token', '');

    if (!is_array($response)) $response = [];

    if ($url !== '') $response['redirect_url'] = $url;
    if ($token !== '') $response['sd_trip_token'] = $token;

    $lead_id = (int) self::cf7_context('lead_id', 0);
    if ($lead_id > 0) $response['lead_id'] = $lead_id;

    return $response;
  }

  // ---------------------------------------------------------------------------
  // Hidden fields + default tag values
  // ---------------------------------------------------------------------------

  public static function cf7_hidden_fields(array $fields) : array {
    $tenant_id = self::current_tenant_id();

    if (class_exists('WPCF7_ContactForm')) {
      $contact_form = \WPCF7_ContactForm::get_current();
      if (!self::cf7_is_ride_form($contact_form)) {
        self::dbg('intake_hidden_fields', ['tenant_id' => $tenant_id ?: null, 'is_ride_form' => false]);
        return $fields;
      }
    }

    if ($tenant_id > 0) {
      $fields['sd_tenant_id'] = (string) $tenant_id;
      self::dbg('intake_hidden_fields_injected', ['sd_tenant_id' => (string) $tenant_id]);
    } else {
      self::dbg('intake_hidden_fields_no_tenant', []);
    }

    return $fields;
  }

  public static function cf7_form_tag_default_values($tag, $unused) {
    if (!is_object($tag) || empty($tag->name)) return $tag;

    if (!class_exists('WPCF7_ContactForm')) return $tag;
    $contact_form = \WPCF7_ContactForm::get_current();
    if (!self::cf7_is_ride_form($contact_form)) return $tag;

    if ($tag->name !== 'sd_tenant_id') return $tag;
    if (property_exists($tag, 'basetype') && $tag->basetype !== 'hidden') return $tag;

    $tenant_id = self::current_tenant_id();
    if ($tenant_id > 0) {
      $tag->values = [(string) $tenant_id];
      $tag->raw_values = [(string) $tenant_id];
    }

    return $tag;
  }

  // ---------------------------------------------------------------------------
  // Assets
  // ---------------------------------------------------------------------------

  public static function enqueue_request_page_assets() : void {

    $force = function_exists('doing_action') && doing_action('wpcf7_enqueue_scripts');

    if (!$force) {
      global $post;
      if (!($post instanceof \WP_Post)) {
        $q = get_queried_object();
        if ($q instanceof \WP_Post) $post = $q;
      }
      if (!self::should_load_assets($post)) return;
    }

    $handle = self::enqueue_base_handle();

    $tenant_id = self::current_tenant_id();
    if ($tenant_id > 0) {
      wp_add_inline_script($handle, "(function(){
        var TID = " . (int) $tenant_id . ";
        function setTid(){
          var el = document.querySelector('input[name=\"sd_tenant_id\"]');
          if (el) el.value = String(TID);
        }
        document.addEventListener('DOMContentLoaded', setTid);
        document.addEventListener('wpcf7beforesubmit', setTid);
        setTid();
      })();", 'after');
    }

    wp_add_inline_script($handle, self::cf7_redirect_inline_js(), 'after');
    wp_add_inline_script($handle, self::places_bind_inline_js(), 'after');

    wp_add_inline_style(self::enqueue_style_handle(), ".pac-container{z-index:999999!important;}");
  }

  private static function should_load_assets($post) : bool {

    $slug = self::request_page_slug();
    if (is_page($slug)) return true;

    if ($post instanceof \WP_Post) {
      $content = (string) $post->post_content;

      if (function_exists('has_shortcode') && has_shortcode($content, 'contact-form-7')) return true;
      if (stripos($content, '[contact-form-7') !== false) return true;
      if (stripos($content, 'contact-form-7') !== false) return true;
    }

    return false;
  }

  private static function enqueue_base_handle() : string {
    $handle = 'sd-cf7-ride-intake';
    if (!wp_script_is($handle, 'registered')) {
      wp_register_script($handle, '', [], '1.1.0', true);
    }
    wp_enqueue_script($handle);
    return $handle;
  }

  private static function enqueue_style_handle() : string {
    $handle = 'sd-cf7-ride-intake-css';
    if (!wp_style_is($handle, 'registered')) {
      wp_register_style($handle, false, [], '1.1.0');
    }
    wp_enqueue_style($handle);
    return $handle;
  }

  private static function cf7_redirect_inline_js() : string {
    return <<<JS
(function(){
  function getRedirectUrl(detail){
    if (!detail) return '';
    var r = detail.apiResponse || detail.response || detail;
    if (!r) return '';
    if (r.redirect_url) return r.redirect_url;
    if (r.data && r.data.redirect_url) return r.data.redirect_url;
    if (r.payload && r.payload.redirect_url) return r.payload.redirect_url;
    return '';
  }

  function go(url){
    if (!url) return;
    try { window.location.assign(url); } catch(e){ window.location.href = url; }
  }

  document.addEventListener('wpcf7mailsent', function(e){
    var url = getRedirectUrl(e && e.detail);
    if (url) go(url);
  }, false);

  document.addEventListener('wpcf7submit', function(e){
    if (!e || !e.detail) return;
    if (e.detail.status && e.detail.status !== 'mail_sent' && e.detail.status !== 'sent') return;
    var url = getRedirectUrl(e.detail);
    if (url) go(url);
  }, false);
})();
JS;
  }

  private static function places_bind_inline_js() : string {
    $tenant_id = self::current_tenant_id();
    $base = self::tenant_base_location_cfg($tenant_id);

    $cfg = [
      'country' => apply_filters('sd_intake_default_country', 'us', $tenant_id),
      'base' => [
        'lat'      => $base['lat'],
        'lng'      => $base['lng'],
        'radius_m' => $base['radius_m'],
      ],
    ];

    $json = wp_json_encode($cfg);

    return <<<JS
(function(){
  function wire(){
    var cfg = $json || {};

    if (!window.SD_Places || !SD_Places.bind) return;

    SD_Places.bind({
      input: 'input[name="pickup_address"], #pickup_address',
      placeId: 'input[name="pickup_place_id"]',
      lat: 'input[name="pickup_lat"]',
      lng: 'input[name="pickup_lng"]',
      country: cfg.country,
      base: cfg.base
    });

    SD_Places.bind({
      input: 'input[name="dropoff_address"], #dropoff_address',
      placeId: 'input[name="dropoff_place_id"]',
      lat: 'input[name="dropoff_lat"]',
      lng: 'input[name="dropoff_lng"]',
      country: cfg.country,
      base: cfg.base
    });
  }

  function boot(){
    if (window.SD_Places && SD_Places.bind) {
      wire();
      return;
    }

    var tries = 0;
    var t = setInterval(function(){
      tries++;
      if (window.SD_Places && SD_Places.bind) {
        clearInterval(t);
        wire();
      }
      if (tries > 60) clearInterval(t);
    }, 250);
  }

  boot();
  document.addEventListener('wpcf7init', wire);
  document.addEventListener('wpcf7reset', wire);
  document.addEventListener('wpcf7invalid', wire);
  document.addEventListener('wpcf7mailsent', wire);
})();
JS;
  }
}

SD_Module_RideRequestIntakeCF7::register();