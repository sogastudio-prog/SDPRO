<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_TenantSettingsBaseLocation
 *
 * Tenant Admin Surface: Base Location card.
 *
 * Purpose:
 * - Render/store the "base_location" tenant settings section
 * - Use SD_TenantSettingsSchema + SD_TenantSettingsSaver as canonical contract
 * - Keep admin views explicit and controlled (read/edit/save/cancel)
 *
 * Notes:
 * - Assumes sd_tenant CPT exists
 * - Assumes SD_Meta, SD_TenantConfig, SD_TenantReadiness,
 *   SD_TenantSettingsSchema, and SD_TenantSettingsSaver are loaded
 * - Read-only until user explicitly clicks Edit
 */
if (class_exists('SD_Module_TenantSettingsBaseLocation', false)) { return; }

final class SD_Module_TenantSettingsBaseLocation {

  private const NONCE_ACTION = 'sd_save_tenant_base_location';
  private const POST_ACTION  = 'sd_save_tenant_settings_base_location';
  private const SECTION      = 'base_location';
  private const CPT          = 'sd_tenant';

  public static function register() : void {
    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
    add_action('admin_post_' . self::POST_ACTION, [__CLASS__, 'handle_save']);
  }

  public static function add_metabox() : void {
    add_meta_box(
      'sd_tenant_settings_base_location',
      'Base Location',
      [__CLASS__, 'render_metabox'],
      self::CPT,
      'normal',
      'high'
    );
  }

  public static function render_metabox(\WP_Post $post) : void {
    $tenant_id = (int) $post->ID;

    if ($post->post_type !== self::CPT) {
      echo '<p>This settings card is only available on tenant records.</p>';
      return;
    }

    $values    = SD_TenantConfig::form_defaults($tenant_id, self::SECTION);
    $schema    = SD_TenantSettingsSchema::section_fields(self::SECTION);
    $editing   = self::is_edit_mode();
    $status    = self::save_status();
    $readiness = class_exists('SD_TenantReadiness', false)
      ? SD_TenantReadiness::badge($tenant_id)
      : ['status' => 'incomplete', 'label' => 'Configuration unknown', 'count_missing' => 0];

    $errors = self::posted_errors();
    $old    = self::posted_values();

    if (!empty($old)) {
      $values = array_merge($values, $old);
    }

    echo '<div class="sd-tenant-settings sd-tenant-settings-base-location">';

      self::render_header($tenant_id, $editing, $readiness);
      self::render_status_notice($status, $errors);

      if (!$editing) {
        self::render_read_view($values);
      } else {
        self::render_edit_form($tenant_id, $values, $schema, $errors);
      }

    echo '</div>';
  }

  public static function handle_save() : void {
    if (!current_user_can('edit_posts')) {
      wp_die('You do not have permission to do that.');
    }

    $tenant_id = isset($_POST['tenant_id']) ? absint($_POST['tenant_id']) : 0;
    $section   = self::SECTION;

    if (!$tenant_id || get_post_type($tenant_id) !== self::CPT) {
      self::redirect_with_state(0, 'error', ['_section' => ['Invalid tenant record.']], [], false);
    }

    check_admin_referer(self::NONCE_ACTION, '_sd_nonce');

    $input  = SD_TenantSettingsSaver::section_input_from_request($section, $_POST);
    $result = SD_TenantSettingsSaver::save_section($tenant_id, $section, $input);

    if (!empty($result['ok'])) {
      self::redirect_with_state($tenant_id, 'updated', [], $result['normalized'], false);
    }

    self::redirect_with_state(
      $tenant_id,
      'error',
      (array) ($result['errors'] ?? ['_section' => ['Unable to save base location settings.']]),
      $input,
      true
    );
  }

  // ---------------------------------------------------------------------------
  // Render helpers
  // ---------------------------------------------------------------------------

  private static function render_header(int $tenant_id, bool $editing, array $readiness) : void {
    $badge_class = ($readiness['status'] ?? '') === 'ready'
      ? 'sd-badge sd-badge--success'
      : 'sd-badge sd-badge--warning';

    $edit_url = add_query_arg([
      'post' => $tenant_id,
      'action' => 'edit',
      'sd_edit_base_location' => 1,
    ], admin_url('post.php'));

    $view_url = add_query_arg([
      'post' => $tenant_id,
      'action' => 'edit',
    ], admin_url('post.php'));

    echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px;">';
      echo '<div>';
        echo '<div style="font-size:13px;color:#666;margin-bottom:6px;">Controls service-area anchor, radius policy, and out-of-area handling for storefront + quote flow.</div>';
        echo '<div class="' . esc_attr($badge_class) . '" style="display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;">';
          echo esc_html((string) ($readiness['label'] ?? 'Configuration'));
          $count_missing = (int) ($readiness['count_missing'] ?? 0);
          if ($count_missing > 0) {
            echo ' · ' . esc_html((string) $count_missing) . ' missing';
          }
        echo '</div>';
      echo '</div>';

      echo '<div>';
        if ($editing) {
          echo '<a class="button" href="' . esc_url($view_url) . '">Cancel</a>';
        } else {
          echo '<a class="button button-primary" href="' . esc_url($edit_url) . '">Edit Base Location</a>';
        }
      echo '</div>';
    echo '</div>';
  }

