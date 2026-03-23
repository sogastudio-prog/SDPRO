<?php
if (!defined('ABSPATH')) { exit; }

if (class_exists('SD_Module_OperatorPushKeys', false)) { return; }

use Minishlink\WebPush\VAPID;

final class SD_Module_OperatorPushKeys {

  private const OPT_PUBLIC  = 'sd_operator_vapid_public';
  private const OPT_PRIVATE = 'sd_operator_vapid_private';
  private const OPT_SUBJECT = 'sd_operator_vapid_subject';

  public static function register() : void {
    add_action('init', [__CLASS__, 'maybe_seed_keys'], 5);
  }

  public static function public_key() : string {
    return (string) get_option(self::OPT_PUBLIC, '');
  }

  public static function private_key() : string {
    return (string) get_option(self::OPT_PRIVATE, '');
  }

  public static function subject() : string {
    $subject = (string) get_option(self::OPT_SUBJECT, '');
    if ($subject !== '') return $subject;

    $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $host = is_string($host) && $host !== '' ? $host : 'solodrive.pro';
    return 'mailto:ops@' . $host;
  }

  public static function has_keys() : bool {
    return (self::public_key() !== '' && self::private_key() !== '');
  }

  public static function maybe_seed_keys() : void {
    if (self::has_keys()) return;
    if (!class_exists(VAPID::class)) return;

    try {
      $keys = VAPID::createVapidKeys();

      if (!empty($keys['publicKey']) && !empty($keys['privateKey'])) {
        update_option(self::OPT_PUBLIC, (string) $keys['publicKey'], false);
        update_option(self::OPT_PRIVATE, (string) $keys['privateKey'], false);

        if ((string) get_option(self::OPT_SUBJECT, '') === '') {
          update_option(self::OPT_SUBJECT, self::subject(), false);
        }

        if (class_exists('SD_Util')) {
          SD_Util::log('operator_vapid_keys_seeded', [
            'subject' => self::subject(),
          ]);
        }
      }
    } catch (\Throwable $e) {
      if (class_exists('SD_Util')) {
        SD_Util::log('operator_vapid_keys_seed_failed', [
          'error' => $e->getMessage(),
        ]);
      }
    }
  }
}