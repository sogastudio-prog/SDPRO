<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontRenderer {

  /**
   * Render storefront shell from SD_StorefrontGate::evaluate() result.
   *
   * Expected $gate keys:
   * - state
   * - can_render_storefront
   * - can_render_request_form
   * - can_request_quote
   * - can_request_booking
   * - message
   * - reason_code
   */
  public static function render(array $gate, string $workflow_html = '') : string {
    $state                   = isset($gate['state']) ? (string) $gate['state'] : 'closed';
    $can_render_storefront   = !empty($gate['can_render_storefront']);
    $can_render_request_form = !empty($gate['can_render_request_form']);
    $can_request_quote       = !empty($gate['can_request_quote']);
    $can_request_booking     = !empty($gate['can_request_booking']);
    $message                 = isset($gate['message']) ? (string) $gate['message'] : '';
    $reason_code             = isset($gate['reason_code']) ? (string) $gate['reason_code'] : 'ok';

    if (!$can_render_storefront) {
      return self::render_unavailable($state, $message !== '' ? $message : 'Storefront unavailable.');
    }

    $headline      = self::headline($state, $reason_code, $can_render_request_form, $can_request_quote, $can_request_booking);
    $primary_cta   = self::primary_cta($can_render_request_form, $can_request_quote, $can_request_booking);
    $secondary_cta = self::secondary_cta($can_render_request_form, $can_request_quote, $can_request_booking);

    ob_start();
    ?>
    <section class="sd-storefront sd-storefront--<?php echo esc_attr($state); ?>">
      <div class="sd-card tenant-storefront-card">
        <div class="sd-storefront__header">
          <span class="sd-badge sd-badge--<?php echo esc_attr($state); ?>">
            <?php echo esc_html(strtoupper($state)); ?>
          </span>

          <h1 class="sd-h1"><?php echo esc_html($headline); ?></h1>

          <?php if ($message !== '') : ?>
            <p class="sd-sub"><?php echo esc_html($message); ?></p>
          <?php endif; ?>
        </div>

        <?php if ($primary_cta !== '' || $secondary_cta !== '') : ?>
          <div class="sd-storefront__actions">
            <?php if ($primary_cta !== '') : ?>
              <button type="button" class="sd-btn sd-btn-primary">
                <?php echo esc_html($primary_cta); ?>
              </button>
            <?php endif; ?>

            <?php if ($secondary_cta !== '') : ?>
              <button type="button" class="sd-btn sd-btn-secondary">
                <?php echo esc_html($secondary_cta); ?>
              </button>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($can_render_request_form && $workflow_html !== '') : ?>
          <div class="sd-storefront__workflow">
            <?php echo $workflow_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
    <?php
    return (string) ob_get_clean();
  }

  private static function render_unavailable(string $state, string $message) : string {
    ob_start();
    ?>
    <section class="sd-storefront sd-storefront--<?php echo esc_attr($state !== '' ? $state : 'closed'); ?>">
      <div class="sd-card tenant-storefront-card">
        <div class="sd-storefront__header">
          <span class="sd-badge sd-badge--<?php echo esc_attr($state !== '' ? $state : 'closed'); ?>">
            <?php echo esc_html(strtoupper($state !== '' ? $state : 'closed')); ?>
          </span>
          <h1 class="sd-h1">Storefront unavailable</h1>
          <p class="sd-sub"><?php echo esc_html($message); ?></p>
        </div>
      </div>
    </section>
    <?php
    return (string) ob_get_clean();
  }

  private static function headline(
    string $state,
    string $reason_code,
    bool $can_render_request_form,
    bool $can_request_quote,
    bool $can_request_booking
  ) : string {
    if (!$can_render_request_form) {
      switch ($reason_code) {
        case 'tenant_not_ready':
          return 'Configuration incomplete';
        case 'storefront_closed':
          return 'Storefront closed';
        case 'storefront_busy':
          return 'Currently busy';
        case 'not_accepting_requests':
          return 'Requests unavailable';
        case 'outside_hours':
          return 'Outside operating hours';
        case 'storefront_disabled':
          return 'Storefront unavailable';
        default:
          return 'Storefront unavailable';
      }
    }

    if ($can_request_quote && $can_request_booking) {
      return 'Request a ride';
    }

    if ($can_request_quote) {
      return 'Request a quote';
    }

    if ($can_request_booking) {
      return 'Book your ride';
    }

    return ($state === 'open') ? 'Storefront open' : 'Storefront';
  }

  private static function primary_cta(bool $can_render_request_form, bool $can_request_quote, bool $can_request_booking) : string {
    if (!$can_render_request_form) {
      return '';
    }

    if ($can_request_quote && $can_request_booking) {
      return 'Start request';
    }

    if ($can_request_quote) {
      return 'Get quote';
    }

    if ($can_request_booking) {
      return 'Book now';
    }

    return '';
  }

  private static function secondary_cta(bool $can_render_request_form, bool $can_request_quote, bool $can_request_booking) : string {
    if (!$can_render_request_form) {
      return '';
    }

    if ($can_request_quote && $can_request_booking) {
      return 'View options';
    }

    return '';
  }
}