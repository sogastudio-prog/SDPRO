<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Admin helper for seeding OPEN supply blocks.
 *
 * Purpose:
 * - Gives ops/admin a fast way to create tenant-scoped supply.
 * - Keeps v1 simple: block generation, not scheduling UI.
 *
 * Notes:
 * - This is intentionally admin-only.
 * - It creates OPEN sd_time_block records.
 * - It does not hold, commit, or assign drivers automatically.
 */
final class SD_Module_TimeblockAdmin {

  private const NONCE_ACTION = 'sd_seed_timeblocks';
  private const PAGE_SLUG    = 'sd-timeblock-seed';

  public static function register() : void {
    if (!is_admin()) return;

    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_post_sd_seed_timeblocks', [__CLASS__, 'handle_seed']);
  }

  public static function admin_menu() : void {
    add_submenu_page(
      'edit.php?post_type=' . SD_Module_TimeBlockCPT::CPT,
      'Seed Time Blocks',
      'Seed Blocks',
      'manage_options',
      self::PAGE_SLUG,
      [__CLASS__, 'render_page']
    );
  }

  public static function render_page() : void {
    if (!current_user_can('manage_options')) {
      wp_die('Access denied.');
    }

    $tenants = get_posts([
      'post_type'      => 'sd_tenant',
      'post_status'    => 'publish',
      'posts_per_page' => 200,
      'orderby'        => 'title',
      'order'          => 'ASC',
    ]);

    $notice = isset($_GET['seeded']) ? (int) $_GET['seeded'] : 0;
    $error  = isset($_GET['sd_error']) ? sanitize_text_field((string) $_GET['sd_error']) : '';

    echo '<div class="wrap">';
    echo '<h1>Seed Time Blocks</h1>';
    echo '<p>Create tenant-scoped OPEN supply blocks for ASAP and reservation feasibility.</p>';

    if ($notice > 0) {
      echo '<div class="notice notice-success"><p>Created <strong>' . esc_html((string) $notice) . '</strong> time blocks.</p></div>';
    }

    if ($error !== '') {
      echo '<div class="notice notice-error"><p>Error: <strong>' . esc_html($error) . '</strong></p></div>';
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field(self::NONCE_ACTION);

    echo '<input type="hidden" name="action" value="sd_seed_timeblocks">';

    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr>';
    echo '<th scope="row"><label for="sd_tenant_id">Tenant</label></th>';
    echo '<td>';
    echo '<select name="sd_tenant_id" id="sd_tenant_id" required>';
    echo '<option value="">Select tenant</option>';
    foreach ($tenants as $tenant) {
      echo '<option value="' . esc_attr((string) $tenant->ID) . '">' . esc_html($tenant->post_title . ' (#' . $tenant->ID . ')') . '</option>';
    }
    echo '</select>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="sd_seed_start_date">Start date</label></th>';
    echo '<td><input type="date" name="sd_seed_start_date" id="sd_seed_start_date" required></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="sd_seed_days">Days</label></th>';
    echo '<td><input type="number" min="1" max="31" step="1" name="sd_seed_days" id="sd_seed_days" value="7" required></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="sd_seed_day_start">Daily start time</label></th>';
    echo '<td><input type="time" name="sd_seed_day_start" id="sd_seed_day_start" value="08:00" required></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="sd_seed_day_end">Daily end time</label></th>';
    echo '<td><input type="time" name="sd_seed_day_end" id="sd_seed_day_end" value="20:00" required></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="sd_seed_block_minutes">Block size (minutes)</label></th>';
    echo '<td><input type="number" min="15" max="480" step="15" name="sd_seed_block_minutes" id="sd_seed_block_minutes" value="120" required></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="sd_seed_driver_id">Driver user id (optional)</label></th>';
    echo '<td><input type="number" min="0" step="1" name="sd_seed_driver_id" id="sd_seed_driver_id" value="0"></td>';
    echo '</tr>';

    echo '</tbody></table>';

    submit_button('Seed OPEN Time Blocks');

    echo '</form>';
    echo '</div>';
  }

  public static function handle_seed() : void {
    if (!current_user_can('manage_options')) {
      wp_die('Access denied.');
    }

    check_admin_referer(self::NONCE_ACTION);

    $tenant_id     = isset($_POST['sd_tenant_id']) ? absint($_POST['sd_tenant_id']) : 0;
    $start_date    = isset($_POST['sd_seed_start_date']) ? trim((string) $_POST['sd_seed_start_date']) : '';
    $days          = isset($_POST['sd_seed_days']) ? max(1, min(31, (int) $_POST['sd_seed_days'])) : 7;
    $day_start     = isset($_POST['sd_seed_day_start']) ? trim((string) $_POST['sd_seed_day_start']) : '';
    $day_end       = isset($_POST['sd_seed_day_end']) ? trim((string) $_POST['sd_seed_day_end']) : '';
    $block_minutes = isset($_POST['sd_seed_block_minutes']) ? max(15, min(480, (int) $_POST['sd_seed_block_minutes'])) : 120;
    $driver_id     = isset($_POST['sd_seed_driver_id']) ? absint($_POST['sd_seed_driver_id']) : 0;

    if ($tenant_id <= 0 || $start_date === '' || $day_start === '' || $day_end === '') {
      wp_safe_redirect(add_query_arg([
        'post_type' => SD_Module_TimeBlockCPT::CPT,
        'page'      => self::PAGE_SLUG,
        'sd_error'  => 'missing_required_fields',
      ], admin_url('edit.php')));
      exit;
    }

    $timezone = self::tenant_timezone($tenant_id);

    try {
      $created = self::seed_blocks($tenant_id, $start_date, $days, $day_start, $day_end, $block_minutes, $driver_id, $timezone);
    } catch (\Throwable $e) {
      if (class_exists('SD_Util')) {
        SD_Util::log('timeblock_seed_failed', [
          'tenant_id' => $tenant_id,
          'error'     => $e->getMessage(),
        ]);
      }

      wp_safe_redirect(add_query_arg([
        'post_type' => SD_Module_TimeBlockCPT::CPT,
        'page'      => self::PAGE_SLUG,
        'sd_error'  => 'seed_failed',
      ], admin_url('edit.php')));
      exit;
    }

    wp_safe_redirect(add_query_arg([
      'post_type' => SD_Module_TimeBlockCPT::CPT,
      'page'      => self::PAGE_SLUG,
      'seeded'    => $created,
    ], admin_url('edit.php')));
    exit;
  }

  private static function seed_blocks(
    int $tenant_id,
    string $start_date,
    int $days,
    string $day_start,
    string $day_end,
    int $block_minutes,
    int $driver_id,
    \DateTimeZone $tz
  ) : int {
    $created = 0;

    for ($i = 0; $i < $days; $i++) {
      $date = new \DateTimeImmutable($start_date, $tz);
      $date = $date->modify('+' . $i . ' day');

      $dayStart = new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $day_start . ':00', $tz);
      $dayEnd   = new \DateTimeImmutable($date->format('Y-m-d') . ' ' . $day_end . ':00', $tz);

      if ($dayEnd <= $dayStart) {
        continue;
      }

      $cursor = $dayStart;
      while ($cursor < $dayEnd) {
        $next = $cursor->modify('+' . $block_minutes . ' minutes');
        if ($next > $dayEnd) {
          break;
        }

        $block_id = SD_TimeBlockRepository::create_block([
          'tenant_id' => $tenant_id,
          'driver_id' => $driver_id,
          'start_ts'  => $cursor->getTimestamp(),
          'end_ts'    => $next->getTimestamp(),
          'capacity'  => $block_minutes,
          'status'    => 'OPEN',
          'title'     => sprintf(
            'Open Supply — %s %s-%s',
            $date->format('Y-m-d'),
            $cursor->format('H:i'),
            $next->format('H:i')
          ),
        ]);

        if ($block_id > 0) {
          $created++;
        }

        $cursor = $next;
      }
    }

    if (class_exists('SD_Util')) {
      SD_Util::log('timeblock_seeded', [
        'tenant_id'     => $tenant_id,
        'days'          => $days,
        'block_minutes' => $block_minutes,
        'driver_id'     => $driver_id,
        'created'       => $created,
      ]);
    }

    return $created;
  }

  private static function tenant_timezone(int $tenant_id) : \DateTimeZone {
    $tz_name = (string) get_post_meta($tenant_id, SD_Meta::STOREFRONT_TIMEZONE, true);
    if ($tz_name === '') {
      $tz_name = wp_timezone_string();
    }
    if ($tz_name === '') {
      $tz_name = 'America/New_York';
    }

    try {
      return new \DateTimeZone($tz_name);
    } catch (\Throwable $e) {
      return new \DateTimeZone('America/New_York');
    }
  }
}