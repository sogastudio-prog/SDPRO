SoloDrive Kernel — Reconstructed Architecture
Runtime Shape

WordPress is the runtime shell. The plugin provides a kernel layer that defines:

platform and tenant identity resolution
canonical metadata contracts
lead-owned lifecycle progression
quote, auth-attempt, ride, and token workflows
Stripe authorization and capture pathways
operator and admin surfaces
Canonical Lifecycle Ownership

The platform is lead-first, not ride-first.

Canonical hierarchy:

platform → tenant → lead → quote → auth_attempt → ride → capture

Rule:

sd_lead owns the visitor engagement lifecycle and stage progression.
Downstream records may be created only when explicitly allowed by the state machine.

Storefront Role

The storefront captures a Lead.

It is responsible for:

collecting minimum viable data
resolving and attaching sd_tenant_id
creating a lead record
minting a token
storing the token on the lead
redirecting to /trip/<token>

The storefront does not create a ride.

Principal Records
Tenant
CPT: sd_tenant
Purpose: first-class tenant record storing slug, domain, storefront configuration, and Stripe settings
Lead
CPT: sd_lead
Purpose: canonical intake and lifecycle owner for a transportation request
Core meta:
sd_tenant_id
sd_trip_token
lifecycle/stage meta
pickup/dropoff fields
requested time
customer identity fields
Quote
CPT: sd_quote
Purpose: pricing and decision record associated to a lead
Core meta:
sd_lead_id
sd_quote_status
_sd_quote_draft_json
Attempt
CPT: sd_attempt
Purpose: canonical Stripe authorization attempt record
Core meta:
sd_lead_id
sd_quote_id
attempt status
Stripe session / payment_intent / event ids
Ride
CPT: sd_ride
Purpose: operational execution record created only after successful authorization
Core meta:
sd_tenant_id
sd_lead_id
sd_ride_state
dispatch / route / completion fields
Public-Facing Flow
1) Intake

Primary intake path is a CF7-based request surface.

Expected flow:

customer submits logistics
tenant is resolved
lead is created
trip token is minted and indexed
user is redirected to /trip/<token>

The intake phase does not create a ride.
The intake phase does not initiate Stripe automatically unless the state machine has advanced to a presented quote and the lead is taking an explicit action.

2) Trip Surface

/trip/<token> acts as the live public status and decision surface.

Behavior:

uncached token-routed access
token resolves lead first, then tenant context
quote visibility depends on quote state
decision UI appears only when an approved/presented quote is active
payment outcome banners may be reflected via query args
third-party passenger views may show logistics only, without pricing/payment details

Rule:

After token entry, public context is owned by the lead record, not by URL tenant resolution.

3) Quote Decision Gate

Quotes are created in response to a lead and must pass through tenant/operator review before being customer-visible.

Expected flow:

quote draft is generated for the lead
tenant approves, adjusts, or rejects
only an approved/presented quote becomes visible on /trip/<token>

Rules:

no unapproved quote may be shown to the lead
multiple active quotes for the same lead must be prevented by idempotency
quote creation must not occur from passive page reads
4) Stripe Authorization

Authorization begins only after the lead accepts the presented quote.

Foundation path:

lead accepts presented quote
checkout session or authorization flow is created
canonical attempt record stores Stripe linkage
webhook resolves Stripe event to attempt
authorization outcome advances attempt / quote / lead lifecycle

Rule:

Authorization is the commit point.
No ride may be created before successful authorization.

5) Ride Execution and Completion

After successful authorization, the ride is created and enters dispatchable execution state.

Operator surfaces then manage:

queue / deadhead / waiting / in-progress / arrived / complete transitions
route inputs and completion metrics
final capture workflow on completion
Module Groupings
Kernel / Foundation
005-kernel-guardrails.php
010-tenant-cpt.php
020-tenant-resolver.php
022-tenant-access.php
014-trip-token-index.php
060-ride-token-service.php
Intake and Public Surface
035-ride-request-intake-cf7.php
040-trip-surface.php
045-request-surface.php
trip-route-inputs.php
160-route-inputs-ui.php
Lifecycle and Domain Services
070-ride-state.php
076-quote-state-service.php
075-quote-service.php
080-ride-state-service.php
155-quote-engine.php
165-ride-completion-service.php
Payments
057-attempt-cpt.php
058-attempt-service.php
111-tenant-stripe-settings.php
112-stripe-checkout.php
113-stripe-webhook.php
115-stripe-lifecycle-listener.php
116-stripe-return.php
121-payments-capture.php
Tenant / Operator / Admin Surfaces
130-dispatch-board.php
140-operator-trips.php
141-operator-trip-actions.php
142-operator-location.php
145-operator-base-location.php
admin metabox and debug modules
State Model
Lead lifecycle on lead

Stored in canonical lead stage / lifecycle meta.

Examples:

LEAD_CAPTURED
LEAD_WAITING_QUOTE
LEAD_OFFERED
LEAD_PROMOTED

Lead is the canonical owner of visitor engagement progression.

Quote lifecycle on quote

Stored in sd_quote_status

Working states:

PROPOSED
PRESENTED
PAYMENT_PENDING

Reserved / available states:

APPROVED
LEAD_ACCEPTED
USER_REJECTED
USER_TIMEOUT
EXPIRED
CANCELLED

Operational rule:

Only the approved/presented quote may be shown to the lead.

Ride execution lifecycle on ride

Stored in sd_ride_state

Code-defined states:

RIDE_QUEUED
RIDE_DEADHEAD
RIDE_WAITING
RIDE_INPROGRESS
RIDE_ARRIVED
RIDE_COMPLETE
RIDE_CANCELLED
Availability Posture

The platform assumes availability by default and applies constraints later.

This means:

tenants do not need to pre-seed available time blocks
missing availability configuration must not block initial lead capture
assignment is a constraint-resolution problem, not a precondition for intake

Rule:

Assume yes, then prove no.

Intended Platform Posture

The kernel is platform-first and tenant-scoped, with SoGa-Go positioned as Tenant-001 and post-launch proving ground.

The architecture is designed so that:

solodrive.pro serves as the public marketing site
app.solodrive.pro serves as the authenticated/system runtime shell
{tenant}.solodrive.pro serves as tenant entry and context resolution
token-based public flows remain lead-owned after entry
Core Rule

Nothing creates downstream objects unless the state machine explicitly allows it.