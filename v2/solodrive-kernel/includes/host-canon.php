<?php
if (!defined('ABSPATH')) { exit; }

// Keep the requested host. WP "canonical" redirects can collapse subdomains to the primary site URL.
add_filter('redirect_canonical', function($redirect_url, $requested_url) {
  $req_host = parse_url($requested_url, PHP_URL_HOST);
  $to_host  = parse_url((string)$redirect_url, PHP_URL_HOST);

  // If WP tries to redirect to a different host, block it.
  if ($req_host && $to_host && strtolower($req_host) !== strtolower($to_host)) {
    return false;
  }
  return $redirect_url;
}, 10, 2);