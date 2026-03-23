<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorSettingsShell
 *
 * Front-end tenant/operator settings shell for /operator/
 *
 * Purpose:
 * - Provide a clean tenant-facing settings dashboard outside wp-admin
 * - Resolve the current operator's tenant from user meta
 * - Render dashboard cards and section editors
 *
 * Entry points:
 * - Direct route render via render_page()
 * - Optional shortcode: [sd_operator_settings]
 *
 * Query args:
 * - ?section=storefront
 * - ?section=pricing
 * - ?section=base_location
 *
 * Notes:
 * - Assumes SD_TenantAccess or user meta SD_Meta::TENANT_ID is available
 * - Assumes SD_TenantConfig, SD_TenantReadiness, and SD_Module_OperatorSettingsSections are loaded
 * - Registration is owned by the module loader; do not manually call ::register() at EOF
 */
if (class_exists('SD_Module_OperatorSettingsShell', false)) { return; }

final class SD_Module_OperatorSettingsShell {

  public static function register() : void {
    add_shortcode('sd_operator_settings', [__CLASS__, 'shortcode']);
  }

  public static function render_page() : void {
    status_header(200);

    if (!is_user_logged_in()) {
      if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'render_login_screen')) {
        SD_Module_OperatorUI::render_login_screen(
          'Operator Login',
          '/operator/',
          'Operator Login',
          'Sign in to access your tenant settings.'
        );
        return;
      }

