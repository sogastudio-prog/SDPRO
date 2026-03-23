<?php
if (!defined('ABSPATH')) { exit; }

interface SD_StorefrontWorkflowAdapterInterface {

  public function key() : string;

  /**
   * Render the workflow UI for the given decision.
   *
   * @param array<string,mixed> $args
   */
  public function render(SD_StorefrontDecision $decision, array $args = []) : string;

  /**
   * Handle a submission and return normalized result data.
   *
   * @param array<string,mixed> $request
   * @return array<string,mixed>
   */
  public function handle_submission(SD_StorefrontDecision $decision, array $request) : array;
}