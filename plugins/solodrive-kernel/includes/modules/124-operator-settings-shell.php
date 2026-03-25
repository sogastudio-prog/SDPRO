<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorSettingsShell
 *
 * Unified mobile-first operator application shell.
 *
 * Canon:
 * - One private tenant operator app
 * - Mobile-first
 * - Drive is the default operational tab
 * - Secondary tabs render lighter settings/config sections
 * - Internal navigation is query-arg based, not rewrite dependent
 *
 * Entry points:
 * - Direct route render via render_page()
 * - Shortcode: [sd_operator_settings]
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
          self::current_request_path(),
          'Operator Login',
          'Sign in to access your operator app.'
        );
        return;
      }

      self::render_shell_fallback('Operator Login', self::fallback_login_card(self::current_request_url()));
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
      return self::render_login_fragment();
    }

    $user_id   = get_current_user_id();
    $tenant_id = self::current_tenant_id_for_user($user_id);

    if ($tenant_id <= 0) {
      return self::notice_card('No tenant is assigned to your account.');
    }

    if (get_post_type($tenant_id) !== 'sd_tenant') {
      return self::notice_card('Assigned tenant record could not be found.');
    }

    if (!self::current_user_can_operator_surface()) {
      return self::notice_card('Your account does not have operator access.');
    }

    $tenant_name = get_the_title($tenant_id);
$tab         = self::current_tab();

