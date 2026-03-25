<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_TimeSpaceLedgerView
 *
 * Read-only tenant-scoped admin view of the time-space ledger.
 *
 * Purpose:
 * - Surface recent ledger events for the current tenant
 * - Support basic operational review and dispute defense
 * - Keep this v1 intentionally simple and read-only
 */

if (class_exists('SD_Module_TimeSpaceLedgerView', false)) { return; }

final class SD_Module_TimeSpaceLedgerView {

  private const PAGE_SIZE = 50;

  public static function register() : void {
    if (!is_admin()) return;

    add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue']);
  }

  public static function add_metabox() : void {
    if (!class_exists('SD_Module_TenantCPT', false)) return;

    add_meta_box(
      'sd_tenant_time_space_ledger',
      'Time-Space Ledger',
      [__CLASS__, 'render_metabox'],
      SD_Module_TenantCPT::CPT,
      'normal',
      'default'
    );
  }

  public static function admin_enqueue(string $hook) : void {
    if (!self::is_tenant_edit_screen()) return;

    wp_register_style('sd-ts-ledger-admin', false, [], '1.0');
    wp_enqueue_style('sd-ts-ledger-admin');

    wp_add_inline_style('sd-ts-ledger-admin', '
      .sd-ts-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:0 0 14px}
      .sd-ts-filter{display:flex;flex-direction:column;gap:4px;min-width:140px}
      .sd-ts-filter label{font-size:12px;font-weight:600;color:#50575e}
      .sd-ts-filter input,.sd-ts-filter select{min-height:34px}
      .sd-ts-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 14px}
      .sd-ts-stat{border:1px solid #dcdcde;border-radius:10px;padding:10px;background:#fff}
      .sd-ts-stat-key{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#646970;margin-bottom:4px}
      .sd-ts-stat-value{display:block;font-size:18px;font-weight:700;color:#1d2327}
      .sd-ts-table-wrap{overflow:auto}
      .sd-ts-table{width:100%;border-collapse:collapse}
      .sd-ts-table th,.sd-ts-table td{padding:10px 8px;border-bottom:1px solid #e2e4e7;vertical-align:top;text-align:left}
      .sd-ts-table th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970;background:#f6f7f7;position:sticky;top:0}
      .sd-ts-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px}
      .sd-ts-pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}
      .sd-ts-pill--observed{background:#edf9f0;color:#166534}
      .sd-ts-pill--projected{background:#eff6ff;color:#1d4ed8}
      .sd-ts-pill--committed{background:#fff7e6;color:#92400e}
      .sd-ts-muted{color:#646970}
      .sd-ts-empty{padding:10px 0;color:#646970}
      .sd-ts-payload{max-width:360px;white-space:pre-wrap;word-break:break-word}
      @media (max-width: 900px){
        .sd-ts-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
      }
    ');
  }

  public static function render_metabox(\WP_Post $post) : void {
    $tenant_id = (int) $post->ID;
    $filters   = self::read_filters();
    $events    = self::query_events($tenant_id, $filters, self::PAGE_SIZE);
    $summary   = self::summarize_events($events);

    echo '<div class="sd-ts-ledger">';

    self::render_filters($tenant_id, $filters);
    self::render_summary($summary);
    self::render_table($events);

    echo '</div>';
  }

  private static function render_filters(int $tenant_id, array $filters) : void {
    $base_url = get_edit_post_link($tenant_id, 'url');
    if (!is_string($base_url) || $base_url === '') {
      $base_url = admin_url('post.php?post=' . $tenant_id . '&action=edit');
    }

    echo '<form method="get" action="' . esc_url($base_url) . '" class="sd-ts-toolbar">';
    echo '<input type="hidden" name="post" value="' . (int) $tenant_id . '">';
    echo '<input type="hidden" name="action" value="edit">';

    echo '<div class="sd-ts-filter">';
    echo '<label for="sd_ts_event_type">Event</label>';
    echo '<select id="sd_ts_event_type" name="sd_ts_event_type">';
    echo '<option value="">All</option>';
    foreach (self::event_type_options() as $value) {
      echo '<option value="' . esc_attr($value) . '"' . selected($filters['event_type'], $value, false) . '>' . esc_html($value) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="sd-ts-filter">';
    echo '<label for="sd_ts_truth_class">Truth</label>';
    echo '<select id="sd_ts_truth_class" name="sd_ts_truth_class">';
    echo '<option value="">All</option>';
    foreach (self::truth_class_options() as $value) {
      echo '<option value="' . esc_attr($value) . '"' . selected($filters['truth_class'], $value, false) . '>' . esc_html($value) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="sd-ts-filter">';
    echo '<label for="sd_ts_subject_type">Subject</label>';
    echo '<select id="sd_ts_subject_type" name="sd_ts_subject_type">';
    echo '<option value="">All</option>';
    foreach (self::subject_type_options() as $value) {
      echo '<option value="' . esc_attr($value) . '"' . selected($filters['subject_type'], $value, false) . '>' . esc_html($value) . '</option>';
    }
    echo '</select>';
    echo '</div>';

    echo '<div class="sd-ts-filter">';
    echo '<label for="sd_ts_lead_id">Lead ID</label>';
    echo '<input id="sd_ts_lead_id" type="number" min="1" name="sd_ts_lead_id" value="' . esc_attr($filters['lead_id'] > 0 ? (string) $filters['lead_id'] : '') . '">';
    echo '</div>';

    echo '<div class="sd-ts-filter">';
    echo '<label for="sd_ts_driver_id">Driver ID</label>';
    echo '<input id="sd_ts_driver_id" type="number" min="1" name="sd_ts_driver_id" value="' . esc_attr($filters['driver_id'] > 0 ? (string) $filters['driver_id'] : '') . '">';
    echo '</div>';

    echo '<div class="sd-ts-filter">';
    echo '<label for="sd_ts_date_from">From</label>';
    echo '<input id="sd_ts_date_from" type="date" name="sd_ts_date_from" value="' . esc_attr($filters['date_from']) . '">';
    echo '</div>';

    echo '<div class="sd-ts-filter">';
    echo '<label for="sd_ts_date_to">To</label>';
    echo '<input id="sd_ts_date_to" type="date" name="sd_ts_date_to" value="' . esc_attr($filters['date_to']) . '">';
    echo '</div>';

    echo '<div class="sd-ts-filter">';
    echo '<button type="submit" class="button button-primary">Filter</button>';
    echo '</div>';

    echo '<div class="sd-ts-filter">';
    echo '<a class="button" href="' . esc_url(remove_query_arg([
      'sd_ts_event_type',
      'sd_ts_truth_class',
      'sd_ts_subject_type',
      'sd_ts_lead_id',
      'sd_ts_driver_id',
      'sd_ts_date_from',
      'sd_ts_date_to',
    ], $base_url)) . '">Reset</a>';
    echo '</div>';

    echo '</form>';
  }

  private static function render_summary(array $summary) : void {
    echo '<div class="sd-ts-summary">';

    self::summary_stat('Total', (string) $summary['total']);
    self::summary_stat('Observed', (string) $summary['observed']);
    self::summary_stat('Projected', (string) $summary['projected']);
    self::summary_stat('Committed', (string) $summary['committed']);

    echo '</div>';
  }

  private static function summary_stat(string $label, string $value) : void {
    echo '<div class="sd-ts-stat">';
    echo '<span class="sd-ts-stat-key">' . esc_html($label) . '</span>';
    echo '<span class="sd-ts-stat-value">' . esc_html($value) . '</span>';
    echo '</div>';
  }

  private static function render_table(array $events) : void {
    if (empty($events)) {
      echo '<div class="sd-ts-empty">No ledger events found for the current filters.</div>';
      return;
    }

    echo '<div class="sd-ts-table-wrap">';
    echo '<table class="sd-ts-table">';
    echo '<thead><tr>';
    echo '<th>Time</th>';
    echo '<th>Event</th>';
    echo '<th>Truth</th>';
    echo '<th>Subject</th>';
    echo '<th>Lead</th>';
    echo '<th>Driver</th>';
    echo '<th>Location</th>';
    echo '<th>Payload</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($events as $event_id) {
      $event_type   = (string) get_post_meta($event_id, SD_Meta::TS_EVENT_TYPE, true);
      $truth_class  = (string) get_post_meta($event_id, SD_Meta::TS_TRUTH_CLASS, true);
      $subject_type = (string) get_post_meta($event_id, SD_Meta::TS_SUBJECT_TYPE, true);
      $subject_id   = (int) get_post_meta($event_id, SD_Meta::TS_SUBJECT_ID, true);
      $lead_id      = (int) get_post_meta($event_id, SD_Meta::TS_LEAD_ID, true);
      $driver_id    = (int) get_post_meta($event_id, SD_Meta::TS_DRIVER_ID, true);
      $start_ts     = (int) get_post_meta($event_id, SD_Meta::TS_START_TS, true);
      $end_ts       = (int) get_post_meta($event_id, SD_Meta::TS_END_TS, true);
      $start_lat    = (string) get_post_meta($event_id, SD_Meta::TS_START_LAT, true);
      $start_lng    = (string) get_post_meta($event_id, SD_Meta::TS_START_LNG, true);
      $payload_json = (string) get_post_meta($event_id, SD_Meta::TS_PAYLOAD_JSON, true);

      echo '<tr>';

      echo '<td class="sd-ts-code">';
      if ($start_ts > 0) {
        echo esc_html(wp_date('M j, Y g:i:s a', $start_ts));
      } else {
        echo '<span class="sd-ts-muted">—</span>';
      }
      if ($end_ts > 0) {
        echo '<br><span class="sd-ts-muted">to ' . esc_html(wp_date('M j, Y g:i:s a', $end_ts)) . '</span>';
      }
      echo '</td>';

      echo '<td class="sd-ts-code">' . esc_html($event_type !== '' ? $event_type : '—') . '</td>';

      echo '<td>' . self::truth_pill($truth_class) . '</td>';

      echo '<td class="sd-ts-code">' . esc_html($subject_type !== '' ? ($subject_type . '#' . $subject_id) : '—') . '</td>';

      echo '<td class="sd-ts-code">' . esc_html($lead_id > 0 ? (string) $lead_id : '—') . '</td>';

      echo '<td class="sd-ts-code">' . esc_html($driver_id > 0 ? (string) $driver_id : '—') . '</td>';

      echo '<td class="sd-ts-code">';
      if ($start_lat !== '' && $start_lng !== '') {
        echo esc_html(number_format((float) $start_lat, 5) . ', ' . number_format((float) $start_lng, 5));
      } else {
        echo '<span class="sd-ts-muted">—</span>';
      }
      echo '</td>';

      echo '<td class="sd-ts-payload sd-ts-code">';
      if ($payload_json !== '') {
        $decoded = json_decode($payload_json, true);
        if (is_array($decoded)) {
          echo esc_html(wp_json_encode($decoded, JSON_PRETTY_PRINT));
        } else {
          echo esc_html($payload_json);
        }
      } else {
        echo '<span class="sd-ts-muted">—</span>';
      }
      echo '</td>';

      echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
  }

  private static function truth_pill(string $truth_class) : string {
    $truth_class = strtoupper($truth_class);

    if ($truth_class === SD_Module_TimeSpaceLedger::TRUTH_OBSERVED) {
      return '<span class="sd-ts-pill sd-ts-pill--observed">OBSERVED</span>';
    }

    if ($truth_class === SD_Module_TimeSpaceLedger::TRUTH_PROJECTED) {
      return '<span class="sd-ts-pill sd-ts-pill--projected">PROJECTED</span>';
    }

    if ($truth_class === SD_Module_TimeSpaceLedger::TRUTH_COMMITTED) {
      return '<span class="sd-ts-pill sd-ts-pill--committed">COMMITTED</span>';
    }

    return '<span class="sd-ts-muted">—</span>';
  }

  private static function query_events(int $tenant_id, array $filters, int $limit) : array {
    $meta_query = [
      [
        'key'     => SD_Meta::TENANT_ID,
        'value'   => $tenant_id,
        'compare' => '=',
        'type'    => 'NUMERIC',
      ],
    ];

    if ($filters['event_type'] !== '') {
      $meta_query[] = [
        'key'     => SD_Meta::TS_EVENT_TYPE,
        'value'   => $filters['event_type'],
        'compare' => '=',
      ];
    }

    if ($filters['truth_class'] !== '') {
      $meta_query[] = [
        'key'     => SD_Meta::TS_TRUTH_CLASS,
        'value'   => $filters['truth_class'],
        'compare' => '=',
      ];
    }

    if ($filters['subject_type'] !== '') {
      $meta_query[] = [
        'key'     => SD_Meta::TS_SUBJECT_TYPE,
        'value'   => $filters['subject_type'],
        'compare' => '=',
      ];
    }

    if ($filters['lead_id'] > 0) {
      $meta_query[] = [
        'key'     => SD_Meta::TS_LEAD_ID,
        'value'   => $filters['lead_id'],
        'compare' => '=',
        'type'    => 'NUMERIC',
      ];
    }

    if ($filters['driver_id'] > 0) {
      $meta_query[] = [
        'key'     => SD_Meta::TS_DRIVER_ID,
        'value'   => $filters['driver_id'],
        'compare' => '=',
        'type'    => 'NUMERIC',
      ];
    }

    if ($filters['date_from'] !== '' || $filters['date_to'] !== '') {
      $range = [
        'key'     => SD_Meta::TS_START_TS,
        'type'    => 'NUMERIC',
      ];

      if ($filters['date_from'] !== '' && $filters['date_to'] !== '') {
        $range['value']   = [strtotime($filters['date_from'] . ' 00:00:00'), strtotime($filters['date_to'] . ' 23:59:59')];
        $range['compare'] = 'BETWEEN';
      } elseif ($filters['date_from'] !== '') {
        $range['value']   = strtotime($filters['date_from'] . ' 00:00:00');
        $range['compare'] = '>=';
      } else {
        $range['value']   = strtotime($filters['date_to'] . ' 23:59:59');
        $range['compare'] = '<=';
      }

      $meta_query[] = $range;
    }

    $ids = get_posts([
      'post_type'      => SD_Module_TimeSpaceEventCPT::CPT,
      'post_status'    => ['publish', 'private'],
      'posts_per_page' => max(1, $limit),
      'fields'         => 'ids',
      'orderby'        => 'meta_value_num',
      'meta_key'       => SD_Meta::TS_START_TS,
      'order'          => 'DESC',
      'meta_query'     => $meta_query,
    ]);

    return array_map('intval', is_array($ids) ? $ids : []);
  }

  private static function summarize_events(array $events) : array {
    $summary = [
      'total'      => count($events),
      'observed'   => 0,
      'projected'  => 0,
      'committed'  => 0,
    ];

    foreach ($events as $event_id) {
      $truth = strtoupper((string) get_post_meta($event_id, SD_Meta::TS_TRUTH_CLASS, true));

      if ($truth === SD_Module_TimeSpaceLedger::TRUTH_OBSERVED) {
        $summary['observed']++;
      } elseif ($truth === SD_Module_TimeSpaceLedger::TRUTH_PROJECTED) {
        $summary['projected']++;
      } elseif ($truth === SD_Module_TimeSpaceLedger::TRUTH_COMMITTED) {
        $summary['committed']++;
      }
    }

    return $summary;
  }

  private static function read_filters() : array {
    return [
      'event_type'   => isset($_GET['sd_ts_event_type']) ? sanitize_text_field((string) wp_unslash($_GET['sd_ts_event_type'])) : '',
      'truth_class'  => isset($_GET['sd_ts_truth_class']) ? sanitize_text_field((string) wp_unslash($_GET['sd_ts_truth_class'])) : '',
      'subject_type' => isset($_GET['sd_ts_subject_type']) ? sanitize_text_field((string) wp_unslash($_GET['sd_ts_subject_type'])) : '',
      'lead_id'      => isset($_GET['sd_ts_lead_id']) ? absint(wp_unslash($_GET['sd_ts_lead_id'])) : 0,
      'driver_id'    => isset($_GET['sd_ts_driver_id']) ? absint(wp_unslash($_GET['sd_ts_driver_id'])) : 0,
      'date_from'    => isset($_GET['sd_ts_date_from']) ? sanitize_text_field((string) wp_unslash($_GET['sd_ts_date_from'])) : '',
      'date_to'      => isset($_GET['sd_ts_date_to']) ? sanitize_text_field((string) wp_unslash($_GET['sd_ts_date_to'])) : '',
    ];
  }

  private static function event_type_options() : array {
    return class_exists('SD_TimeSpace_EventType', false) && method_exists('SD_TimeSpace_EventType', 'all')
      ? SD_TimeSpace_EventType::all()
      : [];
  }

  private static function truth_class_options() : array {
    return class_exists('SD_Module_TimeSpaceLedger', false) && method_exists('SD_Module_TimeSpaceLedger', 'truth_classes')
      ? SD_Module_TimeSpaceLedger::truth_classes()
      : [];
  }

  private static function subject_type_options() : array {
    return class_exists('SD_Module_TimeSpaceLedger', false) && method_exists('SD_Module_TimeSpaceLedger', 'subject_types')
      ? SD_Module_TimeSpaceLedger::subject_types()
      : [];
  }

  private static function is_tenant_edit_screen() : bool {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen) return false;

    return class_exists('SD_Module_TenantCPT', false)
      && $screen->base === 'post'
      && $screen->post_type === SD_Module_TenantCPT::CPT;
  }
}