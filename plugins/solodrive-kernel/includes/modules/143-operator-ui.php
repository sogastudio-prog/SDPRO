<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_OperatorUI (v0.2)
 *
 * Purpose:
 * - Shared UI helpers for private operator surfaces
 */

if (class_exists('SD_Module_OperatorUI', false)) { return; }

final class SD_Module_OperatorUI {

  public static function render_shell(string $title, string $body_html) : void {
    echo '<!doctype html><html><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . esc_html($title) . '</title>';
    wp_head();
    echo self::styles();
    echo '</head><body>';
    echo $body_html;
    wp_footer();
    echo '</body></html>';
  }

  public static function render_login_screen(string $title, string $redirect_path, string $heading = 'Operator Login', string $sub = 'Sign in to access your tenant workspace.') : void {
    status_header(200);

    $html  = '<div class="sd-op-login-wrap">';
    $html .= '  <div class="sd-op-login-card">';
    $html .= '    <div class="sd-op-eyebrow">SoloDrive</div>';
    $html .= '    <h1 class="sd-op-h1">' . esc_html($heading) . '</h1>';
    $html .= '    <div class="sd-op-sub">' . esc_html($sub) . '</div>';
    $html .= '    <div class="sd-op-login-form">';

    ob_start();
    wp_login_form([
      'echo'           => true,
      'remember'       => true,
      'redirect'       => home_url($redirect_path),
      'label_username' => 'Email or Username',
      'label_password' => 'Password',
      'label_log_in'   => 'Sign In',
    ]);
    $html .= ob_get_clean();

    $html .= '    </div>';
    $html .= '  </div>';
    $html .= '</div>';

    self::render_shell($title, $html);
  }

  public static function notice_card(string $title, string $message, string $wrap_class = 'sd-op-wrap') : string {
    return
      '<div class="' . esc_attr($wrap_class) . '">' .
        '<div class="sd-op-card">' .
          '<h2>' . esc_html($title) . '</h2>' .
          '<p>' . esc_html($message) . '</p>' .
        '</div>' .
      '</div>';
  }

