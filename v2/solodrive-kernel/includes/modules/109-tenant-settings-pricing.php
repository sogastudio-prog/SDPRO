<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_TenantSettingsPricing
 *
 * Tenant Admin Surface: Pricing Config card.
 *
 * Purpose:
 * - Render/store the "pricing" tenant settings section
 * - Use SD_TenantSettingsSchema + SD_TenantSettingsSaver as canonical contract
 * - Keep admin views explicit and controlled (read/edit/save/cancel)
 *
 * Notes:
 * - Assumes sd_tenant CPT exists
 * - Assumes SD_Meta, SD_TenantConfig, SD_TenantReadiness,
 *   SD_TenantSettingsSchema, and SD_TenantSettingsSaver are loaded
 * - Read-only until user explicitly clicks Edit
 */
if (class_exists('SD_Module_TenantSettingsPricing', false)) { return; }

final class SD_Module_TenantSettingsPricing {

  private const NONCE_ACTION = 'sd_save_tenant_pricing';
  private const POST_ACTION  = 'sd_save_tenant_settings_pricing';
  private const SECTION      = 'pricing';
  private const CPT          = 'sd_tenant';

  public static function register() : void {
    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
    add_action('admin_post_' . self::POST_ACTION, [__CLASS__, 'handle_save']);
  }

  public static function add_metabox() : void {
    add_meta_box(
      'sd_tenant_settings_pricing',
      'Pricing Config',
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

    echo '<div class="sd-tenant-settings sd-tenant-settings-pricing">';

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
      (array) ($result['errors'] ?? ['_section' => ['Unable to save pricing settings.']]),
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
      'sd_edit_pricing' => 1,
    ], admin_url('post.php'));

    $view_url = add_query_arg([
      'post' => $tenant_id,
      'action' => 'edit',
    ], admin_url('post.php'));

