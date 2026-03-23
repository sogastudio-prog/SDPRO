<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontOperationalContextService {

  public static function resolve(int $tenant_id, SD_StorefrontPolicy $policy) : SD_StorefrontOperationalContext {
    $ctx = new SD_StorefrontOperationalContext($tenant_id, time());

    $ctx->manual_override = (string) $policy->manual_mode;

    $hours = SD_StorefrontHoursEvaluator::status_at($policy, $ctx->current_ts);
    $ctx->within_service_hours = !empty($hours['is_open']);

    $freshness_seconds = (int) apply_filters('sd/storefront/driver_freshness_seconds', 180, $tenant_id);

    $capacity = SD_RideCapacityGateway::get_capacity_snapshot($tenant_id, $policy, $freshness_seconds);

    $ctx->online_drivers             = (int) $capacity['online_driver_count'];
    $ctx->active_rides               = (int) $capacity['active_rides'];
    $ctx->instant_capacity_remaining = (int) $capacity['instant_capacity_remaining'];
    $ctx->stack_slots_remaining      = (int) $capacity['stack_slots_remaining'];
    $ctx->waitlist_count             = SD_WaitlistGateway::count_open_entries($tenant_id);

    return $ctx;
  }
}