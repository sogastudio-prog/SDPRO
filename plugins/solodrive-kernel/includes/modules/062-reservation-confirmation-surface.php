<?php
if (!defined('ABSPATH')) { exit; }

final class SD_Module_ReservationConfirmationSurface {

  public static function register() : void {
    add_action('init', [__CLASS__, 'add_rewrite']);
    add_filter('query_vars', [__CLASS__, 'query_vars']);
    add_action('template_redirect', [__CLASS__, 'template_redirect']);
    add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_enqueue_assets']);
  }

  public static function add_rewrite() : void {
    add_rewrite_rule('^reservation/([^/]+)/?$', 'index.php?sd_reservation_token=$matches[1]', 'top');
  }

  public static function query_vars(array $vars) : array {
    $vars[] = 'sd_reservation_token';
    return $vars;
  }

  public static function template_redirect() : void {
    $token = (string) get_query_var('sd_reservation_token');
    if ($token === '') return;

    $ride_id = self::ride_id_from_token($token);
    if ($ride_id <= 0) {
      status_header(404);
      echo self::render_shell('<div class="sd-card"><p>Reservation not found.</p></div>');
      exit;
    }

    $pickup  = (string) get_post_meta($ride_id, SD_Meta::PICKUP_TEXT, true);
    $dropoff = (string) get_post_meta($ride_id, SD_Meta::DROPOFF_TEXT, true);
    $start   = (int) get_post_meta($ride_id, 'sd_service_start_ts', true);
    $client_secret = (string) get_post_meta($ride_id, 'sd_setup_intent_client_secret', true);

    $body  = '<div class="sd-card sd-reservation-confirm-card">';
    $body .= '<h2>Reservation received</h2>';
    $body .= '<p>Your requested ride has been scheduled.</p>';
    $body .= '<p><strong>Pickup:</strong> ' . esc_html($pickup) . '</p>';
    $body .= '<p><strong>Dropoff:</strong> ' . esc_html($dropoff) . '</p>';
    if ($start > 0) {
      $body .= '<p><strong>Scheduled:</strong> ' . esc_html(date_i18n('M j, Y g:i A', $start)) . '</p>';
    }
    $body .= '</div>';

    if ($client_secret !== '') {
      $body .= '<div class="sd-card sd-reservation-payment-card">';
      $body .= '<h3>Save your card</h3>';
      $body .= '<p>Your card will be securely saved for future authorization and cancellation-policy enforcement.</p>';
      $body .= '<div id="sd-setup-message" class="sd-setup-message" style="margin-bottom:12px;"></div>';
      $body .= '<form id="sd-setup-form" data-client-secret="' . esc_attr($client_secret) . '" data-ride-id="' . (int) $ride_id . '">';
      $body .= '<div id="sd-payment-element" style="margin-bottom:12px;"></div>';
      $body .= '<button type="submit" id="sd-setup-submit">Save card</button>';
      $body .= '</form>';
      $body .= '</div>';
    }

    status_header(200);
    echo self::render_shell($body);
    exit;
  }

  public static function maybe_enqueue_assets() : void {
    $token = (string) get_query_var('sd_reservation_token');
    if ($token === '') return;

    wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);

    $file   = __FILE__;
    $js_abs = dirname(dirname(dirname(__DIR__))) . '/assets/js/reservation-setup-intent.js';
    $js_url = plugins_url('assets/js/reservation-setup-intent.js', dirname(dirname(dirname(__DIR__))) . '/solodrive-kernel.php');
    $ver    = file_exists($js_abs) ? (string) filemtime($js_abs) : '1.0.0';

    wp_enqueue_script('sd-reservation-setup-intent', $js_url, ['stripe-js'], $ver, true);

    $pk = defined('SD_STRIPE_PUBLISHABLE_KEY') ? SD_STRIPE_PUBLISHABLE_KEY : '';
    wp_localize_script('sd-reservation-setup-intent', 'SD_RESERVATION_SETUP', [
      'publishableKey' => $pk,
    ]);
  }

  private static function ride_id_from_token(string $token) : int {
    $ids = get_posts([
      'post_type'      => 'sd_ride',
      'post_status'    => ['publish', 'private', 'draft'],
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'meta_query'     => [
        [
          'key'   => 'sd_reservation_token',
          'value' => $token,
        ],
      ],
    ]);

    return !empty($ids[0]) ? (int) $ids[0] : 0;
  }

  private static function render_shell(string $body) : string {
    ob_start();
    ?>
    <!doctype html>
    <html <?php language_attributes(); ?>>
    <head>
      <meta charset="<?php bloginfo('charset'); ?>">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>Reservation</title>
      <?php wp_head(); ?>
    </head>
    <body class="sd-reservation-confirmation">
      <main class="sd-wrap" style="max-width:720px;margin:40px auto;padding:0 16px;">
        <?php echo $body; ?>
      </main>
      <?php wp_footer(); ?>
    </body>
    </html>
    <?php
    return (string) ob_get_clean();
  }
}
