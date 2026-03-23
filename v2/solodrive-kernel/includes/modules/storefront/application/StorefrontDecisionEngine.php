<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontDecisionEngine {

  public static function decide(
    SD_StorefrontPolicy $policy,
    SD_StorefrontOperationalContext $ctx
  ) : SD_StorefrontDecision {

    $tenant_id = $policy->tenant_id;

    // 1) Storefront disabled
    if (!$policy->storefront_enabled) {
      return new SD_StorefrontDecision(
        $tenant_id,
        'closed',
        SD_StorefrontAvailabilityMode::UNAVAILABLE,
        SD_StorefrontReasonCode::TENANT_DISABLED,
        $policy->closed_headline ?: 'Unavailable',
        'This storefront is currently unavailable.',
        '',
        '',
        ''
      );
    }

    // 2) Manual override
    if ($ctx->manual_override === 'closed') {
      return new SD_StorefrontDecision(
        $tenant_id,
        'closed',
        SD_StorefrontAvailabilityMode::UNAVAILABLE,
        SD_StorefrontReasonCode::MANUAL_CLOSED,
        $policy->closed_headline ?: 'Currently closed',
        $policy->resume_message,
        '',
        '',
        ''
      );
    }

    if ($ctx->manual_override === 'busy') {
      if ($policy->waitlist_enabled) {
        return new SD_StorefrontDecision(
          $tenant_id,
          'busy',
          SD_StorefrontAvailabilityMode::WAITLIST,
          SD_StorefrontReasonCode::MANUAL_BUSY,
          $policy->busy_headline ?: 'We are busy right now',
          'Join the waitlist and we will follow up as soon as capacity opens.',
          'JOIN_WAITLIST',
          $policy->reservations_enabled ? 'RESERVE' : '',
          $policy->workflow_for_mode(SD_StorefrontAvailabilityMode::WAITLIST)
        );
      }

      if ($policy->reservations_enabled) {
        return new SD_StorefrontDecision(
          $tenant_id,
          'busy',
          SD_StorefrontAvailabilityMode::RESERVE_ONLY,
          SD_StorefrontReasonCode::MANUAL_BUSY,
          $policy->busy_headline ?: 'We are busy right now',
          'On-demand booking is temporarily limited. Reservations are still available.',
          'RESERVE',
          '',
          $policy->workflow_for_mode(SD_StorefrontAvailabilityMode::RESERVE_ONLY)
        );
      }

      return new SD_StorefrontDecision(
        $tenant_id,
        'busy',
        SD_StorefrontAvailabilityMode::UNAVAILABLE,
        SD_StorefrontReasonCode::MANUAL_BUSY,
        $policy->busy_headline ?: 'We are busy right now',
        'Please check back soon.',
        '',
        '',
        ''
      );
    }

    // 3) Outside service hours
    if (!$ctx->within_service_hours) {
      if ($policy->reservations_enabled) {
        return new SD_StorefrontDecision(
          $tenant_id,
          'closed',
          SD_StorefrontAvailabilityMode::RESERVE_ONLY,
          SD_StorefrontReasonCode::CLOSED_HOURS,
          $policy->closed_headline ?: 'We are currently closed',
          'Reservations are available.',
          'RESERVE',
          '',
          $policy->workflow_for_mode(SD_StorefrontAvailabilityMode::RESERVE_ONLY)
        );
      }

      return new SD_StorefrontDecision(
        $tenant_id,
        'closed',
        SD_StorefrontAvailabilityMode::UNAVAILABLE,
        SD_StorefrontReasonCode::CLOSED_HOURS,
        $policy->closed_headline ?: 'We are currently closed',
        'Please check back during service hours.',
        '',
        '',
        ''
      );
    }

    // 4) No drivers online
    if ($ctx->online_drivers < 1) {
      if ($policy->auto_close_if_no_drivers && !$policy->reservations_enabled) {
        return new SD_StorefrontDecision(
          $tenant_id,
          'closed',
          SD_StorefrontAvailabilityMode::UNAVAILABLE,
          SD_StorefrontReasonCode::NO_DRIVERS_ONLINE,
          $policy->closed_headline ?: 'No drivers online',
          $policy->no_driver_message,
          '',
          '',
          ''
        );
      }

      if ($policy->reservations_enabled) {
        return new SD_StorefrontDecision(
          $tenant_id,
          'closed',
          SD_StorefrontAvailabilityMode::RESERVE_ONLY,
          SD_StorefrontReasonCode::NO_DRIVERS_ONLINE,
          $policy->closed_headline ?: 'No drivers online',
          $policy->no_driver_message,
          'RESERVE',
          '',
          $policy->workflow_for_mode(SD_StorefrontAvailabilityMode::RESERVE_ONLY)
        );
      }

      return new SD_StorefrontDecision(
        $tenant_id,
        'closed',
        SD_StorefrontAvailabilityMode::UNAVAILABLE,
        SD_StorefrontReasonCode::NO_DRIVERS_ONLINE,
        $policy->closed_headline ?: 'No drivers online',
        $policy->no_driver_message,
        '',
        '',
        ''
      );
    }

    // 5) Instant capacity
    if ($policy->on_demand_enabled && $ctx->instant_capacity_remaining > 0) {
      return new SD_StorefrontDecision(
        $tenant_id,
        'open',
        SD_StorefrontAvailabilityMode::INSTANT,
        SD_StorefrontReasonCode::OPEN_INSTANT,
        $policy->open_headline ?: 'Book your ride',
        'A driver is available now.',
        'BOOK_NOW',
        '',
        $policy->workflow_for_mode(SD_StorefrontAvailabilityMode::INSTANT)
      );
    }

    // 6) Stack capacity
    if ($policy->stacked_enabled && $ctx->stack_slots_remaining > 0) {
      return new SD_StorefrontDecision(
        $tenant_id,
        'busy',
        SD_StorefrontAvailabilityMode::STACKED_ASAP,
        SD_StorefrontReasonCode::OPEN_STACK_ONLY,
        $policy->busy_headline ?: 'Next available ride',
        'A driver is on an active trip but accepting the next ride.',
        'BOOK_ASAP',
        $policy->reservations_enabled ? 'RESERVE' : '',
        $policy->workflow_for_mode(SD_StorefrontAvailabilityMode::STACKED_ASAP)
      );
    }

    // 7) Waitlist
    if ($policy->waitlist_enabled && self::waitlist_has_space($policy, $ctx)) {
      return new SD_StorefrontDecision(
        $tenant_id,
        'busy',
        SD_StorefrontAvailabilityMode::WAITLIST,
        SD_StorefrontReasonCode::CAPACITY_REACHED_WAITLIST_OPEN,
        $policy->busy_headline ?: 'We are busy right now',
        'Join the waitlist and we will follow up when capacity opens.',
        'JOIN_WAITLIST',
        $policy->reservations_enabled ? 'RESERVE' : '',
        $policy->workflow_for_mode(SD_StorefrontAvailabilityMode::WAITLIST)
      );
    }

    // 8) Reservations
    if ($policy->reservations_enabled) {
      return new SD_StorefrontDecision(
        $tenant_id,
        'busy',
        SD_StorefrontAvailabilityMode::RESERVE_ONLY,
        SD_StorefrontReasonCode::CAPACITY_REACHED_RESERVE_ONLY,
        $policy->busy_headline ?: 'We are busy right now',
        'On-demand booking is unavailable, but reservations are open.',
        'RESERVE',
        '',
        $policy->workflow_for_mode(SD_StorefrontAvailabilityMode::RESERVE_ONLY)
      );
    }

    // 9) Unavailable
    return new SD_StorefrontDecision(
      $tenant_id,
      'closed',
      SD_StorefrontAvailabilityMode::UNAVAILABLE,
      SD_StorefrontReasonCode::CAPACITY_REACHED_RESERVE_ONLY,
      $policy->closed_headline ?: 'Unavailable',
      'No rides are currently being accepted.',
      '',
      '',
      ''
    );
  }

  private static function waitlist_has_space(
    SD_StorefrontPolicy $policy,
    SD_StorefrontOperationalContext $ctx
  ) : bool {
    if ($policy->waitlist_limit <= 0) return true;
    return $ctx->waitlist_count < $policy->waitlist_limit;
  }
}