    echo '<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px;">';
      echo '<div>';
        echo '<div style="font-size:13px;color:#666;margin-bottom:6px;">Controls quote policy, pricing model, rates, expirations, and surcharge behavior.</div>';
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
          echo '<a class="button button-primary" href="' . esc_url($edit_url) . '">Edit Pricing Config</a>';
        }
      echo '</div>';
    echo '</div>';
  }

  private static function render_status_notice(string $status, array $errors) : void {
    if ($status === 'updated') {
      echo '<div class="notice notice-success inline"><p>Pricing settings saved.</p></div>';
      return;
    }

    if ($status === 'error') {
      echo '<div class="notice notice-error inline"><p>';
      echo 'Pricing settings could not be saved.';
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

    self::row('Quote Mode', self::pretty_enum($v[SD_Meta::QUOTE_MODE] ?? ''));
    self::row('Pricing Model', self::pretty_enum($v[SD_Meta::PRICING_MODEL] ?? ''));
    self::row('Currency', $v[SD_Meta::CURRENCY] ?? '');
    self::row('Base Fare', self::money($v[SD_Meta::BASE_FARE] ?? ''));
    self::row('Minimum Fare', self::money($v[SD_Meta::MINIMUM_FARE] ?? ''));
    self::row('Per Mile Rate', self::money($v[SD_Meta::PER_MILE_RATE] ?? ''));
    self::row('Per Minute Rate', self::money($v[SD_Meta::PER_MINUTE_RATE] ?? ''));
    self::row('Wait Time Per Minute', self::money($v[SD_Meta::WAIT_TIME_PER_MINUTE] ?? ''));
    self::row('Deadhead Enabled', self::yes_no($v[SD_Meta::DEADHEAD_ENABLED] ?? '0'));
    self::row('Deadhead Per Mile', self::money($v[SD_Meta::DEADHEAD_PER_MILE] ?? ''));
    self::row('Service Fee', self::money($v[SD_Meta::SERVICE_FEE] ?? ''));
    self::row('Quote Expiry Minutes', self::plain($v[SD_Meta::QUOTE_EXPIRY_MINUTES] ?? ''));
    self::row('Lead Expiry Minutes', self::plain($v[SD_Meta::LEAD_EXPIRY_MINUTES] ?? ''));
    self::row('Requires Manual Review', self::yes_no($v[SD_Meta::QUOTE_REQUIRES_MANUAL_REVIEW] ?? '0'));
    self::row('After Hours Surcharge Type', self::pretty_enum($v[SD_Meta::AFTER_HOURS_SURCHARGE_TYPE] ?? ''));
    self::row('After Hours Surcharge Value', self::money($v[SD_Meta::AFTER_HOURS_SURCHARGE_VALUE] ?? ''));

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

      self::select_row(
        'Quote Mode',
        SD_Meta::QUOTE_MODE,
        $v,
        $errors,
        self::enum_options($schema, SD_Meta::QUOTE_MODE),
        'disabled, manual, automatic, or hybrid'
      );

      self::select_row(
        'Pricing Model',
        SD_Meta::PRICING_MODEL,
        $v,
        $errors,
        self::enum_options($schema, SD_Meta::PRICING_MODEL),
        'flat, distance_time, or manual_only'
      );

      self::input_text_row(
        'Currency',
        SD_Meta::CURRENCY,
        $v,
        $errors,
        '3-letter currency code. Example: USD'
      );

      self::input_text_row(
        'Base Fare',
        SD_Meta::BASE_FARE,
        $v,
        $errors,
        'Decimal amount. Example: 8.50'
      );

      self::input_text_row(
        'Minimum Fare',
        SD_Meta::MINIMUM_FARE,
        $v,
        $errors,
        'Decimal amount. Example: 12.00'
      );

      self::input_text_row(
        'Per Mile Rate',
        SD_Meta::PER_MILE_RATE,
        $v,
        $errors,
        'Decimal amount charged per mile.'
      );

      self::input_text_row(
        'Per Minute Rate',
        SD_Meta::PER_MINUTE_RATE,
        $v,
        $errors,
        'Decimal amount charged per minute.'
      );

      self::input_text_row(
        'Wait Time Per Minute',
        SD_Meta::WAIT_TIME_PER_MINUTE,
        $v,
        $errors,
        'Decimal amount charged per minute of waiting.'
      );

      self::checkbox_row(
        'Deadhead Enabled',
        SD_Meta::DEADHEAD_ENABLED,
        $v,
        $errors,
        'Include deadhead cost in quote logic.'
      );

      self::input_text_row(
        'Deadhead Per Mile',
        SD_Meta::DEADHEAD_PER_MILE,
        $v,
        $errors,
        'Required when Deadhead Enabled is checked.'
      );

      self::input_text_row(
        'Service Fee',
        SD_Meta::SERVICE_FEE,
        $v,
        $errors,
        'Flat service fee added to quote.'
      );

      self::input_number_row(
        'Quote Expiry Minutes',
        SD_Meta::QUOTE_EXPIRY_MINUTES,
        $v,
        $errors,
        'How long a presented quote remains actionable.'
      );

      self::input_number_row(
        'Lead Expiry Minutes',
        SD_Meta::LEAD_EXPIRY_MINUTES,
        $v,
        $errors,
        'How long a lead can wait before aging out.'
      );

      self::checkbox_row(
        'Requires Manual Review',
        SD_Meta::QUOTE_REQUIRES_MANUAL_REVIEW,
        $v,
        $errors,
        'Force human review before quote presentation.'
      );

      self::select_row(
        'After Hours Surcharge Type',
        SD_Meta::AFTER_HOURS_SURCHARGE_TYPE,
        $v,
        $errors,
        self::enum_options($schema, SD_Meta::AFTER_HOURS_SURCHARGE_TYPE),
        'none, flat, or percent'
      );

      self::input_text_row(
        'After Hours Surcharge Value',
        SD_Meta::AFTER_HOURS_SURCHARGE_VALUE,
        $v,
        $errors,
        'Required when surcharge type is flat or percent.'
      );

      echo '</tbody></table>';

      echo '<p style="margin-top:16px;">';
        echo '<button type="submit" class="button button-primary">Save Pricing Config</button> ';
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
  // State helpers
  // ---------------------------------------------------------------------------

  private static function is_edit_mode() : bool {
    return isset($_GET['sd_edit_pricing']) && (string) $_GET['sd_edit_pricing'] === '1';
  }

  private static function save_status() : string {
    return isset($_GET['sd_pricing_status'])
      ? sanitize_key((string) $_GET['sd_pricing_status'])
      : '';
  }

  private static function posted_errors() : array {
    if (empty($_GET['sd_pricing_errors'])) {
      return [];
    }

    $decoded = json_decode(wp_unslash(rawurldecode((string) $_GET['sd_pricing_errors'])), true);
    return is_array($decoded) ? $decoded : [];
  }

  private static function posted_values() : array {
    if (empty($_GET['sd_pricing_values'])) {
      return [];
    }

    $decoded = json_decode(wp_unslash(rawurldecode((string) $_GET['sd_pricing_values'])), true);
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
      'sd_pricing_status' => $status,
    ];

    if ($edit_mode) {
      $args['sd_edit_pricing'] = 1;
    }

    if (!empty($errors)) {
      $args['sd_pricing_errors'] = rawurlencode(wp_json_encode($errors));
    }

    if (!empty($values)) {
      $args['sd_pricing_values'] = rawurlencode(wp_json_encode($values));
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

  private static function money($value) : string {
    $value = (string) $value;
    return $value === '' ? '' : $value;
  }

  private static function plain($value) : string {
    $value = (string) $value;
    return $value === '' ? '' : $value;
  }
}