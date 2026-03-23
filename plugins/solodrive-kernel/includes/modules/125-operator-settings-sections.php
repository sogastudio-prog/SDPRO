<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorSettingsSections
 *
 * Front-end tenant/operator settings section renderer + saver.
 *
 * Purpose:
 * - Render live operator settings sections:
 *   - storefront
 *   - pricing
 *   - base_location
 *   - profile
 * - Save section payloads through SD_TenantSettingsSaver
 *
 * Notes:
 * - Intended for use by SD_Module_OperatorSettingsShell
 * - Uses admin-post for saves from front-end
 */
if (class_exists('SD_Module_OperatorSettingsSections', false)) { return; }

final class SD_Module_OperatorSettingsSections {

  private const ACTION_SAVE = 'sd_operator_save_settings_section';

  public static function register() : void {
    add_action('admin_post_' . self::ACTION_SAVE, [__CLASS__, 'handle_save']);
  }

  public static function render_section(int $tenant_id, string $section) : string {
    if (!in_array($section, ['storefront', 'pricing', 'base_location', 'profile'], true)) {
      return self::notice('Unknown settings section.');
    }

    $values = class_exists('SD_TenantConfig', false)
      ? SD_TenantConfig::form_defaults($tenant_id, $section)
      : [];

    $status = self::status();
    $errors = self::errors();
    $old    = self::old_values();

    if (!empty($old)) {
      $values = array_merge($values, $old);
    }

    ob_start();

    echo '<div class="sd-operator-section-card">';
      echo '<div class="sd-operator-section-top">';
        echo '<div>';
          echo '<h2 class="sd-operator-section-title">' . esc_html(self::section_title($section)) . '</h2>';
          echo '<div class="sd-operator-section-desc">' . esc_html(self::section_desc($section)) . '</div>';
        echo '</div>';
        echo '<a class="sd-operator-back" href="' . esc_url(home_url('/operator/')) . '">Back</a>';
      echo '</div>';

      self::render_section_readiness($tenant_id, $section);

      if ($status === 'updated') {
        echo '<div class="sd-operator-notice sd-operator-notice--success">Settings saved.</div>';
      } elseif ($status === 'error') {
        echo '<div class="sd-operator-notice sd-operator-notice--error">Settings could not be saved. Please correct the highlighted fields.</div>';
      }

      echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_SAVE) . '">';
        echo '<input type="hidden" name="tenant_id" value="' . esc_attr((string) $tenant_id) . '">';
        echo '<input type="hidden" name="section" value="' . esc_attr($section) . '">';
        wp_nonce_field('sd_operator_save_section_' . $section, '_sd_nonce');

        if ($section === 'storefront') {
          self::render_storefront_fields($values, $errors);
        } elseif ($section === 'pricing') {
          self::render_pricing_fields($values, $errors);
        } elseif ($section === 'base_location') {
          self::render_base_location_fields($values, $errors);
        } elseif ($section === 'profile') {
          self::render_profile_fields($values, $errors);
        }

        echo '<div class="sd-operator-actions-row">';
          echo '<button type="submit" class="sd-operator-btn">Save ' . esc_html(self::section_title($section)) . '</button>';
          echo '<a class="sd-operator-btn sd-operator-btn--ghost" href="' . esc_url(home_url('/operator/')) . '">Cancel</a>';
        echo '</div>';
      echo '</form>';
    echo '</div>';

