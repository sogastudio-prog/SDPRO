<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontDecision {
  public int $tenant_id;
  public string $public_state;       // open|busy|closed
  public string $availability_mode;  // instant|stacked_asap|waitlist|reserve_only|unavailable
  public string $reason_code;
  public string $headline;
  public string $message;
  public string $primary_cta;
  public string $secondary_cta;
  public string $workflow_type;

  /** @var array<string,mixed> */
  public array $meta = [];

  public function __construct(
    int $tenant_id,
    string $public_state,
    string $availability_mode,
    string $reason_code,
    string $headline = '',
    string $message = '',
    string $primary_cta = '',
    string $secondary_cta = '',
    string $workflow_type = '',
    array $meta = []
  ) {
    $this->tenant_id          = $tenant_id;
    $this->public_state       = $public_state;
    $this->availability_mode  = $availability_mode;
    $this->reason_code        = $reason_code;
    $this->headline           = $headline;
    $this->message            = $message;
    $this->primary_cta        = $primary_cta;
    $this->secondary_cta      = $secondary_cta;
    $this->workflow_type      = $workflow_type;
    $this->meta               = $meta;
  }

  public function to_array() : array {
    return [
      'tenant_id'         => $this->tenant_id,
      'public_state'      => $this->public_state,
      'availability_mode' => $this->availability_mode,
      'reason_code'       => $this->reason_code,
      'headline'          => $this->headline,
      'message'           => $this->message,
      'primary_cta'       => $this->primary_cta,
      'secondary_cta'     => $this->secondary_cta,
      'workflow_type'     => $this->workflow_type,
      'meta'              => $this->meta,
    ];
  }
}