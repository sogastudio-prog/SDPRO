<?php
if (!defined('ABSPATH')) { exit; }

final class SD_CF7WorkflowAdapter implements SD_StorefrontWorkflowAdapterInterface {

  public function key() : string {
    return 'cf7';
  }

  public function render(SD_StorefrontDecision $decision, array $args = []) : string {
    $form_id = isset($args['form_id']) ? (int) $args['form_id'] : 0;

    if ($form_id < 1) {
      return '<div class="sd-card"><p>Workflow is not configured.</p></div>';
    }

    // Hidden context fields can later be injected via shortcode attrs / hooks.
    return do_shortcode(sprintf('[contact-form-7 id="%d"]', $form_id));
  }

  public function handle_submission(SD_StorefrontDecision $decision, array $request) : array {
    // In v1, CF7 remains responsible for submission.
    // This is the seam where legacy CF7 flows can later normalize into kernel submission logic.
    return [
      'ok' => true,
      'adapter' => 'cf7',
      'message' => 'Submission handling delegated to CF7.',
    ];
  }
}