  private static function render_status_notice(string $status, array $errors) : void {
    if ($status === 'updated') {
      echo '<div class="notice notice-success inline"><p>Base location settings saved.</p></div>';
      return;
    }

    if ($status === 'error') {
      echo '<div class="notice notice-error inline"><p>';
      echo 'Base location settings could not be saved.';
      echo '</p>';

      if (!empty($errors)) {
        echo '<ul style="margin:8px 0 0 18px;">';
        foreach ($errors as $messages) {
          foreach ((array) $messages as $message) {
            echo '<li>' . esc_html((string) $message) . '</li>';
          }
        }
        echo '</ul>';
      }

      echo '</div>';
    }
  }

  private static function render_read_view(array $v) : void {
    echo '<table class="widefat striped" style="max-width:100%;">';
    echo '<tbody>';

    self::row('Base Location Label', $v[SD_Meta::BASE_LOCATION_LABEL] ?? '');
    self::row('Base Location Place ID', $v[SD_Meta::BASE_LOCATION_PLACE_ID] ?? '');
    self::row('Base Latitude', self::plain($v[SD_Meta::BASE_LOCATION_LAT] ?? ''));
    self::row('Base Longitude', self::plain($v[SD_Meta::BASE_LOCATION_LNG] ?? ''));
    self::row('Base Radius (m)', self::plain($v[SD_Meta::BASE_LOCATION_RADIUS_M] ?? ''));
    self::row('Service Radius Mode', self::pretty_enum($v[SD_Meta::SERVICE_RADIUS_MODE] ?? ''));
    self::row('Pickup Radius (m)', self::plain($v[SD_Meta::PICKUP_RADIUS_M] ?? ''));
    self::row('Dropoff Radius (m)', self::plain($v[SD_Meta::DROPOFF_RADIUS_M] ?? ''));
    self::row('Out of Area Policy', self::pretty_enum($v[SD_Meta::OUT_OF_AREA_POLICY] ?? ''));

    echo '</tbody>';
    echo '</table>';
  }

  private static function render_edit_form(int $tenant_id, array $v, array $schema, array $errors) : void {
    $action = admin_url('admin-post.php');

    echo '<form method="post" action="' . esc_url($action) . '">';
      echo '<input type="hidden" name="action" value="' . esc_attr(self::POST_ACTION) . '">';
      echo '<input type="hidden" name="tenant_id" value="' . esc_attr((string) $tenant_id) . '">';
      wp_nonce_field(self::NONCE_ACTION, '_sd_nonce');

      echo '<table class="form-table" role="presentation"><tbody>';

      self::input_text_row(
        'Base Location Label',
        SD_Meta::BASE_LOCATION_LABEL,
        $v,
        $errors,
        'Human-readable location label used as the service-area anchor.'
      );

      self::input_text_row(
        'Base Location Place ID',
        SD_Meta::BASE_LOCATION_PLACE_ID,
        $v,
        $errors,
        'Optional Google Place ID for the base location.'
      );

      self::input_text_row(
        'Base Latitude',
        SD_Meta::BASE_LOCATION_LAT,
        $v,
        $errors,
        'Required numeric latitude. Example: 40.712776'
      );

      self::input_text_row(
        'Base Longitude',
        SD_Meta::BASE_LOCATION_LNG,
        $v,
        $errors,
        'Required numeric longitude. Example: -74.005974'
      );

      self::input_number_row(
        'Base Radius (m)',
        SD_Meta::BASE_LOCATION_RADIUS_M,
        $v,
        $errors,
        'Primary service radius in meters. Example: 40000'
      );

      self::select_row(
        'Service Radius Mode',
        SD_Meta::SERVICE_RADIUS_MODE,
        $v,
        $errors,
        self::enum_options($schema, SD_Meta::SERVICE_RADIUS_MODE),
        'base_circle, pickup_only, or flexible'
      );

      self::input_number_row(
        'Pickup Radius (m)',
        SD_Meta::PICKUP_RADIUS_M,
        $v,
        $errors,
        'Required for pickup_only and flexible modes.'
      );

      self::input_number_row(
        'Dropoff Radius (m)',
        SD_Meta::DROPOFF_RADIUS_M,
        $v,
        $errors,
        'Required for flexible mode.'
      );

      self::select_row(
        'Out of Area Policy',
        SD_Meta::OUT_OF_AREA_POLICY,
        $v,
        $errors,
        self::enum_options($schema, SD_Meta::OUT_OF_AREA_POLICY),
        'reject, request_quote, or allow_with_surcharge'
      );

      echo '</tbody></table>';

      echo '<p style="margin-top:16px;">';
        echo '<button type="submit" class="button button-primary">Save Base Location</button> ';
        echo '<a class="button" href="' . esc_url(add_query_arg([
          'post' => $tenant_id,
          'action' => 'edit',
        ], admin_url('post.php'))) . '">Cancel</a>';
      echo '</p>';

    echo '</form>';
  }