if ($tab === 'drive' && class_exists('SD_Module_OperatorDriveMode', false) && method_exists('SD_Module_OperatorDriveMode', 'boot_drive_runtime')) {
  SD_Module_OperatorDriveMode::boot_drive_runtime($tenant_id);
}

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

    echo '<div class="sd-surface sd-surface--wide sd-operator-app tenant-operator-app">';
      self::render_styles();
      self::render_app_header($tenant_name, $store_state, $readiness, $tab);
      self::render_tab_nav($tab);

      echo '<div class="sd-operator-app-body">';
      echo self::render_tab_body($tenant_id, $tab, $readiness);
      echo '</div>';
    echo '</div>';

    return (string) ob_get_clean();
  }

  private static function render_login_fragment() : string {
    if (class_exists('SD_Module_OperatorUI', false) && method_exists('SD_Module_OperatorUI', 'render_login_screen')) {
      ob_start();
      SD_Module_OperatorUI::render_login_screen(
        'Operator Login',
        self::current_request_path(),
        'Operator Login',
        'Sign in to access your operator app.'
      );
      return (string) ob_get_clean();
    }

    return self::fallback_login_card(self::current_request_url());
  }

  private static function render_app_header(string $tenant_name, string $store_state, array $readiness, string $tab) : void {
    $badge_text  = !empty($readiness['is_ready']) ? 'Ready for testing' : 'Configuration incomplete';
    $badge_class = !empty($readiness['is_ready']) ? 'sd-operator-badge sd-operator-badge--ready' : 'sd-operator-badge sd-operator-badge--warn';

    echo '<div class="sd-operator-app-head">';
      echo '<div class="sd-operator-app-head-main">';
        echo '<div class="sd-operator-kicker">OPERATOR APP</div>';
        echo '<h1 class="sd-operator-title">' . esc_html($tenant_name) . '</h1>';
        echo '<div class="sd-operator-sub">Storefront state: ' . esc_html(self::pretty_enum($store_state)) . '</div>';
      echo '</div>';

      if ($tab !== 'drive') {
        echo '<div class="sd-operator-app-head-side">';
          echo '<div class="' . esc_attr($badge_class) . '">' . esc_html($badge_text) . '</div>';
        echo '</div>';
      }
    echo '</div>';

    if ($tab !== 'drive' && empty($readiness['is_ready'])) {
      self::render_readiness_checklist($readiness, $tab);
    }
  }

  private static function render_tab_nav(string $tab) : void {
    $tabs = [
      'drive'         => 'Drive',
      'home'          => 'Home',
      'storefront'    => 'Storefront',
      'pricing'       => 'Pricing',
      'base_location' => 'Base',
      'profile'       => 'Profile',
    ];

    echo '<div class="sd-operator-tabs">';
    foreach ($tabs as $key => $label) {
      $classes = 'sd-operator-tab';
      if ($tab === $key) {
        $classes .= ' is-active';
      }

      $url = add_query_arg(['tab' => $key], self::base_url());
      echo '<a class="' . esc_attr($classes) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</div>';
  }

  private static function render_tab_body(int $tenant_id, string $tab, array $readiness) : string {
    switch ($tab) {
      case 'drive':
        if (class_exists('SD_Module_OperatorDriveMode', false) && method_exists('SD_Module_OperatorDriveMode', 'render_tab')) {
          return SD_Module_OperatorDriveMode::render_tab($tenant_id);
        }
        return self::notice_card('Drive module unavailable.');

      case 'home':
        ob_start();
        self::render_dashboard($tenant_id);
        return (string) ob_get_clean();

      case 'storefront':
      case 'pricing':
      case 'base_location':
      case 'profile':
        if (class_exists('SD_Module_OperatorSettingsSections', false) && method_exists('SD_Module_OperatorSettingsSections', 'render_section')) {
          return SD_Module_OperatorSettingsSections::render_section($tenant_id, $tab);
        }
        return self::notice_card('Operator settings sections module is unavailable.');
    }

    ob_start();
    self::render_dashboard($tenant_id);
    return (string) ob_get_clean();
  }

  private static function render_readiness_checklist(array $readiness, string $current_tab) : void {
    $tenant_id = self::current_tenant_id_for_user(get_current_user_id());
    $missing_items = self::missing_items_for_tab($tenant_id, $readiness, $current_tab);
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
          $url = add_query_arg(['tab' => $section], self::base_url());
          echo '<a class="sd-operator-readiness-link" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        } else {
          echo '<span class="sd-operator-readiness-link sd-operator-readiness-link--plain">' . esc_html($label) . '</span>';
        }

        if ($current_tab === 'home' && $section !== '' && $section !== '_unmapped') {
          echo '<span class="sd-operator-readiness-section"> · ' . esc_html(self::section_label($section)) . '</span>';
        }

        echo '<div class="sd-operator-readiness-reason">' . esc_html($reason) . '</div>';
        echo '</li>';
      }
      echo '</ul>';
    }

    if (!empty($warnings) && $current_tab === 'home') {
      echo '<div class="sd-operator-readiness-title sd-operator-readiness-title--warnings">Warnings</div>';
      echo '<ul class="sd-operator-readiness-list sd-operator-readiness-list--warnings">';
      foreach ($warnings as $warning) {
        echo '<li class="sd-operator-readiness-item">' . esc_html((string) $warning) . '</li>';
      }
      echo '</ul>';
    }

    echo '</div>';
  }

  private static function missing_items_for_tab(int $tenant_id, array $readiness, string $current_tab) : array {
    if (!class_exists('SD_TenantReadiness', false)) {
      return (array) ($readiness['missing_items'] ?? []);
    }

    if ($current_tab === 'home') {
      return (array) SD_TenantReadiness::missing_items($tenant_id);
    }

    $grouped = SD_TenantReadiness::missing_by_section($tenant_id);
    return isset($grouped[$current_tab]) && is_array($grouped[$current_tab])
      ? $grouped[$current_tab]
      : [];
  }

  private static function render_dashboard(int $tenant_id) : void {
    $base = self::base_url();

    $cards = [
      [
        'key'   => 'drive',
        'title' => 'Drive',
        'desc'  => 'Open the mobile-first live operations surface.',
        'live'  => true,
      ],
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
    ];

    echo '<div class="sd-operator-grid">';
    foreach ($cards as $card) {
      $classes = 'sd-operator-card';
      if (!$card['live']) {
        $classes .= ' sd-operator-card--disabled';
      }

      echo '<div class="' . esc_attr($classes) . '">';
        if ($card['live']) {
          $url = add_query_arg(['tab' => $card['key']], $base);
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

    if (class_exists('SD_TenantAccess', false) && method_exists('SD_TenantAccess', 'current_user_tenant_id')) {
      $tenant_id = (int) SD_TenantAccess::current_user_tenant_id();
      if ($tenant_id > 0) return $tenant_id;
    }

    return (int) get_user_meta($user_id, SD_Meta::TENANT_ID, true);
  }

  private static function current_user_can_operator_surface() : bool {
    if (current_user_can('manage_options')) return true;

    if (class_exists('SD_Module_RolesCaps', false)) {
      return current_user_can(SD_Module_RolesCaps::CAP_MANAGE_TENANT)
        || current_user_can(SD_Module_RolesCaps::CAP_DISPATCH)
        || current_user_can(SD_Module_RolesCaps::CAP_DRIVER);
    }

    return is_user_logged_in();
  }

  private static function current_tab() : string {
    $allowed = ['drive', 'home', 'storefront', 'pricing', 'base_location', 'profile'];
    $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'drive';
    return in_array($tab, $allowed, true) ? $tab : 'drive';
  }

  private static function base_url() : string {
    $page_url = self::current_page_permalink();
    if ($page_url !== '') {
      return $page_url;
    }

    return home_url('/operator/');
  }

  private static function current_page_permalink() : string {
    $post = get_post();
    if ($post instanceof WP_Post) {
      $url = get_permalink($post);
      if (is_string($url) && $url !== '') {
        return $url;
      }
    }

    return '';
  }

  private static function current_request_url() : string {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
    return home_url($uri);
  }

  private static function current_request_path() : string {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
    $path = wp_parse_url($uri, PHP_URL_PATH);
    return is_string($path) && $path !== '' ? $path : '/';
  }

  private static function section_label(string $section) : string {
    $map = [
      'drive'         => 'Drive',
      'home'          => 'Home',
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

  private static function fallback_login_card(string $redirect_url) : string {
    ob_start();

    echo '<div class="sd-surface sd-surface--wide sd-operator-app tenant-operator-app">';
    self::render_styles();
    echo '<div class="sd-operator-login-wrap">';
    echo '<div class="sd-operator-section-card" style="max-width:520px;margin:40px auto;">';
    echo '<div class="sd-operator-section-top">';
    echo '<div>';
    echo '<h1 class="sd-operator-section-title">Operator Login</h1>';
    echo '<div class="sd-operator-section-desc">Sign in to access your operator app.</div>';
    echo '</div>';
    echo '</div>';

    wp_login_form([
      'echo'           => true,
      'remember'       => true,
      'redirect'       => $redirect_url,
      'label_username' => 'Email or Username',
      'label_password' => 'Password',
    ]);

    echo '</div>';
    echo '</div>';
    echo '</div>';

    return (string) ob_get_clean();
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
      .sd-operator-app{max-width:1160px;margin:0 auto;padding:18px 16px 32px}
      .sd-operator-app-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}
      .sd-operator-app-head-main{min-width:0}
      .sd-operator-app-head-side{display:flex;align-items:center}
      .sd-operator-kicker{font-size:12px;font-weight:700;letter-spacing:.08em;color:#5a667d;margin-bottom:4px}
      .sd-operator-title{font-size:42px;line-height:1.04;margin:0 0 6px;color:#17233d}
      .sd-operator-sub{font-size:18px;color:#5a667d}
      .sd-operator-badge{display:inline-block;padding:8px 14px;border-radius:999px;font-size:13px;font-weight:700}
      .sd-operator-badge--ready{background:#edf9f0;color:#166534}
      .sd-operator-badge--warn{background:#fff7e6;color:#92400e}
      .sd-operator-tabs{display:flex;gap:10px;overflow:auto;padding:2px 0 14px;margin-bottom:14px}
      .sd-operator-tab{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 16px;border-radius:999px;border:1px solid #d6dde9;background:#fff;color:#17233d;text-decoration:none;font-weight:700;white-space:nowrap}
      .sd-operator-tab.is-active{background:#17233d;color:#fff;border-color:#17233d}
      .sd-operator-app-body{min-width:0}
      .sd-operator-readiness{margin:0 0 16px;max-width:720px;background:#fff;border:1px solid #ead7b8;border-radius:18px;padding:16px 18px}
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
      .sd-operator-grid{display:grid;grid-template-columns:1fr;gap:14px}
      .sd-operator-card{background:#fff;border:1px solid #dce3ef;border-radius:22px;min-height:140px}
      .sd-operator-card-link{display:block;padding:20px;color:inherit;text-decoration:none;height:100%}
      .sd-operator-card-title{font-size:22px;font-weight:800;color:#17233d;margin-bottom:8px}
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
      .sd-operator-form-grid{display:grid;grid-template-columns:1fr;gap:16px}
      .sd-operator-field{display:flex;flex-direction:column;gap:6px}
      .sd-operator-field--full{grid-column:1 / -1}
      .sd-operator-label{font-size:13px;font-weight:700;color:#17233d}
      .sd-operator-help{font-size:12px;color:#6b7280}
      .sd-operator-error{font-size:12px;color:#b91c1c;font-weight:700}
      .sd-operator-field input[type=text],
      .sd-operator-field input[type=number],
      .sd-operator-field select,
      .sd-operator-field textarea{width:100%;border:1px solid #cfd8e6;border-radius:14px;padding:12px 14px;font-size:16px;box-sizing:border-box}
      .sd-operator-field textarea{min-height:110px;resize:vertical}
      .sd-operator-check{display:flex;align-items:center;gap:10px;margin-top:6px}
      .sd-operator-actions-row{display:flex;gap:12px;align-items:center;margin-top:22px;flex-wrap:wrap}
      .sd-operator-btn{display:inline-block;border:1px solid #17233d;background:#17233d;color:#fff;text-decoration:none;padding:12px 18px;border-radius:999px;font-weight:700;cursor:pointer}
      .sd-operator-btn--ghost{background:#fff;color:#17233d}
      @media (min-width: 760px){
        .sd-operator-app{padding:24px 20px 40px}
        .sd-operator-title{font-size:52px}
        .sd-operator-sub{font-size:24px}
        .sd-operator-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
        .sd-operator-form-grid{grid-template-columns:1fr 1fr;gap:16px 20px}
      }
      @media (min-width: 1080px){
        .sd-operator-grid{grid-template-columns:repeat(4,minmax(0,1fr))}
      }
    </style>';
  }
}