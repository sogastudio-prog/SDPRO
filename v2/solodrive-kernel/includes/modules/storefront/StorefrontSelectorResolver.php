<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontSelectorResolver {

  public static function resolve(
    string $store_state,
    array $bindings,
    ?string $selected_mode = null
  ) : array {

    $store_state   = strtolower($store_state);
    $selected_mode = self::sanitize_mode($selected_mode);

    $allowed    = self::allowed_modes_for_state($store_state);
    $left_mode  = self::default_left_mode($store_state);
    $right_mode = self::right_mode($store_state);

    // Resolve selected mode
    $resolved_mode = in_array($selected_mode, $allowed, true)
      ? $selected_mode
      : $left_mode;

    // Map to form key
    $form_key = self::form_key_for_mode($resolved_mode);

    // Resolve form id
    $form_id = (int) ($bindings[$form_key]['form_id'] ?? 0);

    // Fallback if missing
    if ($form_id < 1) {
      foreach ($allowed as $fallback_mode) {
        $fk = self::form_key_for_mode($fallback_mode);
        $fid = (int) ($bindings[$fk]['form_id'] ?? 0);
        if ($fid > 0) {
          $resolved_mode = $fallback_mode;
          $form_key = $fk;
          $form_id = $fid;
          break;
        }
      }
    }

    return [
      'store_state'       => $store_state,
      'left_mode'         => $left_mode,
      'right_mode'        => $right_mode,
      'status_pill'       => $right_mode ? null : strtoupper($store_state),
      'selected_mode'     => $selected_mode,
      'resolved_mode'     => $resolved_mode,
      'resolved_form_key' => $form_key,
      'resolved_form_id'  => $form_id,
    ];
  }

  // ---------------------------------------------------------------------------

  private static function sanitize_mode(?string $mode) : string {
    $mode = strtolower((string) $mode);
    return in_array($mode, ['asap', 'waitlist', 'reserve'], true) ? $mode : '';
  }

  private static function allowed_modes_for_state(string $store_state) : array {
    switch ($store_state) {
      case 'open':
        return ['asap', 'reserve'];
      case 'busy':
        return ['waitlist', 'reserve'];
      case 'closed':
      default:
        return ['reserve'];
    }
  }

  private static function default_left_mode(string $store_state) : string {
    switch ($store_state) {
      case 'open':
        return 'asap';
      case 'busy':
        return 'waitlist';
      case 'closed':
      default:
        return 'reserve';
    }
  }

  private static function right_mode(string $store_state) : ?string {
    switch ($store_state) {
      case 'open':
      case 'busy':
        return 'reserve';
      case 'closed':
      default:
        return null;
    }
  }

  private static function form_key_for_mode(string $mode) : string {
    switch ($mode) {
      case 'waitlist':
        return 'cf7.waitlist';
      case 'reserve':
        return 'cf7.reservation';
      case 'asap':
      default:
        return 'cf7.instant';
    }
  }
}