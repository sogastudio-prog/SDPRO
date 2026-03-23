<?php
if (!defined('ABSPATH')) { exit; }

final class SD_StorefrontWorkflowRegistry {

  /** @var array<string,SD_StorefrontWorkflowAdapterInterface> */
  private array $adapters = [];

  public function register(SD_StorefrontWorkflowAdapterInterface $adapter) : void {
    $this->adapters[$adapter->key()] = $adapter;
  }

  public function get_adapter(string $workflow_type) : ?SD_StorefrontWorkflowAdapterInterface {
    $parts = explode('.', $workflow_type, 2);
    $adapter_key = $parts[0] ?? '';
    return $this->adapters[$adapter_key] ?? null;
  }

  public function render_workflow(SD_StorefrontDecision $decision, array $workflow_config = []) : string {
    if (!$decision->workflow_type) return '';

    $adapter = $this->get_adapter($decision->workflow_type);
    if (!$adapter) {
      return '<div class="sd-card"><p>Workflow adapter not found.</p></div>';
    }

    return $adapter->render($decision, $workflow_config);
  }
}