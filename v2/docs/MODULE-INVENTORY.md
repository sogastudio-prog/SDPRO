# SoloDrive Kernel — Module Inventory

## Bootstrap and shared includes

- `solodrive-kernel.php` — plugin bootstrap, activation hook, trip token hash index creation, boot trigger
- `includes/core.php` — kernel boot and health shortcode
- `includes/module-loader.php` — module discovery/registration
- `includes/sd-meta.php` — canonical meta registry
- `includes/sd-compat-meta.php` — legacy-to-canonical compatibility layer
- `includes/sd-tenant-scope.php` — tenant-scoped post-type enforcement
- `includes/utils.php` — shared utility methods
- `includes/host-canon.php` — host canonicalization helper

## Modules

### Foundation
- `005-kernel-guardrails.php` — canon-violation logging / fail-soft protection
- `006-places.php` — places integration utilities
- `010-tenant-cpt.php` — tenant CPT and tenant meta admin UI
- `012-roles-caps.php` — roles and capability setup
- `014-trip-token-index.php` — token index support
- `015-user-tenant-assignment.php` — user-to-tenant linkage
- `020-tenant-resolver.php` — tenant resolution from handle/domain/path
- `022-tenant-access.php` — tenant access enforcement
- `025-admin-user-tenant-binding.php` — admin binding tooling
- `032-route-service.php` — route service primitives

### Intake / public UX
- `035-ride-request-intake-cf7.php` — intake bridge from Contact Form 7
- `040-trip-surface.php` — `/trip/<token>` public surface
- `045-request-surface.php` — request page logic
- `trip-route-inputs.php` — routing input logic
- `160-route-inputs-ui.php` — route inputs UI helpers
- `assets/js/request-surface.js` — request-surface browser logic

### Domain records and services
- `050-ride-cpt.php` — ride CPT
- `050b-ride-metabox-capture.php` — ride payment/capture admin box
- `055-quote-cpt.php` — quote CPT
- `055b-quote-metabox-present.php` — quote presentation admin box
- `057-attempt-cpt.php` — attempt CPT
- `058-attempt-service.php` — attempt state/linkage service
- `060-ride-token-service.php` — token mint/lookup helpers
- `070-ride-state.php` — canonical ride execution lifecycle definition
- `075-quote-service.php` — quote lookup/create helpers
- `076-quote-state-service.php` — canonical quote state service
- `080-ride-state-service.php` — ride state write/read service
- `155-quote-engine.php` — quote draft build engine
- `165-ride-completion-service.php` — ride completion metrics/service

### Payments
- `111-tenant-stripe-settings.php` — tenant Stripe settings
- `112-stripe-checkout.php` — Checkout session creation
- `113-stripe-webhook.php` — webhook endpoint
- `114-stripe-return.php` — mislabeled file; defines lifecycle listener class
- `115-stripe-lifecycle-listener.php` — lifecycle listener v2.1, shadowed by class guard if `114` loads first
- `116-stripe-return.php` — Stripe return endpoint routing
- `121-payments-capture.php` — capture on ride completion

### Tenant ops and admin
- `090-admin-ride-metabox.php` — admin ride state box
- `090b-admin-ride-metadebug.php` — ride meta debug
- `090c-admin-quote-metadebug.php` — quote meta debug
- `095-admin-tenant-scope.php` — tenant-scope admin protections
- `130-dispatch-board.php` — dispatch board surface
- `140-operator-trips.php` — main tenant operator trips surface
- `141-operator-trip-actions.php` — operator ride actions
- `142-operator-location.php` — operator location / storefront state sync
- `145-operator-base-location.php` — operator base location surface
- `150-route-compute.php` — route computation layer
- `admin-view-edit-toggle.php` — admin view/edit helper

## Observations

- The module list is broad enough for a real kernel, not just a proof-of-concept.
- The heaviest concentration of code is in intake, trip surface, operator trips, and payment flow.
- Naming is mostly disciplined, but the `114`/`115` Stripe module pair needs cleanup.
