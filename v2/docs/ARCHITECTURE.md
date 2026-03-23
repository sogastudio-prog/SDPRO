# SoloDrive Kernel — Reconstructed Architecture

## Runtime shape

WordPress is the runtime shell. The plugin provides a kernel layer that defines:
- tenant identity and resolution
- canonical metadata contracts
- ride, quote, attempt, and token workflows
- Stripe authorization/capture pathways
- operator/admin surfaces

## Principal records

### Tenant
- CPT: `sd_tenant`
- Purpose: first-class tenant record storing slug/domain/storefront/Stripe settings

### Ride
- CPT: `sd_ride`
- Purpose: intake record and operational trip spine
- Core meta: `sd_trip_token`, `sd_lead_status`, `sd_ride_state`, pickup/dropoff fields

### Quote
- CPT: `sd_quote`
- Purpose: pricing/decision record associated to a ride
- Core meta: `sd_ride_id`, `sd_quote_status`, `_sd_quote_draft_json`

### Attempt
- CPT: `sd_attempt`
- Purpose: canonical Stripe transaction/authorization attempt record
- Core meta: attempt status, ride link, quote link, Stripe session/payment_intent/event ids

## Public-facing flow

### 1) Intake
Primary intake path is a CF7-based request surface.
Expected flow:
- customer submits logistics
- ride is created
- quote is created or attached
- trip token is minted/indexed
- Stripe authorization flow is initiated
- user is redirected to `/trip/<token>`

### 2) Trip decision surface
`/trip/<token>` acts as the live status and decision surface.
Behavior implied by code and architecture notes:
- uncached token-routed access
- quote details are read from quote draft JSON
- decision UI appears when quote is in the presented state
- payment outcome banners can be reflected via query args

### 3) Stripe authorization
Foundation path:
- checkout session is created
- canonical attempt record stores Stripe linkage
- webhook resolves session → attempt
- authorization event advances attempt/quote/ride lifecycle

### 4) Ride execution and completion
After authorization, the ride enters dispatchable execution state.
Operator surfaces then manage:
- deadhead / waiting / in-progress / arrived / complete transitions
- route inputs and completion metrics
- final capture workflow on completion

## Module groupings

### Kernel/foundation
- `005-kernel-guardrails.php`
- `010-tenant-cpt.php`
- `020-tenant-resolver.php`
- `022-tenant-access.php`
- `014-trip-token-index.php`
- `060-ride-token-service.php`

### Intake and public surface
- `035-ride-request-intake-cf7.php`
- `040-trip-surface.php`
- `045-request-surface.php`
- `trip-route-inputs.php`
- `160-route-inputs-ui.php`

### Lifecycle and domain services
- `070-ride-state.php`
- `076-quote-state-service.php`
- `075-quote-service.php`
- `080-ride-state-service.php`
- `155-quote-engine.php`
- `165-ride-completion-service.php`

### Payments
- `057-attempt-cpt.php`
- `058-attempt-service.php`
- `111-tenant-stripe-settings.php`
- `112-stripe-checkout.php`
- `113-stripe-webhook.php`
- `115-stripe-lifecycle-listener.php` or its shadowed predecessor in `114`
- `116-stripe-return.php`
- `121-payments-capture.php`

### Tenant/operator/admin surfaces
- `130-dispatch-board.php`
- `140-operator-trips.php`
- `141-operator-trip-actions.php`
- `142-operator-location.php`
- `145-operator-base-location.php`
- admin metabox/debug modules

## State model

### Lead lifecycle on ride
Stored in `sd_lead_status`
Examples from doctrine:
- `LEAD_CAPTURED`
- `LEAD_WAITING_QUOTE`
- `LEAD_OFFERED`
- `LEAD_PROMOTED`

### Quote lifecycle on quote
Stored in `sd_quote_status`
Core working states in code:
- `PROPOSED`
- `PRESENTED`
- `PAYMENT_PENDING`

Reserved/available states also exist in code:
- `APPROVED`
- `LEAD_ACCEPTED`
- `USER_REJECTED`
- `USER_TIMEOUT`
- `EXPIRED`
- `CANCELLED`

### Ride execution lifecycle on ride
Stored in `sd_ride_state`
Code-defined states:
- `RIDE_QUEUED`
- `RIDE_DEADHEAD`
- `RIDE_WAITING`
- `RIDE_INPROGRESS`
- `RIDE_ARRIVED`
- `RIDE_COMPLETE`
- `RIDE_CANCELLED`

## Intended platform posture

This kernel is not brand-first. It is platform-first and tenant-scoped, with SoGa-Go positioned as the first tenant and post-launch proving ground. That matches the uploaded doctrine and project scope.