  public static function render_operator_status_strip() : string {
    ob_start(); ?>
    <div class="sd-operator-status-strip" id="sd-operator-status-strip">
      <div class="sd-operator-status-item" id="sd-status-online">Offline</div>
      <div class="sd-operator-status-item" id="sd-status-alerts">Alerts unknown</div>
      <div class="sd-operator-status-item" id="sd-status-location">Location unknown</div>
      <div class="sd-operator-status-item" id="sd-status-install">Install unknown</div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  public static function pretty_enum(string $value) : string {
    if ($value === '') return '';
    return ucwords(str_replace('_', ' ', $value));
  }

  public static function human_time(int $ts) : string {
    if ($ts <= 0) return '—';
    return human_time_diff($ts, time()) . ' ago';
  }

  public static function format_last_known_loc(float $lat, float $lng) : string {
    if (abs($lat) < 0.0001 || abs($lng) < 0.0001) {
      return '—';
    }

    return number_format($lat, 4) . ', ' . number_format($lng, 4);
  }

  public static function format_money(int $amount_cents, string $currency = 'usd') : string {
    if ($amount_cents <= 0) {
      return '— ' . strtoupper($currency !== '' ? $currency : 'usd');
    }

    return '$' . number_format($amount_cents / 100, 2) . ' ' . strtoupper($currency !== '' ? $currency : 'usd');
  }

  public static function display_state_label(string $state) : string {
    $map = [
      'LEAD_WAITING_QUOTE' => 'Needs quote',
      'PROPOSED'           => 'Needs quote',
      'APPROVED'           => 'Needs quote',
      'PRESENTED'          => 'Waiting on rider',
      'LEAD_ACCEPTED'      => 'Opening authorization',
      'PAYMENT_PENDING'    => 'Authorized',
      'RIDE_QUEUED'        => 'Queued',
      'RIDE_DEADHEAD'      => 'En route to pickup',
      'RIDE_ARRIVED'       => 'Arrived at destination',
      'RIDE_WAITING'       => 'Waiting at pickup',
      'RIDE_INPROGRESS'    => 'Trip in progress',
      'RIDE_COMPLETE'      => 'Completed',
      'RIDE_CANCELLED'     => 'Cancelled',
      'authorized'         => 'Authorized',
      'AUTHORIZED'         => 'Authorized',
      'CAPTURE_PENDING'    => 'Capture pending',
      'CAPTURED'           => 'Captured',
      'CAPTURE_FAILED'     => 'Capture failed',
      'open'               => 'Open',
      'busy'               => 'Busy',
      'closed'             => 'Closed',
      'online'             => 'Online',
      'offline'            => 'Offline',
      'paused'             => 'Paused',
    ];

    return $map[$state] ?? ($state !== '' ? self::pretty_enum($state) : '—');
  }

  public static function styles() : string {
    static $css = null;
    if ($css !== null) {
      return $css;
    }

    $css = <<<HTML
<style>
  :root{
    --sd-bg:#f6f7fb;
    --sd-card:#ffffff;
    --sd-text:#0f172a;
    --sd-sub:#475569;
    --sd-line:#e2e8f0;
    --sd-accent:#111827;
    --sd-alert:#dc2626;
    --sd-alert-bg:#fef2f2;
    --sd-ok:#166534;
    --sd-ok-bg:#edf9f0;
    --sd-warn:#92400e;
    --sd-warn-bg:#fff7e6;
  }
  *{box-sizing:border-box}
  body{
    margin:0;
    background:var(--sd-bg);
    color:var(--sd-text);
    font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif
  }
  .sd-op-wrap{max-width:1080px;margin:0 auto;padding:20px}
  .sd-op-wrap--trips{max-width:900px}
  .sd-op-topbar{
    display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px
  }
  .sd-op-topbar--trips{
    position:sticky;top:0;background:var(--sd-bg);padding-top:8px;z-index:2
  }
  .sd-op-eyebrow{
    font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--sd-sub)
  }
  .sd-op-h1{margin:4px 0 6px;font-size:28px;line-height:1.05}
  .sd-op-sub{color:var(--sd-sub);font-size:14px}
  .sd-op-grid{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px
  }
  .sd-op-card{
    background:var(--sd-card);border:1px solid var(--sd-line);border-radius:18px;padding:16px
  }
  .sd-op-menu-card{text-decoration:none;color:inherit}
  .sd-op-menu-title{font-weight:800;margin-bottom:6px}
  .sd-op-actions,
  .sd-op-header-controls,
  .sd-op-cta-row,
  .sd-op-toggles,
  .sd-op-pwa-actions{
    display:flex;gap:10px;align-items:center;flex-wrap:wrap
  }
  .sd-op-pwa-actions{margin:0 0 12px}
  .sd-op-btn,
  .sd-op-toggle,
  .sd-op-pill{
    display:inline-flex;align-items:center;justify-content:center;
    min-height:42px;padding:0 14px;border-radius:999px;border:1px solid var(--sd-line);
    background:#fff;color:var(--sd-text);text-decoration:none;font-weight:700;cursor:pointer
  }
  .sd-op-btn-primary,
  .sd-op-toggle.is-active{
    background:var(--sd-accent);border-color:var(--sd-accent);color:#fff
  }
  .sd-op-badge{
    margin-left:8px;padding:3px 8px;border-radius:999px;background:rgba(255,255,255,.15);font-size:12px
  }
  .sd-op-badge--ready{
    background:var(--sd-ok-bg);color:var(--sd-ok)
  }
  .sd-op-badge--warn{
    background:var(--sd-warn-bg);color:var(--sd-warn)
  }
  .sd-op-strip{
    display:flex;gap:18px;flex-wrap:wrap;margin:0 0 12px;color:var(--sd-sub);font-size:14px
  }
  .sd-op-lower{margin-top:14px}
  .sd-op-card-head{margin-bottom:12px}
  .sd-op-card-head h2{margin:0 0 4px;font-size:20px}
  .sd-op-queue{display:flex;flex-direction:column;gap:10px}
  .sd-op-queue-row{
    display:flex;justify-content:space-between;gap:14px;align-items:flex-start;
    padding:14px;border:1px solid var(--sd-line);border-radius:14px;text-decoration:none;color:inherit;background:#fff
  }
  .sd-op-queue-row.is-selected{border-color:#94a3b8;background:#f8fafc}
  .sd-op-queue-row.is-alert{border-color:#fecaca;background:var(--sd-alert-bg)}
  .sd-op-queue-title{font-weight:800;margin-bottom:4px}
  .sd-op-queue-route{color:var(--sd-sub);font-size:14px}
  .sd-op-queue-meta{
    font-size:13px;color:var(--sd-sub);text-align:right;display:flex;flex-direction:column;gap:4px
  }
  .sd-op-active-head{
    display:flex;flex-direction:column;gap:8px;padding:14px;border:1px solid var(--sd-line);border-radius:14px;background:#fff;margin-bottom:12px
  }
  .sd-op-active-line{font-size:14px}
  .sd-op-state-box{
    padding:16px;border:1px solid var(--sd-line);border-radius:14px;background:#fff
  }
  .sd-op-state-box.is-alert{background:var(--sd-alert-bg);border-color:#fecaca}
  .sd-op-state-box h3{margin:0 0 8px;font-size:18px}
  .sd-op-login-wrap{
    min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px
  }
  .sd-op-login-card{
    width:min(100%,420px);background:#fff;border:1px solid var(--sd-line);border-radius:22px;padding:24px
  }
  .sd-op-login-form form{
    display:flex;flex-direction:column;gap:12px;margin-top:16px
  }
  .sd-op-login-form label{
    display:block;font-size:14px;font-weight:700;margin-bottom:6px
  }
  .sd-op-login-form input[type="text"],
  .sd-op-login-form input[type="password"]{
    width:100%;min-height:44px;padding:10px 12px;border-radius:12px;border:1px solid var(--sd-line)
  }
  .sd-op-login-form input[type="submit"]{
    min-height:44px;border-radius:999px;border:0;background:var(--sd-accent);color:#fff;font-weight:800;padding:0 16px
  }
  .sd-op-toggle.is-alert{
    border-color:var(--sd-alert);
    color:#fff;
    background:var(--sd-alert);
    animation:sdQueueFlash 1.15s ease-in-out infinite;
  }
  .sd-op-pill.is-online{
    border-color:#86efac;
    background:#f0fdf4;
  }
  .sd-operator-status-strip{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:10px;
    margin:0 0 12px;
  }
  .sd-operator-status-item{
    background:#fff;
    border:1px solid var(--sd-line);
    border-radius:14px;
    padding:10px 12px;
    font-size:13px;
    font-weight:700;
    color:var(--sd-sub);
  }
  @keyframes sdQueueFlash{
    0%,100%{transform:scale(1); box-shadow:0 0 0 0 rgba(220,38,38,.35)}
    50%{transform:scale(1.03); box-shadow:0 0 0 8px rgba(220,38,38,0)}
  }
  @media (max-width: 720px){
    .sd-op-topbar,.sd-op-queue-row{flex-direction:column}
    .sd-op-queue-meta{text-align:left}
    .sd-op-h1{font-size:24px}
    .sd-op-wrap--trips{padding:14px}
    .sd-operator-status-strip{grid-template-columns:1fr}
  }
</style>
HTML;

    return $css;
  }
}