  // ---------------------------------------------------------------------------
  // Field render helpers
  // ---------------------------------------------------------------------------

  private static function row(string $label, string $value) : void {
    echo '<tr>';
      echo '<th style="width:240px;">' . esc_html($label) . '</th>';
      echo '<td>' . ($value !== '' ? esc_html($value) : '<span style="color:#777;">—</span>') . '</td>';
    echo '</tr>';
  }

  private static function input_text_row(string $label, string $key, array $v, array $errors, string $help = '') : void {
    $value = (string) ($v[$key] ?? '');

    echo '<tr>';
      echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
      echo '<td>';
        echo '<input type="text" class="regular-text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        self::field_help_and_errors($help, $errors[$key] ?? []);
      echo '</td>';
    echo '</tr>';
  }

  private static function input_number_row(string $label, string $key, array $v, array $errors, string $help = '') : void {
    $value = (string) ($v[$key] ?? '');

    echo '<tr>';
      echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
      echo '<td>';
        echo '<input type="number" class="small-text" step="1" min="0" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        self::field_help_and_errors($help, $errors[$key] ?? []);
      echo '</td>';
    echo '</tr>';
  }

  private static function select_row(string $label, string $key, array $v, array $errors, array $options, string $help = '') : void {
    $value = (string) ($v[$key] ?? '');

    echo '<tr>';
      echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
      echo '<td>';
        echo '<select id="' . esc_attr($key) . '" name="' . esc_attr($key) . '">';
          foreach ($options as $option_value => $option_label) {
            echo '<option value="' . esc_attr($option_value) . '" ' . selected($value, (string) $option_value, false) . '>';
              echo esc_html($option_label);
            echo '</option>';
          }
        echo '</select>';
        self::field_help_and_errors($help, $errors[$key] ?? []);
      echo '</td>';
    echo '</tr>';
  }

  private static function field_help_and_errors(string $help, array $messages) : void {
    if ($help !== '') {
      echo '<p class="description">' . esc_html($help) . '</p>';
    }

    if (!empty($messages)) {
      foreach ($messages as $message) {
        echo '<p style="color:#b32d2e;margin:6px 0 0;"><strong>' . esc_html((string) $message) . '</strong></p>';
      }
    }
  }

  // ---------------------------------------------------------------------------
  // State helpers
  // ---------------------------------------------------------------------------

  private static function is_edit_mode() : bool {
    return isset($_GET['sd_edit_base_location']) && (string) $_GET['sd_edit_base_location'] === '1';
  }

  private static function save_status() : string {
    return isset($_GET['sd_base_location_status'])
      ? sanitize_key((string) $_GET['sd_base_location_status'])
      : '';
  }

  private static function posted_errors() : array {
    if (empty($_GET['sd_base_location_errors'])) {
      return [];
    }

    $decoded = json_decode(wp_unslash(rawurldecode((string) $_GET['sd_base_location_errors'])), true);
    return is_array($decoded) ? $decoded : [];
  }

  private static function posted_values() : array {
    if (empty($_GET['sd_base_location_values'])) {
      return [];
    }

    $decoded = json_decode(wp_unslash(rawurldecode((string) $_GET['sd_base_location_values'])), true);
    return is_array($decoded) ? $decoded : [];
  }

  private static function redirect_with_state(
    int $tenant_id,
    string $status,
    array $errors = [],
    array $values = [],
    bool $edit_mode = false
  ) : void {
    $args = [
      'post' => $tenant_id,
      'action' => 'edit',
      'sd_base_location_status' => $status,
    ];

    if ($edit_mode) {
      $args['sd_edit_base_location'] = 1;
    }

    if (!empty($errors)) {
      $args['sd_base_location_errors'] = rawurlencode(wp_json_encode($errors));
    }

    if (!empty($values)) {
      $args['sd_base_location_values'] = rawurlencode(wp_json_encode($values));
    }

    wp_safe_redirect(add_query_arg($args, admin_url('post.php')));
    exit;
  }

  // ---------------------------------------------------------------------------
  // Display helpers
  // ---------------------------------------------------------------------------

  private static function pretty_enum(string $value) : string {
    if ($value === '') return '';
    return ucwords(str_replace('_', ' ', $value));
  }

  private static function enum_options(array $schema, string $key) : array {
    $field = $schema[$key] ?? [];
    $out = [];

    foreach ((array) ($field['enum'] ?? []) as $value) {
      $out[(string) $value] = self::pretty_enum((string) $value);
    }

    return $out;
  }

  private static function plain($value) : string {
    $value = (string) $value;
    return $value === '' ? '' : $value;
  }
}