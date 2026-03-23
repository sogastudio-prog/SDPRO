<?php
if (!defined('ABSPATH')) { exit; }

if (class_exists('SD_Module_TripSurfaceApi', false)) { return; }

final class SD_Module_TripSurfaceApi {

  public static function register() : void {
    add_action('rest_api_init', [__CLASS__, 'rest_api_init']);
  }

  public static function rest_api_init() : void {
    register_rest_route('sd/v1', '/trip-status', [
      'methods'             => 'GET',
      'callback'            => [__CLASS__, 'handle_trip_status'],
      'permission_callback' => '__return_true',
      'args' => [
        'trip_token' => [
          'required' => true,
          'type'     => 'string',
        ],
      ],
    ]);
  }

  public static function handle_trip_status(\WP_REST_Request $request) {
    $token = trim((string) $request->get_param('trip_token'));
    if ($token === '') {
      return new \WP_REST_Response([
        'ok' => false,
        'message' => 'Missing token.',
      ], 400);
    }

    if (!class_exists('SD_Module_TripSurface')) {
      return new \WP_REST_Response([
        'ok' => false,
        'message' => 'Trip surface unavailable.',
      ], 500);
    }

    if (!method_exists('SD_Module_TripSurface', 'public_trip_status_payload')) {
      return new \WP_REST_Response([
        'ok' => false,
        'message' => 'Trip payload method unavailable.',
      ], 500);
    }

    $payload = SD_Module_TripSurface::public_trip_status_payload($token);
    $code = !empty($payload['ok']) ? 200 : 404;

    return new \WP_REST_Response($payload, $code);
  }
}

SD_Module_TripSurfaceApi::register();