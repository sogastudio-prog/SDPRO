<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_StripeLifecycleListener (v2.1)
 *
 * Purpose:
 * - Canonical bridge from Stripe authorization into SoloDrive kernel lifecycle.
 *
 * Trigger:
 * - do_action('sd_stripe_authorized', $attempt_id, $quote_id, $ride_id)
 *
 * On authorization:
 * - Attempt lifecycle:
 *     -> AUTHORIZED
 * - Quote lifecycle:
 *     -> PAYMENT_PENDING
 * - Ride lead lifecycle:
 *     -> LEAD_PROMOTED
 * - Ride execution lifecycle:
 *     -> RIDE_QUEUED
 *
 * Canon:
 * - Stripe authorization, not trip page click, is what promotes the ride into
 *   dispatchable execution state.
 * - /trip/<token> remains a surface; this module updates kernel truth.
 * - Attempt-first payment correlation remains authoritative.
 *
 * Idempotency:
 * - Safe to receive duplicate sd_stripe_authorized events.
 * - Re-setting same quote/ride/attempt state should fail-soft.
 */

if (class_exists('SD_Module_StripeLifecycleListener', false)) { return; }

final class SD_Module_StripeLifecycleListener {

  public static function register() : void {
    add_action('sd_stripe_authorized', [__CLASS__, 'on_authorized'], 10, 3);
  }

  public static function on_authorized(int $attempt_id, int $quote_id, int $ride_id) : void {
    $attempt_id = (int) $attempt_id;
    $quote_id   = (int) $quote_id;
    $ride_id    = (int) $ride_id;

    if ($attempt_id <= 0) {
      return;
    }

    // Defensive linkage recovery from attempt
    if ($quote_id <= 0 && class_exists('SD_Module_AttemptService')) {
      $quote_id = (int) SD_Module_AttemptService::get_quote_id($attempt_id);
    }

    if ($ride_id <= 0 && class_exists('SD_Module_AttemptService')) {
      $ride_id = (int) SD_Module_AttemptService::get_ride_id($attempt_id);
    }

    // -----------------------------------------------------------------------
    // Attempt -> AUTHORIZED
    // -----------------------------------------------------------------------
    if (class_exists('SD_Module_AttemptService')) {
      SD_Module_AttemptService::set_status(
        $attempt_id,
        SD_Module_AttemptService::STATUS_AUTHORIZED,
        [
          'source'   => 'stripe_authorized',
          'quote_id' => $quote_id,
          'ride_id'  => $ride_id,
        ]
      );
    }

    // -----------------------------------------------------------------------
    // Quote -> PAYMENT_PENDING
    // -----------------------------------------------------------------------
    if (
      $quote_id > 0 &&
      class_exists('SD_Module_QuoteStateService') &&
      class_exists('SD_Quote_State')
    ) {
      SD_Module_QuoteStateService::set(
        $quote_id,
        SD_Quote_State::PAYMENT_PENDING,
        [
          'source'     => 'stripe_authorized',
          'attempt_id' => $attempt_id,
          'ride_id'    => $ride_id,
        ]
      );
    }

    // -----------------------------------------------------------------------
    // Ride lead + execution lifecycle
    // -----------------------------------------------------------------------
    if ($ride_id > 0) {

      // Canonical lead promotion
      if (class_exists('SD_Meta')) {
        if (class_exists('SD_Lead_Status') && defined('SD_Lead_Status::PROMOTED')) {
          update_post_meta($ride_id, SD_Meta::LEAD_STATUS, SD_Lead_Status::PROMOTED);
        } else {
          update_post_meta($ride_id, SD_Meta::LEAD_STATUS, 'LEAD_PROMOTED');
        }
      }

      // Persist authorized attempt pointer for later capture correlation
      update_post_meta($ride_id, '_sd_authorized_attempt_id', (string) $attempt_id);

      // Keep canonical quote linkage hot on the ride
      if ($quote_id > 0) {
        update_post_meta($ride_id, '_sd_latest_quote_id', (string) $quote_id);

        if (class_exists('SD_Meta')) {
          update_post_meta($ride_id, SD_Meta::QUOTE_ID, (string) $quote_id);
        }
      }

      // Authorization is what makes the ride dispatchable
      if (
        class_exists('SD_Module_RideStateService') &&
        class_exists('SD_Ride_State')
      ) {
        SD_Module_RideStateService::set(
          $ride_id,
          SD_Ride_State::QUEUED,
          [
            'source'     => 'stripe_authorized',
            'attempt_id' => $attempt_id,
            'quote_id'   => $quote_id,
          ]
        );
      } else {
        // Fail-soft fallback if state service is unavailable
        if (class_exists('SD_Meta')) {
          update_post_meta($ride_id, SD_Meta::RIDE_STATE, 'RIDE_QUEUED');
          update_post_meta($ride_id, SD_Meta::P_STATE_UPDATED_AT, time());
        }
      }
    }

    if (class_exists('SD_Util')) {
      SD_Util::log('stripe_lifecycle_applied', [
        'attempt_id' => $attempt_id,
        'quote_id'   => $quote_id,
        'ride_id'    => $ride_id,
      ]);
    }
  }
}

SD_Module_StripeLifecycleListener::register();