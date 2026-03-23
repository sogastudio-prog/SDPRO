<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_TenantSettingsStorefront
 *
 * Tenant Admin Surface: Storefront Config card.
 *
 * Purpose:
 * - Render/store the "storefront" tenant settings section
 * - Use SD_TenantSettingsSchema + SD_TenantSettingsSaver as canonical contract
 * - Keep admin views explicit and controlled (read/edit/save/cancel)
 *
 * Notes:
 * - Assumes sd_tenant CPT exists
 * - Assumes SD_Meta, SD_TenantConfig, SD_TenantReadiness,
 *   SD_TenantSettingsSchema, and SD_TenantSettingsSaver are loaded
 * - Read-only until user explicitly clicks Edit
 */
if (class_exists('SD_Module_TenantSettingsStorefront', false)) { return; }

final class SD_Module_TenantSettingsStorefront {

  private const NONCE_ACTION = 'sd_save_tenant_storefront';
  private const POST_ACTION  = 'sd_save_tenant_settings_storefront';
  private const SECTION      = 'storefront';
  private const CPT          = 'sd_tenant';

  public static function register() : void {
    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
    add_action('admin_post_' . self::POST_ACTION, [__CLASS__, 'handle_save']);
  }

  public static function add_metabox() : void {
    add_meta_box(
      'sd_tenant_settings_storefront',
      'Storefront Config',
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

    echo '<div class="sd-tenant-settings sd-tenant-settings-storefront">';

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

    $input = SD_TenantSettingsSaver::section_input_from_request($section, $_POST);

    // Extra uniqueness checks not handled by base schema class.
    $extra_errors = self::validate_unique_constraints($tenant_id, $input);

    if (!empty($extra_errors)) {
      self::redirect_with_state($tenant_id, 'error', $extra_errors, $input, true);
    }

    $result = SD_TenantSettingsSaver::save_section($tenant_id, $section, $input);

    if (!empty($result['ok'])) {
      self::redirect_with_state($tenant_id, 'updated', [], $result['normalized'], false);
    }

    self::redirect_with_state(
      $tenant_id,
      'error',
      (array) ($result['errors'] ?? ['_section' => ['Unable to save storefront settings.']]),
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
      'sd_edit_storefront' => 1,
    ], admin_url('post.php'));

    $view_url = add_query_arg([
      'post' => $tenant_id,
      'action' => 'edit',
    ], admin_url('post.php'));

    echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px;">';
      echo '<div>';
        echo '<div style="font-size:13px;color:#666;margin-bottom:6px;">Controls public storefront behavior, request gating, and rider-facing storefront mode.</div>';
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
          echo '<a class="button button-primary" href="' . esc_url($edit_url) . '">Edit Storefront Config</a>';
        }
      echo '</div>';
    echo '</div>';
  }

  private static function render_status_notice(string $status, array $errors) : void {
    if ($status === 'updated') {
      echo '<div class="notice notice-success inline"><p>Storefront settings saved.</p></div>';
      return;
    }

    if ($status === 'error') {
      echo '<div class="notice notice-error inline"><p>';
      echo 'Storefront settings could not be saved.';
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

    self::row('Tenant Slug', $v[SD_Meta::TENANT_SLUG] ?? '');
    self::row('Tenant Domain', $v[SD_Meta::TENANT_DOMAIN] ?? '');
    self::row('Storefront State', self::pretty_enum($v[SD_Meta::STOREFRONT_STATE] ?? ''));
    self::row('Storefront Enabled', self::yes_no($v[SD_Meta::STOREFRONT_ENABLED] ?? '0'));
    self::row('Accepting Requests', self::yes_no($v[SD_Meta::STOREFRONT_ACCEPTING_REQUESTS] ?? '0'));
    self::row('Request Mode', self::pretty_enum($v[SD_Meta::STOREFRONT_REQUEST_MODE] ?? ''));
    self::row('Requires Quote', self::yes_no($v[SD_Meta::STOREFRONT_REQUIRES_QUOTE] ?? '0'));
    self::row('Allows Immediate Booking', self::yes_no($v[SD_Meta::STOREFRONT_ALLOWS_IMMEDIATE_BOOKING] ?? '0'));
    self::row('Hours Mode', self::pretty_enum($v[SD_Meta::STOREFRONT_HOURS_MODE] ?? ''));
    self::row('Storefront Timezone', $v[SD_Meta::STOREFRONT_TIMEZONE] ?? '');
    self::row('Busy Message', $v[SD_Meta::STOREFRONT_BUSY_MESSAGE] ?? '');
    self::row('Closure Message', $v[SD_Meta::STOREFRONT_CLOSURE_MESSAGE] ?? '');

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
        'Tenant Slug',
        SD_Meta::TENANT_SLUG,
        $v,
        $errors,
        'Unique storefront handle. Lowercase letters, numbers, and hyphens only.'
      );

      self::input_text_row(
        'Tenant Domain',
        SD_Meta::TENANT_DOMAIN,
        $v,
        $errors,
        'Optional custom domain. Host only, no https:// and no path.'
      );

      self::select_row(
        'Storefront State',
        SD_Meta::STOREFRONT_STATE,
        $v,
        $errors,
        self::enum_options($schema, SD_Meta::STOREFRONT_STATE),
        'Controls public storefront posture.'
      );

      self::checkbox_row(
        'Storefront Enabled',
        SD_Meta::STOREFRONT_ENABLED,
        $v,
        $errors,
        'Master on/off switch for storefront rendering.'
      );

      self::checkbox_row(
        'Accepting Requests',
        SD_Meta::STOREFRONT_ACCEPTING_REQUESTS,
        $v,
        $errors,
        'Allow rider intake requests when storefront is available.'
      );

      self::select_row(
        'Request Mode',
        SD_Meta::STOREFRONT_REQUEST_MODE,
        $v,
        $errors,
        self::enum_options($schema, SD_Meta::STOREFRONT_REQUEST_MODE),
        'Choose quote-only, booking-only, or both.'
      );

      self::checkbox_row(
        'Requires Quote',
        SD_Meta::STOREFRONT_REQUIRES_QUOTE,
        $v,
        $errors,
        'When enabled, the rider must go through quote flow before booking.'
      );

      self::checkbox_row(
        'Allows Immediate Booking',
        SD_Meta::STOREFRONT_ALLOWS_IMMEDIATE_BOOKING,
        $v,
        $errors,
        'Use with care. Cannot be enabled while request mode is quote_only.'
      );

      self::select_row(
        'Hours Mode',
        SD_Meta::STOREFRONT_HOURS_MODE,
        $v,
        $errors,
        self::enum_options($schema, SD_Meta::STOREFRONT_HOURS_MODE),
        'always_on or scheduled'
      );

      self::input_text_row(
        'Storefront Timezone',
        SD_Meta::STOREFRONT_TIMEZONE,
        $v,
        $errors,
        'Required when Hours Mode = scheduled. Example: America/New_York'
      );

      self::textarea_row(
        'Busy Message',
        SD_Meta::STOREFRONT_BUSY_MESSAGE,
        $v,
        $errors,
        'Shown when storefront state is busy.'
      );

      self::textarea_row(
        'Closure Message',
        SD_Meta::STOREFRONT_CLOSURE_MESSAGE,
        $v,
        $errors,
        'Shown when storefront state is closed.'
      );

      echo '</tbody></table>';

      echo '<p style="margin-top:16px;">';
        echo '<button type="submit" class="button button-primary">Save Storefront Config</button> ';
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

  private static function textarea_row(string $label, string $key, array $v, array $errors, string $help = '') : void {
    $value = (string) ($v[$key] ?? '');

    echo '<tr>';
      echo '<th scope="row"><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
      echo '<td>';
        echo '<textarea class="large-text" rows="4" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '">' . esc_textarea($value) . '</textarea>';
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

  private static function checkbox_row(string $label, string $key, array $v, array $errors, string $help = '') : void {
    $checked = ((string) ($v[$key] ?? '0') === '1');

    echo '<tr>';
      echo '<th scope="row">' . esc_html($label) . '</th>';
      echo '<td>';
        echo '<input type="hidden" name="' . esc_attr($key) . '" value="0">';
        echo '<label>';
          echo '<input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($checked, true, false) . '> ';
          echo esc_html($label);
        echo '</label>';
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
  // Validation helpers
  // ---------------------------------------------------------------------------

  private static function validate_unique_constraints(int $tenant_id, array $input) : array {
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
      'post_type'      => self::CPT,
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'post__not_in'   => [$tenant_id],
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'   => SD_Meta::TENANT_SLUG,
          'value' => $slug,
        ],
      ],
    ]);

    return !empty($q->posts);
  }

  private static function domain_in_use_by_other_tenant(int $tenant_id, string $domain) : bool {
    $q = new \WP_Query([
      'post_type'      => self::CPT,
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'post__not_in'   => [$tenant_id],
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'   => SD_Meta::TENANT_DOMAIN,
          'value' => $domain,
        ],
      ],
    ]);

    return !empty($q->posts);
  }

  private static function normalize_domain(string $value) : string {
    $value = strtolower(trim($value));
    $value = preg_replace('#^https?://#', '', $value);
    $value = preg_replace('#/.*$#', '', $value);
    return sanitize_text_field($value);
  }

  // ---------------------------------------------------------------------------
  // State helpers
  // ---------------------------------------------------------------------------

  private static function is_edit_mode() : bool {
    return isset($_GET['sd_edit_storefront']) && (string) $_GET['sd_edit_storefront'] === '1';
  }

  private static function save_status() : string {
    return isset($_GET['sd_storefront_status'])
      ? sanitize_key((string) $_GET['sd_storefront_status'])
      : '';
  }

  private static function posted_errors() : array {
    if (empty($_GET['sd_storefront_errors'])) {
      return [];
    }

    $decoded = json_decode(wp_unslash((string) $_GET['sd_storefront_errors']), true);
    return is_array($decoded) ? $decoded : [];
  }

  private static function posted_values() : array {
    if (empty($_GET['sd_storefront_values'])) {
      return [];
    }

    $decoded = json_decode(wp_unslash((string) $_GET['sd_storefront_values']), true);
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
      'sd_storefront_status' => $status,
    ];

    if ($edit_mode) {
      $args['sd_edit_storefront'] = 1;
    }

    if (!empty($errors)) {
      $args['sd_storefront_errors'] = rawurlencode(wp_json_encode($errors));
    }

    if (!empty($values)) {
      $args['sd_storefront_values'] = rawurlencode(wp_json_encode($values));
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

  private static function yes_no($value) : string {
    return ((string) $value === '1') ? 'Yes' : 'No';
  }

  private static function enum_options(array $schema, string $key) : array {
    $field = $schema[$key] ?? [];
    $out = [];

    foreach ((array) ($field['enum'] ?? []) as $value) {
      $out[(string) $value] = self::pretty_enum((string) $value);
    }

    return $out;
  }
}