      self::render_shell_fallback('Operator Login', self::notice_card('Please log in to access operator settings.'));
      return;
    }

    $html = self::shortcode();

    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'render_shell')) {
      SD_Module_OperatorUI::render_shell('Operator', $html);
      return;
    }

    self::render_shell_fallback('Operator', $html);
  }

  public static function shortcode($atts = []) : string {
    if (!is_user_logged_in()) {
      return self::notice_card('Please log in to access operator settings.');
    }

    $user_id   = get_current_user_id();
    $tenant_id = self::current_tenant_id_for_user($user_id);

    if ($tenant_id <= 0) {
      return self::notice_card('No tenant is assigned to your account.');
    }

    if (get_post_type($tenant_id) !== 'sd_tenant') {
      return self::notice_card('Assigned tenant record could not be found.');
    }

    $tenant_name = get_the_title($tenant_id);
    $section     = self::current_section();
    $readiness   = class_exists('SD_TenantReadiness', false)
      ? SD_TenantReadiness::evaluate($tenant_id)
      : ['is_ready' => false, 'missing' => [], 'warnings' => [], 'missing_items' => []];

    $storefront  = class_exists('SD_TenantConfig', false)
      ? SD_TenantConfig::storefront($tenant_id)
      : [];

    $store_state = isset($storefront[SD_Meta::STOREFRONT_STATE])
      ? (string) $storefront[SD_Meta::STOREFRONT_STATE]
      : 'open';

    ob_start();

    echo '<div class="sd-surface sd-surface--wide sd-operator-settings tenant-operator-settings">';
      self::render_styles();
      self::render_header($tenant_id, $tenant_name, $store_state, $readiness, $section);

      if ($section === '') {
        self::render_dashboard($tenant_id);
      } else {
        if (class_exists('SD_Module_OperatorSettingsSections', false) && method_exists('SD_Module_OperatorSettingsSections', 'render_section')) {
          echo SD_Module_OperatorSettingsSections::render_section($tenant_id, $section);
        } else {
          echo self::notice_card('Operator settings sections module is unavailable.');
        }
      }
    echo '</div>';

    return (string) ob_get_clean();
  }

  private static function render_header(int $tenant_id, string $tenant_name, string $store_state, array $readiness, string $current_section) : void {
    $badge_text  = !empty($readiness['is_ready']) ? 'Ready for testing' : 'Configuration incomplete';
    $badge_class = !empty($readiness['is_ready']) ? 'sd-operator-badge sd-operator-badge--ready' : 'sd-operator-badge sd-operator-badge--warn';

    echo '<div class="sd-operator-hero">';
      echo '<div>';
        echo '<div class="sd-operator-kicker">TENANT HOME</div>';
        echo '<h1 class="sd-operator-title">' . esc_html($tenant_name) . '</h1>';
        echo '<div class="sd-operator-sub">Storefront state: ' . esc_html(self::pretty_enum($store_state)) . '</div>';
        echo '<div class="' . esc_attr($badge_class) . '">' . esc_html($badge_text) . '</div>';

        if (empty($readiness['is_ready'])) {
          self::render_readiness_checklist($tenant_id, $readiness, $current_section);
        }
      echo '</div>';

      echo '<div class="sd-operator-actions">';
        echo '<a class="sd-operator-drive-btn" href="' . esc_url(home_url('/operator/trips/')) . '">Drive Mode</a>';
      echo '</div>';
    echo '</div>';
  }

  private static function render_readiness_checklist(int $tenant_id, array $readiness, string $current_section) : void {
    $missing_items = self::missing_items_for_section($tenant_id, $readiness, $current_section);
    $warnings      = (array) ($readiness['warnings'] ?? []);

    if (empty($missing_items) && empty($warnings)) {
      return;
    }

    echo '<div class="sd-operator-readiness">';

    if (!empty($missing_items)) {
      $count = count($missing_items);
      echo '<div class="sd-operator-readiness-title">Missing required config' . ($count > 0 ? ' · ' . (int) $count : '') . '</div>';
      echo '<ul class="sd-operator-readiness-list">';
      foreach ($missing_items as $item) {
        $label   = isset($item['label']) ? (string) $item['label'] : 'Missing setting';
        $reason  = isset($item['reason']) ? (string) $item['reason'] : 'Required value is missing.';
        $section = isset($item['section']) ? (string) $item['section'] : '';

        echo '<li class="sd-operator-readiness-item">';
        if ($section !== '' && $section !== '_unmapped') {
          $url = add_query_arg(['section' => $section], self::base_url());
          echo '<a class="sd-operator-readiness-link" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        } else {
          echo '<span class="sd-operator-readiness-link sd-operator-readiness-link--plain">' . esc_html($label) . '</span>';
        }

        if ($current_section === '' && $section !== '' && $section !== '_unmapped') {
          echo '<span class="sd-operator-readiness-section"> · ' . esc_html(self::section_label($section)) . '</span>';
        }

        echo '<div class="sd-operator-readiness-reason">' . esc_html($reason) . '</div>';
        echo '</li>';
      }
      echo '</ul>';
    }

    if (!empty($warnings) && $current_section === '') {
      echo '<div class="sd-operator-readiness-title sd-operator-readiness-title--warnings">Warnings</div>';
      echo '<ul class="sd-operator-readiness-list sd-operator-readiness-list--warnings">';
      foreach ($warnings as $warning) {
        echo '<li class="sd-operator-readiness-item">' . esc_html((string) $warning) . '</li>';
      }
      echo '</ul>';
    }

    echo '</div>';
  }

  private static function missing_items_for_section(int $tenant_id, array $readiness, string $current_section) : array {
    if (!class_exists('SD_TenantReadiness', false)) {
      return (array) ($readiness['missing_items'] ?? []);
    }

    if ($current_section === '') {
      return (array) SD_TenantReadiness::missing_items($tenant_id);
    }

    $grouped = SD_TenantReadiness::missing_by_section($tenant_id);
    return isset($grouped[$current_section]) && is_array($grouped[$current_section])
      ? $grouped[$current_section]
      : [];
  }

  private static function render_dashboard(int $tenant_id) : void {
    $base = self::base_url();

    $cards = [
      [
        'key'   => 'storefront',
        'title' => 'Storefront Config',
        'desc'  => 'Tenant storefront settings and request surface configuration.',
        'live'  => true,
      ],
      [
        'key'   => 'pricing',
        'title' => 'Pricing Config',
        'desc'  => 'Pricing controls, quote behavior, and policy tuning.',
        'live'  => true,
      ],
      [
        'key'   => 'base_location',
        'title' => 'Base Location',
        'desc'  => 'Set the operational base location used when live location is unavailable.',
        'live'  => true,
      ],
      [
        'key'   => 'profile',
        'title' => 'Tenant Profile',
        'desc'  => 'Driver/operator profile shown to riders and customers.',
        'live'  => true,
      ],
      [
        'key'   => 'vehicle',
        'title' => 'Automobile Info',
        'desc'  => 'Vehicle details, service class, and rider-facing car info.',
        'live'  => false,
      ],
      [
        'key'   => 'brand',
        'title' => 'Brand Config',
        'desc'  => 'Brand colors, identity, and tenant-facing appearance.',
        'live'  => false,
      ],
      [
        'key'   => 'calendar',
        'title' => 'Calendar',
        'desc'  => 'Scheduled and reserved rides.',
        'live'  => false,
      ],
      [
        'key'   => 'drive_mode',
        'title' => 'Drive Mode',
        'desc'  => 'Open the mobile-first live operations surface.',
        'live'  => false,
      ],
    ];

    echo '<div class="sd-operator-grid">';
    foreach ($cards as $card) {
      $classes = 'sd-operator-card';
      if (!$card['live']) {
        $classes .= ' sd-operator-card--disabled';
      }

      echo '<div class="' . esc_attr($classes) . '">';
        if ($card['live']) {
          $url = add_query_arg(['section' => $card['key']], $base);
          echo '<a class="sd-operator-card-link" href="' . esc_url($url) . '">';
        } else {
          echo '<div class="sd-operator-card-link">';
        }

        echo '<div class="sd-operator-card-title">' . esc_html($card['title']) . '</div>';
        echo '<div class="sd-operator-card-desc">' . esc_html($card['desc']) . '</div>';
        if (!$card['live']) {
          echo '<div class="sd-operator-card-soon">Coming next</div>';
        }

        if ($card['live']) {
          echo '</a>';
        } else {
          echo '</div>';
        }
      echo '</div>';
    }
    echo '</div>';
  }

  private static function current_tenant_id_for_user(int $user_id) : int {
    if ($user_id <= 0) return 0;

    if (class_exists('SD_TenantAccess', false) && method_exists('SD_TenantAccess', 'get_current_user_tenant_id')) {
      $tenant_id = (int) SD_TenantAccess::get_current_user_tenant_id();
      if ($tenant_id > 0) return $tenant_id;
    }

    return (int) get_user_meta($user_id, SD_Meta::TENANT_ID, true);
  }

  private static function current_section() : string {
    $allowed = ['storefront', 'pricing', 'base_location', 'profile'];
    $section = isset($_GET['section']) ? sanitize_key((string) $_GET['section']) : '';
    return in_array($section, $allowed, true) ? $section : '';
  }

  private static function base_url() : string {
    return home_url('/operator/');
  }

  private static function section_label(string $section) : string {
    $map = [
      'storefront'    => 'Storefront Config',
      'pricing'       => 'Pricing Config',
      'base_location' => 'Base Location',
      'profile'       => 'Tenant Profile',
      'vehicle'       => 'Automobile Info',
      'brand'         => 'Brand Config',
      'calendar'      => 'Calendar',
    ];

    return isset($map[$section]) ? $map[$section] : ucwords(str_replace('_', ' ', $section));
  }

  private static function pretty_enum(string $value) : string {
    if ($value === '') return '';
    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'pretty_enum')) {
      return SD_Module_OperatorUI::pretty_enum($value);
    }
    return ucwords(str_replace('_', ' ', $value));
  }

  private static function notice_card(string $message) : string {
    return '<div class="sd-card tenant-operator-card"><p>' . esc_html($message) . '</p></div>';
  }

  private static function render_shell_fallback(string $title, string $body_html) : void {
    echo '<!doctype html><html><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . esc_html($title) . '</title>';
    wp_head();
    echo '</head><body>';
    echo $body_html;
    wp_footer();
    echo '</body></html>';
  }

  private static function render_styles() : void {
    static $done = false;
    if ($done) return;
    $done = true;

    echo '<style>
      .sd-operator-settings{max-width:1160px;margin:0 auto;padding:24px 20px 40px}
      .sd-operator-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:28px}
      .sd-operator-kicker{font-size:14px;font-weight:700;letter-spacing:.08em;color:#5a667d;margin-bottom:6px}
      .sd-operator-title{font-size:52px;line-height:1;margin:0 0 8px;color:#17233d}
      .sd-operator-sub{font-size:30px;color:#5a667d;margin-bottom:14px}
      .sd-operator-badge{display:inline-block;padding:8px 14px;border-radius:999px;font-size:14px;font-weight:700}
      .sd-operator-badge--ready{background:#edf9f0;color:#166534}
      .sd-operator-badge--warn{background:#fff7e6;color:#92400e}
      .sd-operator-readiness{margin-top:16px;max-width:720px;background:#fff;border:1px solid #ead7b8;border-radius:18px;padding:16px 18px}
      .sd-operator-readiness-title{font-size:14px;font-weight:800;color:#92400e;margin:0 0 10px}
      .sd-operator-readiness-title--warnings{margin-top:14px;color:#5a667d}
      .sd-operator-readiness-list{margin:0;padding-left:18px}
      .sd-operator-readiness-list--warnings{color:#5a667d}
      .sd-operator-readiness-item{margin:0 0 10px}
      .sd-operator-readiness-link{font-weight:700;color:#17233d;text-decoration:none}
      .sd-operator-readiness-link:hover{text-decoration:underline}
      .sd-operator-readiness-link--plain{text-decoration:none}
      .sd-operator-readiness-section{color:#6b7280;font-size:13px}
      .sd-operator-readiness-reason{font-size:12px;color:#6b7280;margin-top:2px}
      .sd-operator-drive-btn{display:inline-block;background:#17233d;color:#fff;text-decoration:none;padding:16px 24px;border-radius:999px;font-size:20px;font-weight:700}
      .sd-operator-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px}
      .sd-operator-card{background:#fff;border:1px solid #dce3ef;border-radius:24px;min-height:170px}
      .sd-operator-card-link{display:block;padding:22px;color:inherit;text-decoration:none;height:100%}
      .sd-operator-card-title{font-size:22px;font-weight:800;color:#17233d;margin-bottom:10px}
      .sd-operator-card-desc{font-size:14px;line-height:1.45;color:#5a667d}
      .sd-operator-card-soon{margin-top:14px;font-size:12px;font-weight:700;color:#8a94a6;text-transform:uppercase;letter-spacing:.04em}
      .sd-operator-card--disabled{opacity:.92}
      .sd-operator-section-card{background:#fff;border:1px solid #dce3ef;border-radius:24px;padding:24px}
      .sd-operator-section-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:20px}
      .sd-operator-section-title{font-size:28px;font-weight:800;color:#17233d;margin:0 0 6px}
      .sd-operator-section-desc{font-size:14px;color:#5a667d}
      .sd-operator-back{display:inline-block;text-decoration:none;color:#17233d;font-weight:700}
      .sd-operator-notice{margin:0 0 18px;padding:14px 16px;border-radius:16px;font-size:14px}
      .sd-operator-notice--success{background:#edf9f0;color:#166534}
      .sd-operator-notice--error{background:#fef2f2;color:#991b1b}
      .sd-operator-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px 20px}
      .sd-operator-field{display:flex;flex-direction:column;gap:6px}
      .sd-operator-field--full{grid-column:1 / -1}
      .sd-operator-label{font-size:13px;font-weight:700;color:#17233d}
      .sd-operator-help{font-size:12px;color:#6b7280}
      .sd-operator-error{font-size:12px;color:#b91c1c;font-weight:700}
      .sd-operator-field input[type=text],
      .sd-operator-field input[type=number],
      .sd-operator-field select,
      .sd-operator-field textarea{width:100%;border:1px solid #cfd8e6;border-radius:14px;padding:12px 14px;font-size:14px;box-sizing:border-box}
      .sd-operator-field textarea{min-height:110px;resize:vertical}
      .sd-operator-check{display:flex;align-items:center;gap:10px;margin-top:6px}
      .sd-operator-actions-row{display:flex;gap:12px;align-items:center;margin-top:22px}
      .sd-operator-btn{display:inline-block;border:1px solid #17233d;background:#17233d;color:#fff;text-decoration:none;padding:12px 18px;border-radius:999px;font-weight:700;cursor:pointer}
      .sd-operator-btn--ghost{background:#fff;color:#17233d}
      @media (max-width: 980px){
        .sd-operator-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
        .sd-operator-hero{flex-direction:column}
      }
      @media (max-width: 640px){
        .sd-operator-grid,.sd-operator-form-grid{grid-template-columns:1fr}
        .sd-operator-title{font-size:38px}
        .sd-operator-sub{font-size:22px}
      }
    </style>';
  }
}