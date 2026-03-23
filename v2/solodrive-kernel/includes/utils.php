<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Util {

  public static function log(string $event, array $ctx = []) : void {
    // Minimal structured logging to PHP error_log.
    // No PII, no tokens.
    $payload = [
      'sd'    => true,
      'event' => $event,
      'ts'    => gmdate('c'),
      'ctx'   => $ctx,
    ];
    error_log('[solodrive] ' . wp_json_encode($payload));
  }

  public static function card(string $title, array $rows) : string {
    $out  = '<div style="max-width:760px;margin:16px auto;padding:16px;border:1px solid #ddd;border-radius:12px">';
    $out .= '<h2 style="margin:0 0 8px 0;font-size:18px">' . esc_html($title) . '</h2>';
    $out .= '<table style="width:100%;border-collapse:collapse">';
    foreach ($rows as $k => $v) {
      $out .= '<tr>';
      $out .= '<td style="padding:6px 8px;border-top:1px solid #eee;color:#444;width:220px"><strong>' . esc_html((string)$k) . '</strong></td>';
      $out .= '<td style="padding:6px 8px;border-top:1px solid #eee;color:#222">' . esc_html((string)$v) . '</td>';
      $out .= '</tr>';
    }
    $out .= '</table></div>';
    return $out;
  }
}