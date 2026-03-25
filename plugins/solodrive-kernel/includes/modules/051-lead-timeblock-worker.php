<?php
if (!defined('ABSPATH')) { exit; }

/**
 * SD_Module_LeadTimeblockWorker
 *
 * Purpose:
 * - Perform the real availability/timeblock job for a lead
 * - Write the lead's availability result
 * - Advance the lead from LEAD_NEEDS_TIMEBLOCK to LEAD_NEEDS_QUOTE
 *
 * Canon:
 * - Lead is the orchestration root
 * - This worker does one job only: availability/timeblock evaluation
 * - This worker does NOT create quotes, attempts, or rides
 * - Stage engine remains the only orchestrator
 *
 * v1 strategy:
 * - Do not allocate/hold blocks yet
 * - Do not touch reservation supply accounting yet
 * - Simply evaluate whether lead timing is serviceable enough to proceed
 * - Write a canonical result to AVAILABILITY_STATUS
 *
 * Result values:
 * - available
 * - unavailable
 */

if (class_exists('SD_Module_LeadTimeblockWorker', false)) { return; }

final class SD_Module_LeadTimeblockWorker {

  private const JOB_STATE_KEY = '_sd_timeblock_job_state';

  public static function register() : void {
    add_action('sd_core_stage_LEAD_NEEDS_TIMEBLOCK', [__CLASS__, 'handle_stage'], 10, 3);
  }

  /**
   * Hook signature from SD_CoreStage:
   * do_action('sd_core_stage_' . $stage, $lead_id, $from_stage, $reason);
   */
  public static function handle_stage($lead_id, $from_stage = '', $reason = '') : void {
    $lead_id = absint($lead_id);
    if ($lead_id <= 0) return;
    if (get_post_type($lead_id) !== SD_Module_LeadCPT::CPT) return;

    if (!class_exists('SD_CoreStage', false)) return;

    // Hard stage guard.
    if (SD_CoreStage::current_stage($lead_id) !== SD_CoreStage::LEAD_NEEDS_TIMEBLOCK) {
      return;
    }

    // Idempotency guard: if a non-pending result already exists, just advance or stop.
    $availability = trim((string) get_post_meta($lead_id, SD_Meta::AVAILABILITY_STATUS, true));
    if ($availability === 'available') {
      delete_post_meta($lead_id, SD_Meta::P_AVAILABILITY_REASON);
      update_post_meta($lead_id, self::JOB_STATE_KEY, 'ok');

      SD_CoreStage::advance(
        $lead_id,
        SD_CoreStage::LEAD_NEEDS_QUOTE,
        'Availability already confirmed.'
      );
      return;
    }

    if ($availability === 'unavailable') {
      delete_post_meta($lead_id, SD_Meta::P_AVAILABILITY_REASON);
      update_post_meta($lead_id, self::JOB_STATE_KEY, 'ok');
      return;
    }

    // Concurrency guard.
    $job_state = (string) get_post_meta($lead_id, self::JOB_STATE_KEY, true);
    if ($job_state === 'running') {
      return;
    }

    update_post_meta($lead_id, self::JOB_STATE_KEY, 'running');
    delete_post_meta($lead_id, SD_Meta::P_AVAILABILITY_REASON);

    try {
      $tenant_id = absint(get_post_meta($lead_id, SD_Meta::TENANT_ID, true));
      if ($tenant_id <= 0) {
        throw new \Exception('Missing tenant_id on lead.');
      }

      $requested_ts = absint(get_post_meta($lead_id, SD_Meta::REQUESTED_TS, true));
      if ($requested_ts <= 0) {
        throw new \Exception('Missing requested timestamp on lead.');
      }

      $request_mode = strtoupper(trim((string) get_post_meta($lead_id, SD_Meta::REQUEST_MODE, true)));
      if ($request_mode === '') {
        $request_mode = 'ASAP';
      }

      $result = self::evaluate_availability($lead_id, $tenant_id, $requested_ts, $request_mode);

      if (empty($result['ok'])) {
        throw new \Exception((string) ($result['error'] ?? 'Availability evaluation failed.'));
      }

      $availability = (string) ($result['availability'] ?? 'unavailable');
      $reason_text  = (string) ($result['reason'] ?? '');

      update_post_meta($lead_id, SD_Meta::AVAILABILITY_STATUS, $availability);

      if ($reason_text !== '') {
        update_post_meta($lead_id, SD_Meta::P_AVAILABILITY_REASON, $reason_text);
      } else {
        delete_post_meta($lead_id, SD_Meta::P_AVAILABILITY_REASON);
      }

      update_post_meta($lead_id, self::JOB_STATE_KEY, 'ok');

      if ($availability === 'available') {
        SD_CoreStage::advance(
          $lead_id,
          SD_CoreStage::LEAD_NEEDS_QUOTE,
          'Availability confirmed.'
        );
        return;
      }

      // Stay out of quote path if unavailable.
      if (class_exists('SD_Util')) {
        SD_Util::log('lead_timeblock_unavailable', [
          'lead_id'      => $lead_id,
          'tenant_id'    => $tenant_id,
          'request_mode' => $request_mode,
          'reason'       => $reason_text,
        ]);
      }

    } catch (\Throwable $e) {
      update_post_meta($lead_id, self::JOB_STATE_KEY, 'error');
      update_post_meta($lead_id, SD_Meta::P_AVAILABILITY_REASON, $e->getMessage());
      update_post_meta($lead_id, SD_Meta::AVAILABILITY_STATUS, 'unavailable');
    }
  }

  /**
   * v1 availability evaluator.
   *
   * Rules:
   * - ASAP:
   *   - generally allowed
   *   - storefront must be open/accepting if gate exists
   *
   * - RESERVE:
   *   - requested_ts must be in the future
   *   - if tenant advance-booking max days exists, honor it
   *
   * This keeps the worker real but intentionally light until block inventory
   * and held/committed supply are fully enforced.
   */
  private static function evaluate_availability(int $lead_id, int $tenant_id, int $requested_ts, string $request_mode) : array {
    $now = current_time('timestamp');

    if (class_exists('SD_StorefrontGate', false)) {
      $gate = SD_StorefrontGate::evaluate($tenant_id);
      if (empty($gate['can_render_request_form'])) {
        return [
          'ok'           => true,
          'availability' => 'unavailable',
          'reason'       => isset($gate['message']) ? (string) $gate['message'] : 'Storefront is not accepting requests.',
        ];
      }
    }

    if ($request_mode === 'RESERVE') {
      if ($requested_ts <= $now) {
        return [
          'ok'           => true,
          'availability' => 'unavailable',
          'reason'       => 'Reservation time must be in the future.',
        ];
      }

      $max_days = (int) get_post_meta($tenant_id, SD_Meta::ADVANCE_BOOKING_MAX_DAYS, true);
      if ($max_days > 0) {
        $max_ts = strtotime('+' . $max_days . ' days', $now);
        if ($max_ts && $requested_ts > $max_ts) {
          return [
            'ok'           => true,
            'availability' => 'unavailable',
            'reason'       => 'Requested reservation is outside the current advance booking window.',
          ];
        }
      }

      return [
        'ok'           => true,
        'availability' => 'available',
        'reason'       => 'Reservation timing accepted.',
      ];
    }

    // ASAP path
    return [
      'ok'           => true,
      'availability' => 'available',
      'reason'       => 'ASAP request accepted.',
    ];
  }
}