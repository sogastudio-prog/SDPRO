<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontHoursEvaluator {

  /**
   * Returns true when the storefront is inside service hours.
   *
   * Policy inputs expected:
   * - weekly_hours
   * - holiday_overrides
   * - manual_closures
   */
  public static function is_open_at(
    SD_StorefrontPolicy $policy,
    int $ts,
    ?DateTimeZone $tz = null
  ) : bool {
    $tz = $tz ?: self::tenant_timezone($policy->tenant_id);

    $dt = new DateTimeImmutable('@' . $ts);
    $dt = $dt->setTimezone($tz);

    $date_key = $dt->format('Y-m-d');
    $day_key  = strtolower($dt->format('D')); // mon, tue, wed...
    $time_now = $dt->format('H:i');

    // 1) Manual closure dates always win.
    if (self::date_is_listed($date_key, (array) $policy->manual_closures)) {
      return false;
    }

    // 2) Holiday override for exact date.
    $holiday = self::lookup_date_rule($date_key, (array) $policy->holiday_overrides);
    if (is_array($holiday)) {
      return self::time_in_windows($time_now, self::normalize_windows($holiday));
    }

    // 3) Weekly hours fallback.
    $weekly = (array) $policy->weekly_hours;
    $windows = isset($weekly[$day_key]) ? self::normalize_windows($weekly[$day_key]) : [];

    if (!$windows) {
      return false;
    }

    return self::time_in_windows($time_now, $windows);
  }

  /**
   * Helpful for UI and debug payloads.
   *
   * @return array<string,mixed>
   */
  public static function status_at(
    SD_StorefrontPolicy $policy,
    int $ts,
    ?DateTimeZone $tz = null
  ) : array {
    $tz = $tz ?: self::tenant_timezone($policy->tenant_id);

    $dt = new DateTimeImmutable('@' . $ts);
    $dt = $dt->setTimezone($tz);

    $date_key = $dt->format('Y-m-d');
    $day_key  = strtolower($dt->format('D'));
    $time_now = $dt->format('H:i');

    $source  = 'weekly';
    $windows = [];

    if (self::date_is_listed($date_key, (array) $policy->manual_closures)) {
      return [
        'is_open'      => false,
        'source'       => 'manual_closure',
        'date'         => $date_key,
        'day'          => $day_key,
        'local_time'   => $time_now,
        'windows'      => [],
        'next_open_at' => self::next_open_at($policy, $ts, $tz),
      ];
    }

    $holiday = self::lookup_date_rule($date_key, (array) $policy->holiday_overrides);
    if (is_array($holiday)) {
      $source  = 'holiday_override';
      $windows = self::normalize_windows($holiday);
    } else {
      $weekly = (array) $policy->weekly_hours;
      $windows = isset($weekly[$day_key]) ? self::normalize_windows($weekly[$day_key]) : [];
    }

    $is_open = self::time_in_windows($time_now, $windows);

    return [
      'is_open'      => $is_open,
      'source'       => $source,
      'date'         => $date_key,
      'day'          => $day_key,
      'local_time'   => $time_now,
      'windows'      => $windows,
      'next_open_at' => $is_open ? null : self::next_open_at($policy, $ts, $tz),
    ];
  }

  /**
   * Returns next open timestamp in tenant-local time search window, or null.
   */
  public static function next_open_at(
    SD_StorefrontPolicy $policy,
    int $ts,
    ?DateTimeZone $tz = null,
    int $days_ahead = 14
  ) : ?int {
    $tz = $tz ?: self::tenant_timezone($policy->tenant_id);

    $start = new DateTimeImmutable('@' . $ts);
    $start = $start->setTimezone($tz);

    for ($i = 0; $i <= $days_ahead; $i++) {
      $day = $start->modify('+' . $i . ' day');
      $date_key = $day->format('Y-m-d');
      $day_key  = strtolower($day->format('D'));

      if (self::date_is_listed($date_key, (array) $policy->manual_closures)) {
        continue;
      }

      $holiday = self::lookup_date_rule($date_key, (array) $policy->holiday_overrides);
      $windows = is_array($holiday)
        ? self::normalize_windows($holiday)
        : self::normalize_windows(((array) $policy->weekly_hours)[$day_key] ?? []);

      if (!$windows) {
        continue;
      }

      foreach ($windows as $window) {
        $candidate = DateTimeImmutable::createFromFormat(
          'Y-m-d H:i',
          $date_key . ' ' . $window['start'],
          $tz
        );

        if ($candidate && $candidate->getTimestamp() > $ts) {
          return $candidate->getTimestamp();
        }
      }
    }

    return null;
  }

  private static function tenant_timezone(int $tenant_id) : DateTimeZone {
    $tz_string = (string) get_post_meta($tenant_id, 'sd_timezone', true);
    if ($tz_string !== '') {
      try {
        return new DateTimeZone($tz_string);
      } catch (Throwable $e) {
        // fall through
      }
    }

    $wp_tz = wp_timezone_string();
    if ($wp_tz) {
      try {
        return new DateTimeZone($wp_tz);
      } catch (Throwable $e) {
        // fall through
      }
    }

    return new DateTimeZone('UTC');
  }

  /**
   * Accepts these shapes:
   * - []
   * - ['09:00-17:00', '18:00-22:00']
   * - [ ['start'=>'09:00','end'=>'17:00'] ]
   *
   * @param mixed $raw
   * @return array<int,array{start:string,end:string}>
   */
  private static function normalize_windows($raw) : array {
    $out = [];

    if (!is_array($raw)) {
      return $out;
    }

    foreach ($raw as $row) {
      if (is_string($row) && strpos($row, '-') !== false) {
        [$start, $end] = array_map('trim', explode('-', $row, 2));
        if (self::valid_hhmm($start) && self::valid_hhmm($end)) {
          $out[] = ['start' => $start, 'end' => $end];
        }
        continue;
      }

      if (is_array($row)) {
        $start = isset($row['start']) ? trim((string) $row['start']) : '';
        $end   = isset($row['end'])   ? trim((string) $row['end'])   : '';

        if (self::valid_hhmm($start) && self::valid_hhmm($end)) {
          $out[] = ['start' => $start, 'end' => $end];
        }
      }
    }

    return $out;
  }

  /**
   * @param array<int,array{start:string,end:string}> $windows
   */
  private static function time_in_windows(string $hhmm, array $windows) : bool {
    foreach ($windows as $window) {
      $start = $window['start'];
      $end   = $window['end'];

      // Same-day window.
      if ($start <= $end) {
        if ($hhmm >= $start && $hhmm <= $end) {
          return true;
        }
        continue;
      }

      // Overnight window, e.g. 20:00-02:00
      if ($hhmm >= $start || $hhmm <= $end) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param array<int|string,mixed> $list
   */
  private static function date_is_listed(string $date_key, array $list) : bool {
    foreach ($list as $item) {
      if (trim((string) $item) === $date_key) {
        return true;
      }
    }
    return false;
  }

  /**
   * @param array<int|string,mixed> $rules
   * @return mixed
   */
  private static function lookup_date_rule(string $date_key, array $rules) {
    if (isset($rules[$date_key])) {
      return $rules[$date_key];
    }

    foreach ($rules as $rule) {
      if (is_array($rule) && (($rule['date'] ?? '') === $date_key)) {
        return $rule['windows'] ?? [];
      }
    }

    return null;
  }

  private static function valid_hhmm(string $value) : bool {
    return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
  }
}