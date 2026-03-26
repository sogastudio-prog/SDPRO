<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_StorefrontEntry
 *
 * Purpose:
 * - Provide the first real public SoloDrive storefront entry surface
 * - Bridge:
 *     tenant resolution
 *     storefront gate
 *     storefront renderer
 *     existing request workflow shortcode
 *
 * Shortcode:
 * - [sd_storefront]
 *
 * Recommended homepage usage:
 * - Replace the current [sd_request] hack with:
 *     [sd_storefront]
 *
 * Notes:
 * - This does NOT replace the request workflow yet
 * - It puts the storefront system in front of the workflow
 * - Workflow is injected only when the storefront gate allows it
 */

if (class_exists('SD_Module_StorefrontEntry', false)) { return; }

final class SD_Module_StorefrontEntry {

  public static function register() : void {
    add_shortcode('sd_storefront', [__CLASS__, 'shortcode']);
  }

  public static function shortcode($atts = []) : string {
    $tenant_id = self::resolve_tenant_id();

    if ($tenant_id <= 0) {
      return self::render_fallback_storefront('Storefront unavailable.', 'Tenant could not be resolved.');
    }

    if (!class_exists('SD_StorefrontGate', false)) {
      return self::render_fallback_storefront('Storefront unavailable.', 'Storefront gate is unavailable.');
    }

    if (!class_exists('SD_StorefrontRenderer', false)) {
      return self::render_fallback_storefront('Storefront unavailable.', 'Storefront renderer is unavailable.');
    }

    $gate = SD_StorefrontGate::evaluate($tenant_id);

    $workflow_html = '';
    if (!empty($gate['can_render_request_form'])) {
      $workflow_html = self::render_request_workflow($tenant_id, $gate);
    }

    return SD_StorefrontRenderer::render($gate, $workflow_html);
  }

  // ---------------------------------------------------------------------------
  // Tenant resolution
  // ---------------------------------------------------------------------------

  private static function resolve_tenant_id() : int {
    if (defined('SD_TENANT_ID') && (int) SD_TENANT_ID > 0) {
    return (int) SD_TENANT_ID;
    }

    if (!empty($GLOBALS['sd_tenant_id'])) {
    return (int) $GLOBALS['sd_tenant_id'];
    }

    if (class_exists('SD_Module_TenantResolver', false)) {
    return (int) SD_Module_TenantResolver::current_tenant_id();
    }

    return 0;
    }

  // ---------------------------------------------------------------------------
  // Workflow rendering
  // ---------------------------------------------------------------------------

  private static function render_request_workflow(int $tenant_id, array $gate) : string {
  if (!class_exists('SD_Module_StorefrontIntake', false)) {
    return '';
  }

  return SD_Module_StorefrontIntake::render($tenant_id, $gate);
}

  // ---------------------------------------------------------------------------
  // Fallback rendering
  // ---------------------------------------------------------------------------

  private static function render_fallback_storefront(string $headline, string $message) : string {
    $gate = [
      'state'                   => 'closed',
      'can_render_storefront'   => true,
      'can_render_request_form' => false,
      'can_request_quote'       => false,
      'can_request_booking'     => false,
      'message'                 => $message,
      'reason_code'             => 'storefront_unavailable',
      'is_open_now'             => false,
      'hours_mode'              => 'always_on',
      'timezone'                => '',
    ];

    if (class_exists('SD_StorefrontRenderer', false)) {
      return SD_StorefrontRenderer::render($gate, '');
    }

    ob_start();
    ?>
    <section class="sd-storefront sd-storefront--closed">
      <div class="sd-card tenant-storefront-card">
        <div class="sd-storefront__header">
          <span class="sd-badge sd-badge--closed">CLOSED</span>
          <h1 class="sd-h1"><?php echo esc_html($headline); ?></h1>
          <p class="sd-sub"><?php echo esc_html($message); ?></p>
        </div>
      </div>
    </section>
    <?php
    return (string) ob_get_clean();
  }
}