    return (string) ob_get_clean();
  }

  public static function handle_save() : void {
    if (!is_user_logged_in()) {
      wp_die('Login required.');
    }

    $user_id   = get_current_user_id();
    $tenant_id = isset($_POST['tenant_id']) ? absint($_POST['tenant_id']) : 0;
    $section   = isset($_POST['section']) ? sanitize_key((string) $_POST['section']) : '';

    if (!in_array($section, ['storefront', 'pricing', 'base_location', 'profile'], true)) {
      self::redirect($section, 'error', ['_section' => ['Unknown settings section.']], [], $tenant_id);
    }

    check_admin_referer('sd_operator_save_section_' . $section, '_sd_nonce');

    $current_tenant = self::current_tenant_id_for_user($user_id);
    if ($tenant_id <= 0 || $current_tenant <= 0 || $tenant_id !== $current_tenant) {
      self::redirect($section, 'error', ['_section' => ['You do not have access to that tenant.']], [], $current_tenant);
    }

    $input = SD_TenantSettingsSaver::section_input_from_request($section, $_POST);

    if ($section === 'storefront') {
      $extra = self::validate_storefront_uniques($tenant_id, $input);
      if (!empty($extra)) {
        self::redirect($section, 'error', $extra, $input, $tenant_id);
      }
    }

    $result = SD_TenantSettingsSaver::save_section($tenant_id, $section, $input);

    if (!empty($result['ok'])) {
      self::redirect($section, 'updated', [], $result['normalized'], $tenant_id);
    }

    self::redirect($section, 'error', (array) ($result['errors'] ?? []), $input, $tenant_id);
  }

  private static function render_storefront_fields(array $v, array $errors) : void {
    $schema = SD_TenantSettingsSchema::section_fields('storefront');

    echo '<div class="sd-operator-form-grid">';
      self::text(SD_Meta::TENANT_SLUG, 'Tenant Slug', $v, $errors, 'Unique storefront handle.');
      self::text(SD_Meta::TENANT_DOMAIN, 'Tenant Domain', $v, $errors, 'Optional custom domain. Host only.');
      self::select(SD_Meta::STOREFRONT_STATE, 'Storefront State', $v, $errors, self::enum_options($schema, SD_Meta::STOREFRONT_STATE));
      self::select(SD_Meta::STOREFRONT_REQUEST_MODE, 'Request Mode', $v, $errors, self::enum_options($schema, SD_Meta::STOREFRONT_REQUEST_MODE));
      self::check(SD_Meta::STOREFRONT_ENABLED, 'Storefront Enabled', $v, $errors, 'Master storefront switch.');
      self::check(SD_Meta::STOREFRONT_ACCEPTING_REQUESTS, 'Accepting Requests', $v, $errors, 'Allow rider requests now.');
      self::check(SD_Meta::STOREFRONT_REQUIRES_QUOTE, 'Requires Quote', $v, $errors, 'Force quote-first flow.');
      self::check(SD_Meta::STOREFRONT_ALLOWS_IMMEDIATE_BOOKING, 'Allows Immediate Booking', $v, $errors, 'Enable only when booking path is intended.');
      self::select(SD_Meta::STOREFRONT_HOURS_MODE, 'Hours Mode', $v, $errors, self::enum_options($schema, SD_Meta::STOREFRONT_HOURS_MODE));
      self::select(SD_Meta::STOREFRONT_TIMEZONE, 'Storefront Timezone', $v, $errors, self::us_timezone_options(), 'Required when Hours Mode is scheduled.');
      self::textarea(SD_Meta::STOREFRONT_BUSY_MESSAGE, 'Busy Message', $v, $errors, 'Shown when storefront state is busy.');
      self::textarea(SD_Meta::STOREFRONT_CLOSURE_MESSAGE, 'Closure Message', $v, $errors, 'Shown when storefront state is closed.');
    echo '</div>';
  }

  private static function render_pricing_fields(array $v, array $errors) : void {
    $schema = SD_TenantSettingsSchema::section_fields('pricing');

    echo '<div class="sd-operator-form-grid">';
      self::select(SD_Meta::QUOTE_MODE, 'Quote Mode', $v, $errors, self::enum_options($schema, SD_Meta::QUOTE_MODE));
      self::select(SD_Meta::PRICING_MODEL, 'Pricing Model', $v, $errors, self::enum_options($schema, SD_Meta::PRICING_MODEL));
      self::text(SD_Meta::CURRENCY, 'Currency', $v, $errors, 'Example: USD');
      self::text(SD_Meta::BASE_FARE, 'Base Fare', $v, $errors, 'Example: 8.50');
      self::text(SD_Meta::MINIMUM_FARE, 'Minimum Fare', $v, $errors, 'Example: 12.00');
      self::text(SD_Meta::PER_MILE_RATE, 'Per Mile Rate', $v, $errors, 'Example: 2.35');
      self::text(SD_Meta::PER_MINUTE_RATE, 'Per Minute Rate', $v, $errors, 'Example: 0.65');
      self::text(SD_Meta::WAIT_TIME_PER_MINUTE, 'Wait Time Per Minute', $v, $errors, 'Example: 0.50');
      self::check(SD_Meta::DEADHEAD_ENABLED, 'Deadhead Enabled', $v, $errors, 'Include deadhead in quote logic.');
      self::text(SD_Meta::DEADHEAD_PER_MILE, 'Deadhead Per Mile', $v, $errors, 'Required when deadhead is enabled.');
      self::text(SD_Meta::SERVICE_FEE, 'Service Fee', $v, $errors, 'Flat fee added to quote.');
      self::number(SD_Meta::QUOTE_EXPIRY_MINUTES, 'Quote Expiry Minutes', $v, $errors, 'How long a quote remains actionable.');
      self::number(SD_Meta::LEAD_EXPIRY_MINUTES, 'Lead Expiry Minutes', $v, $errors, 'How long a lead remains active.');
      self::check(self::manual_review_meta_key(), 'Requires Manual Review', $v, $errors, 'Human review before presentation.');
      self::select(SD_Meta::AFTER_HOURS_SURCHARGE_TYPE, 'After Hours Surcharge Type', $v, $errors, self::enum_options($schema, SD_Meta::AFTER_HOURS_SURCHARGE_TYPE));
      self::text(SD_Meta::AFTER_HOURS_SURCHARGE_VALUE, 'After Hours Surcharge Value', $v, $errors, 'Required when surcharge type is flat or percent.');
    echo '</div>';
  }

  private static function render_base_location_fields(array $v, array $errors) : void {
    $schema = SD_TenantSettingsSchema::section_fields('base_location');

    echo '<div class="sd-operator-form-grid">';
      self::text(SD_Meta::BASE_LOCATION_LABEL, 'Base Location Label', $v, $errors, 'Human-readable service-area anchor.');
      self::text(SD_Meta::BASE_LOCATION_PLACE_ID, 'Base Location Place ID', $v, $errors, 'Optional Google Place ID.');
      self::text(SD_Meta::BASE_LOCATION_LAT, 'Base Latitude', $v, $errors, 'Example: 30.864390');
      self::text(SD_Meta::BASE_LOCATION_LNG, 'Base Longitude', $v, $errors, 'Example: -83.273986');
      self::number(SD_Meta::BASE_LOCATION_RADIUS_M, 'Base Radius (m)', $v, $errors, 'Example: 40000');
      self::select(SD_Meta::SERVICE_RADIUS_MODE, 'Service Radius Mode', $v, $errors, self::enum_options($schema, SD_Meta::SERVICE_RADIUS_MODE));
      self::number(SD_Meta::PICKUP_RADIUS_M, 'Pickup Radius (m)', $v, $errors, 'Required for pickup_only and flexible modes.');
      self::number(SD_Meta::DROPOFF_RADIUS_M, 'Dropoff Radius (m)', $v, $errors, 'Required for flexible mode.');
      self::select(SD_Meta::OUT_OF_AREA_POLICY, 'Out of Area Policy', $v, $errors, self::enum_options($schema, SD_Meta::OUT_OF_AREA_POLICY));
    echo '</div>';
  }

  private static function render_profile_fields(array $v, array $errors) : void {
    echo '<div class="sd-operator-form-grid">';
      self::text(SD_Meta::PROFILE_BUSINESS_NAME, 'Profile Business Name', $v, $errors, 'Displayed to riders and customers. Required for readiness.');
      self::text(SD_Meta::PROFILE_SUPPORT_PHONE, 'Support Phone', $v, $errors, 'Shown to riders when support contact is needed.');
      self::text(SD_Meta::PROFILE_SUPPORT_EMAIL, 'Support Email', $v, $errors, 'Shown to riders when support contact is needed.');
      self::textarea(SD_Meta::PROFILE_DESCRIPTION, 'Business Description', $v, $errors, 'Short rider-facing description of your service.');
    echo '</div>';
  }

  private static function render_section_readiness(int $tenant_id, string $section) : void {
    if (!class_exists('SD_TenantReadiness', false)) {
      return;
    }

    $grouped = SD_TenantReadiness::missing_by_section($tenant_id);
    $items   = isset($grouped[$section]) && is_array($grouped[$section]) ? $grouped[$section] : [];

    if (empty($items)) {
      return;
    }

    echo '<div class="sd-operator-notice sd-operator-notice--error">';
      echo '<strong>Still missing in this section:</strong>';
      echo '<ul style="margin:10px 0 0 18px;">';
      foreach ($items as $item) {
        $label  = isset($item['label']) ? (string) $item['label'] : 'Missing setting';
        $reason = isset($item['reason']) ? (string) $item['reason'] : 'Required value is missing.';
        echo '<li><strong>' . esc_html($label) . '.</strong> ' . esc_html($reason) . '</li>';
      }
      echo '</ul>';
    echo '</div>';
  }

  private static function text(string $key, string $label, array $v, array $errors, string $help = '') : void {
    echo '<div class="sd-operator-field">';
      echo '<label class="sd-operator-label" for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
      echo '<input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr((string) ($v[$key] ?? '')) . '">';
      self::help_and_errors($help, $errors[$key] ?? []);
    echo '</div>';
  }

  private static function number(string $key, string $label, array $v, array $errors, string $help = '') : void {
    echo '<div class="sd-operator-field">';
      echo '<label class="sd-operator-label" for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
      echo '<input type="number" step="1" min="0" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr((string) ($v[$key] ?? '')) . '">';
      self::help_and_errors($help, $errors[$key] ?? []);
    echo '</div>';
  }

  private static function textarea(string $key, string $label, array $v, array $errors, string $help = '') : void {
    echo '<div class="sd-operator-field sd-operator-field--full">';
      echo '<label class="sd-operator-label" for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
      echo '<textarea id="' . esc_attr($key) . '" name="' . esc_attr($key) . '">' . esc_textarea((string) ($v[$key] ?? '')) . '</textarea>';
      self::help_and_errors($help, $errors[$key] ?? []);
    echo '</div>';
  }

  private static function select(string $key, string $label, array $v, array $errors, array $options, string $help = '') : void {
    $value = (string) ($v[$key] ?? '');

    echo '<div class="sd-operator-field">';
      echo '<label class="sd-operator-label" for="' . esc_attr($key) . '">' . esc_html($label) . '</label>';
      echo '<select id="' . esc_attr($key) . '" name="' . esc_attr($key) . '">';
      foreach ($options as $option_value => $option_label) {
        echo '<option value="' . esc_attr($option_value) . '" ' . selected($value, (string) $option_value, false) . '>' . esc_html($option_label) . '</option>';
      }
      echo '</select>';
      self::help_and_errors($help, $errors[$key] ?? []);
    echo '</div>';
  }

  private static function check(string $key, string $label, array $v, array $errors, string $help = '') : void {
    if ($key === '') {
      return;
    }

    $checked = ((string) ($v[$key] ?? '0') === '1');

    echo '<div class="sd-operator-field">';
      echo '<span class="sd-operator-label">' . esc_html($label) . '</span>';
      echo '<input type="hidden" name="' . esc_attr($key) . '" value="0">';
      echo '<label class="sd-operator-check"><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($checked, true, false) . '> <span>' . esc_html($label) . '</span></label>';
      self::help_and_errors($help, $errors[$key] ?? []);
    echo '</div>';
  }

  private static function help_and_errors(string $help, array $messages) : void {
    if ($help !== '') {
      echo '<div class="sd-operator-help">' . esc_html($help) . '</div>';
    }
    foreach ((array) $messages as $message) {
      echo '<div class="sd-operator-error">' . esc_html((string) $message) . '</div>';
    }
  }

  private static function section_title(string $section) : string {
    $map = [
      'storefront'    => 'Storefront Config',
      'pricing'       => 'Pricing Config',
      'base_location' => 'Base Location',
      'profile'       => 'Tenant Profile',
    ];
    return $map[$section] ?? $section;
  }

  private static function section_desc(string $section) : string {
    $map = [
      'storefront'    => 'Controls public storefront behavior, request gating, and rider-facing storefront mode.',
      'pricing'       => 'Controls quote policy, pricing model, rates, expirations, and surcharge behavior.',
      'base_location' => 'Controls service-area anchor, radius policy, and out-of-area handling for storefront + quote flow.',
      'profile'       => 'Controls rider-facing business identity and support contact details.',
    ];
    return $map[$section] ?? '';
  }

  private static function enum_options(array $schema, string $key) : array {
    $field = $schema[$key] ?? [];
    $out   = [];

    foreach ((array) ($field['enum'] ?? []) as $value) {
      $out[(string) $value] = ucwords(str_replace('_', ' ', (string) $value));
    }

    return $out;
  }

  private static function us_timezone_options() : array {
    return [
      ''                    => 'Select timezone',
      'America/New_York'    => 'Eastern Time (America/New_York)',
      'America/Chicago'     => 'Central Time (America/Chicago)',
      'America/Denver'      => 'Mountain Time (America/Denver)',
      'America/Phoenix'     => 'Arizona Time (America/Phoenix)',
      'America/Los_Angeles' => 'Pacific Time (America/Los_Angeles)',
      'America/Anchorage'   => 'Alaska Time (America/Anchorage)',
      'Pacific/Honolulu'    => 'Hawaii Time (Pacific/Honolulu)',
      'America/Indiana/Indianapolis' => 'Indiana East (America/Indiana/Indianapolis)',
    ];
  }

  private static function validate_storefront_uniques(int $tenant_id, array $input) : array {
    $errors = [];

    if (isset($input[SD_Meta::TENANT_SLUG])) {
      $slug = sanitize_title((string) $input[SD_Meta::TENANT_SLUG]);
      if ($slug !== '' && self::slug_in_use_by_other_tenant($tenant_id, $slug)) {
        $errors[SD_Meta::TENANT_SLUG][] = 'That tenant slug is already in use.';
      }
    }

    if (isset($input[SD_Meta::TENANT_DOMAIN])) {
      $domain = self::normalize_domain((string) $input[SD_Meta::TENANT_DOMAIN]);
      if ($domain !== '' && self::domain_in_use_by_other_tenant($tenant_id, $domain)) {
        $errors[SD_Meta::TENANT_DOMAIN][] = 'That tenant domain is already in use.';
      }
    }

    return $errors;
  }

  private static function slug_in_use_by_other_tenant(int $tenant_id, string $slug) : bool {
    $q = new \WP_Query([
      'post_type'      => 'sd_tenant',
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'post__not_in'   => [$tenant_id],
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'   => SD_Meta::TENANT_SLUG,
        'value' => $slug,
      ]],
    ]);

    return !empty($q->posts);
  }

  private static function domain_in_use_by_other_tenant(int $tenant_id, string $domain) : bool {
    $q = new \WP_Query([
      'post_type'      => 'sd_tenant',
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'post__not_in'   => [$tenant_id],
      'fields'         => 'ids',
      'meta_query'     => [[
        'key'   => SD_Meta::TENANT_DOMAIN,
        'value' => $domain,
      ]],
    ]);

    return !empty($q->posts);
  }

  private static function normalize_domain(string $value) : string {
    $value = strtolower(trim($value));
    $value = preg_replace('#^https?://#', '', $value);
    $value = preg_replace('#/.*$#', '', $value);
    return sanitize_text_field($value);
  }

  private static function current_tenant_id_for_user(int $user_id) : int {
    if ($user_id <= 0) return 0;

    if (class_exists('SD_TenantAccess', false) && method_exists('SD_TenantAccess', 'get_current_user_tenant_id')) {
      $tenant_id = (int) SD_TenantAccess::get_current_user_tenant_id();
      if ($tenant_id > 0) return $tenant_id;
    }

    return (int) get_user_meta($user_id, SD_Meta::TENANT_ID, true);
  }

  private static function redirect(string $section, string $status, array $errors, array $values, int $tenant_id) : void {
    $args = [
      'section' => $section,
      'status'  => $status,
    ];

    if (!empty($errors)) {
      $args['sd_errors'] = rawurlencode(wp_json_encode($errors));
    }

    if (!empty($values)) {
      $args['sd_values'] = rawurlencode(wp_json_encode($values));
    }

    wp_safe_redirect(add_query_arg($args, home_url('/operator/')));
    exit;
  }

  private static function status() : string {
    return isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
  }

  private static function errors() : array {
    if (empty($_GET['sd_errors'])) return [];
    $decoded = json_decode(wp_unslash(rawurldecode((string) $_GET['sd_errors'])), true);
    return is_array($decoded) ? $decoded : [];
  }

  private static function old_values() : array {
    if (empty($_GET['sd_values'])) return [];
    $decoded = json_decode(wp_unslash(rawurldecode((string) $_GET['sd_values'])), true);
    return is_array($decoded) ? $decoded : [];
  }

  private static function notice(string $message) : string {
    return '<div class="sd-operator-section-card"><p>' . esc_html($message) . '</p></div>';
  }

  private static function manual_review_meta_key() : string {
    if (defined('SD_Meta::QUOTE_REQUIRES_MANUAL_REVIEW')) {
      $key = constant('SD_Meta::QUOTE_REQUIRES_MANUAL_REVIEW');
      return is_string($key) ? $key : '';
    }

    if (defined('SD_Meta::REQUIRES_MANUAL_REVIEW')) {
      $key = constant('SD_Meta::REQUIRES_MANUAL_REVIEW');
      return is_string($key) ? $key : '';
    }

    return '';
  }
}