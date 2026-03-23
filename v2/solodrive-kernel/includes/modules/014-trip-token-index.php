<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_TripTokenIndex (v1)
 *
 * Purpose:
 * - Fast /trip/<token> resolution without query args.
 * - Maintain a tiny lookup table indexed by token hash -> lead_id + tenant_id.
 *
 * Design:
 * - token_hash = sha256(token) as hex (64 chars)
 * - Table: wp_sd_trip_token_index
 * - Unique(token_hash), and indexed ride_id + tenant_id
 *
 * Notes:
 * - This is platform-wide. All leads MUST be tenant-scoped.
 */

if (class_exists('SD_Module_TripTokenIndex', false)) { return; }

final class SD_Module_TripTokenIndex {

  private const TABLE_SLUG = 'sd_trip_token_index';

  public static function register() : void {
    // Create/upgrade table on plugin activation (preferred),
    // but also safe to run on admin_init as a soft-heal.
    add_action('admin_init', [__CLASS__, 'maybe_ensure_table']);
  }

  /**
   * Idempotent: create/upgrade the index table.
   */
  public static function maybe_ensure_table() : void {
    // Avoid repeated work on every admin page load.
    $opt = get_option('_sd_trip_token_index_ready');
    if ($opt === '1') return;

    self::ensure_table();
    update_option('_sd_trip_token_index_ready', '1', false);
  }

  public static function ensure_table() : void {
    global $wpdb;

    $table = self::table_name();
    $charset = $wpdb->get_charset_collate();

    // dbDelta requires upgrade.php
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // token_hash is stored as CHAR(64) hex to keep it simple/portable.
    $sql = "CREATE TABLE {$table} (
      token_hash CHAR(64) NOT NULL,
      lead_id BIGINT(20) UNSIGNED NOT NULL,
      tenant_id BIGINT(20) UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY  (token_hash),
      KEY lead_id (lead_id),
      KEY tenant_id (tenant_id)
    ) {$charset};";

    dbDelta($sql);
  }

  public static function table_name() : string {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_SLUG;
  }

  public static function hash_token(string $token) : string {
    $token = trim($token);
    if ($token === '') return '';
    return hash('sha256', $token);
  }

  /**
   * Upsert: token_hash -> ride_id, tenant_id
   */
  public static function upsert(string $token, int $lead_id, int $tenant_id) : bool {
    global $wpdb;

    $h = self::hash_token($token);
    if ($h === '' || $lead_id <= 0 || $tenant_id <= 0) return false;

    $table = self::table_name();

    // Ensure table exists (soft-heal).
    self::ensure_table();

    // Use REPLACE to keep it simple: PRIMARY KEY(token_hash)
    $res = $wpdb->query(
      $wpdb->prepare(
        "REPLACE INTO {$table} (token_hash, lead_id, tenant_id, created_at)
         VALUES (%s, %d, %d, NOW())",
        $h, $lead_id, $tenant_id
      )
    );

    return ($res !== false);
  }

  /**
   * Resolve /trip token quickly.
   *
   * @return array{lead_id:int, tenant_id:int}|null
   */
  public static function resolve(string $token) : ?array {
    global $wpdb;

    $h = self::hash_token($token);
    if ($h === '') return null;

    $table = self::table_name();

    // If table doesn't exist yet, fail soft.
    // (We still want platform to run.)
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
    if ($exists !== $table) return null;

    $row = $wpdb->get_row(
      $wpdb->prepare(
        "SELECT lead_id, tenant_id FROM {$table} WHERE token_hash = %s LIMIT 1",
        $h
      ),
      ARRAY_A
    );

    if (!is_array($row)) return null;

    $lead_id = (int) ($row['lead_id'] ?? 0);
    $tenant_id = (int) ($row['tenant_id'] ?? 0);
    if ($lead_id <= 0 || $tenant_id <= 0) return null;

    return [
      'lead_id'   => $lead_id,
      'tenant_id' => $tenant_id,
    ];
  }
}

SD_Module_TripTokenIndex::register();