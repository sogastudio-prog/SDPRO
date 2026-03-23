<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StripeCustomerService {

  public static function get_or_create_for_ride(int $ride_id) : array {
    $ride_id = absint($ride_id);
    if ($ride_id <= 0) {
      return ['ok' => false, 'error' => 'invalid_ride_id'];
    }

    $existing = (string) get_post_meta($ride_id, 'sd_stripe_customer_id', true);
    if ($existing !== '') {
      return ['ok' => true, 'customer_id' => $existing, 'created' => false];
    }

    if (!class_exists('\\Stripe\\Customer')) {
      self::log('stripe_customer_missing_library', ['ride_id' => $ride_id]);
      return ['ok' => false, 'error' => 'stripe_library_missing'];
    }

    $tenant_id = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    $name      = (string) get_post_meta($ride_id, SD_Meta::CUSTOMER_NAME, true);
    $phone     = (string) get_post_meta($ride_id, SD_Meta::CUSTOMER_PHONE, true);

    try {
      self::bootstrap_stripe($tenant_id);

      $customer = \Stripe\Customer::create([
        'name'     => $name !== '' ? $name : null,
        'phone'    => $phone !== '' ? $phone : null,
        'metadata' => [
          'sd_ride_id'   => (string) $ride_id,
          'sd_tenant_id' => (string) $tenant_id,
        ],
      ]);

      $customer_id = (string) ($customer->id ?? '');
      if ($customer_id === '') {
        return ['ok' => false, 'error' => 'customer_create_failed'];
      }

      update_post_meta($ride_id, 'sd_stripe_customer_id', $customer_id);

      self::log('stripe_customer_created', [
        'ride_id'      => $ride_id,
        'tenant_id'    => $tenant_id,
        'customer_id'  => $customer_id,
      ]);

      return ['ok' => true, 'customer_id' => $customer_id, 'created' => true];
    } catch (\Throwable $e) {
      self::log('stripe_customer_create_exception', [
        'ride_id' => $ride_id,
        'error'   => $e->getMessage(),
      ]);
      return ['ok' => false, 'error' => 'customer_create_exception'];
    }
  }

  private static function bootstrap_stripe(int $tenant_id) : void {
    if (class_exists('SD_Stripe')) {
      SD_Stripe::bootstrap_for_tenant($tenant_id);
      return;
    }

    if (class_exists('SD_Module_Stripe')) {
      SD_Module_Stripe::bootstrap_for_tenant($tenant_id);
      return;
    }

    if (defined('SD_STRIPE_SECRET_KEY') && SD_STRIPE_SECRET_KEY) {
      \Stripe\Stripe::setApiKey(SD_STRIPE_SECRET_KEY);
      return;
    }

    throw new \RuntimeException('No Stripe bootstrap available.');
  }

  private static function log(string $event, array $ctx = []) : void {
    if (class_exists('SD_Util') && method_exists('SD_Util', 'log')) {
      SD_Util::log($event, $ctx);
      return;
    }
    error_log('[solodrive] ' . wp_json_encode([
      'sd'    => true,
      'event' => $event,
      'ts'    => gmdate('c'),
      'ctx'   => $ctx,
    ]));
  }
}