<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Deprecated by lead-root timeblock model.
 *
 * Canon now:
 * - holds are created against sd_lead
 * - commits happen on promotion/auth success
 * - rides do not create their own supply blocks
 */
final class SD_Module_RideTimeBlockSync {

  public static function register() : void {
    // Intentionally no-op.
  }
}