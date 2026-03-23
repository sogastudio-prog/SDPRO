<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_Storefront {

  private static ?SD_StorefrontWorkflowRegistry $registry = null;

  public static function register() : void {
    add_shortcode('sd_storefront', [__CLASS__, 'shortcode_storefront']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_assets']);
  }

  public static function registry() : SD_StorefrontWorkflowRegistry {
    if (!self::$registry) {
      self::$registry = new SD_StorefrontWorkflowRegistry();
      self::$registry->register(new SD_CF7WorkflowAdapter());
    }

    return self::$registry;
  }

  public static function shortcode_storefront($atts = []) : string {
    $atts = shortcode_atts([
      'tenant_id' => 0,
    ], $atts, 'sd_storefront');

    $tenant_id = (int) $atts['tenant_id'];
    if ($tenant_id < 1) {
      return '<div class="sd-card"><p>Missing tenant context.</p></div>';
    }

    $policy   = SD_TenantStorefrontPolicyRepository::get_policy($tenant_id);
    $context  = SD_StorefrontOperationalContextService::resolve($tenant_id, $policy);
    $decision = SD_StorefrontDecisionEngine::decide($policy, $context);

    // ---------------------------------------------------------------------------
// Determine store state (use decision/context — adjust if needed)
// ---------------------------------------------------------------------------
$store_state = strtolower($decision->store_state ?? 'open');

// Read query mode
$selected_mode = isset($_GET['mode']) ? sanitize_key($_GET['mode']) : null;

// Resolve selector model
$bindings = self::resolve_workflow_config($tenant_id, $decision);

$selector = SD_StorefrontSelectorResolver::resolve(
  $store_state,
  $bindings,
  $selected_mode
);

// Override workflow based on selector
$workflow_config = [
  'form_id' => $selector['resolved_form_id'],
];

// Render CF7 workflow
$workflow_html = self::registry()->render_workflow($decision, $workflow_config);

// Render selector row
$selector_html = self::render_selector_row($selector);

// Final render
return SD_StorefrontRenderer::render(
  $decision,
  $selector_html . $workflow_html
);
  }

  public static function maybe_enqueue_assets() : void {
    if (is_admin()) return;

    global $post;
    if (!$post || empty($post->post_content)) return;

    if (!has_shortcode((string) $post->post_content, 'sd_storefront')) {
      return;
    }

    $js_abs_path = dirname(dirname(dirname(__DIR__))) . '/assets/js/request-surface.js';
    $js_url      = plugins_url('assets/js/request-surface.js', dirname(dirname(dirname(__DIR__))) . '/solodrive-kernel.php');
    $ver         = file_exists($js_abs_path) ? (string) filemtime($js_abs_path) : '1.0.0';

    wp_enqueue_script(
      'sd-request-surface',
      $js_url,
      [],
      $ver,
      true
    );
  }
  
  private static function render_selector_row(array $selector) : string {

  $left  = $selector['left_mode'];
  $right = $selector['right_mode'];
  $active = $selector['resolved_mode'];

  $base_url = strtok($_SERVER['REQUEST_URI'], '?');

  $btn = function($mode, $label, $is_active) use ($base_url) {
    $url = esc_url(add_query_arg('mode', $mode, $base_url));
    $cls = 'sd-mode-btn' . ($is_active ? ' is-active' : '');
    return '<a href="' . $url . '" class="' . $cls . '">' . esc_html($label) . '</a>';
  };

  $html  = '<div class="sd-mode-row">';

  // Left button (always clickable)
  $html .= $btn($left, strtoupper($left), $active === $left);

  // Right side
  if ($right) {
    $html .= $btn($right, strtoupper($right), $active === $right);
  } else {
    $html .= '<div class="sd-status-pill">' . esc_html($selector['status_pill']) . '</div>';
  }

  $html .= '</div>';

  return $html;
}

  private static function resolve_workflow_config(int $tenant_id, SD_StorefrontDecision $decision) : array {
    $bindings = [
      'cf7.instant'     => ['form_id' => (int) get_post_meta($tenant_id, 'sd_cf7_instant_form_id', true)],
      'cf7.stacked'     => ['form_id' => (int) get_post_meta($tenant_id, 'sd_cf7_stacked_form_id', true)],
      'cf7.waitlist'    => ['form_id' => (int) get_post_meta($tenant_id, 'sd_cf7_waitlist_form_id', true)],
      'cf7.reservation' => ['form_id' => (int) get_post_meta($tenant_id, 'sd_cf7_reservation_form_id', true)],
    ];

    return $bindings[$decision->workflow_type] ?? [];
  }
}

SD_Module_Storefront::register();