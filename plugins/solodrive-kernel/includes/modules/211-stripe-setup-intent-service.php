<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StripeSetupIntentService {

  public static function create_for_ride(int $ride_id) : array {
    $ride_id = absint($ride_id);
    if ($ride_id <= 0) {
      return ['ok' => false, 'error' => 'invalid_ride_id'];
    }

    if (!class_exists('\\Stripe\\SetupIntent')) {
      self::log('stripe_setup_intent_missing_library', ['ride_id' => $ride_id]);
      return ['ok' => false, 'error' => 'stripe_library_missing'];
    }

    $customer = SD_StripeCustomerService::get_or_create_for_ride($ride_id);
    if (empty($customer['ok'])) {
      return ['ok' => false, 'error' => (string) ($customer['error'] ?? 'customer_failed')];
    }

    $tenant_id    = (int) get_post_meta($ride_id, SD_Meta::TENANT_ID, true);
    $customer_id  = (string) $customer['customer_id'];

    try {
      self::bootstrap_stripe($tenant_id);

      $intent = \Stripe\SetupIntent::create([
        'customer'             => $customer_id,
        'payment_method_types' => ['card'],
        'usage'                => 'off_session',
        'metadata'             => [
          'sd_ride_id'   => (string) $ride_id,
          'sd_tenant_id' => (string) $tenant_id,
        ],
      ]);

      $intent_id     = (string) ($intent->id ?? '');
      $client_secret = (string) ($intent->client_secret ?? '');

      if ($intent_id === '' || $client_secret === '') {
        return ['ok' => false, 'error' => 'setup_intent_create_failed'];
      }

      update_post_meta($ride_id, 'sd_setup_intent_id', $intent_id);
      update_post_meta($ride_id, 'sd_payment_strategy', 'SAVE_ONLY');

      self::log('stripe_setup_intent_created', [
        'ride_id'        => $ride_id,
        'tenant_id'      => $tenant_id,
        'customer_id'    => $customer_id,
        'setup_intent_id'=> $intent_id,
      ]);

      return [
        'ok'            => true,
        'setup_intent_id' => $intent_id,
        'client_secret' => $client_secret,
        'customer_id'   => $customer_id,
      ];
    } catch (\Throwable $e) {
      self::log('stripe_setup_intent_exception', [
        'ride_id' => $ride_id,
        'error'   => $e->getMessage(),
      ]);
      return ['ok' => false, 'error' => 'setup_intent_exception'];
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