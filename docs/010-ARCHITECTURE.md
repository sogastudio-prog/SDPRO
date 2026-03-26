# SoloDrive Kernel — Architecture

Status: ACTIVE  
Purpose: Define the current working architecture, the target canonical architecture, and the migration direction between them.

---

## Document Intent

This document describes both:

1. the **current working system** that has already reached payment capture, and
2. the **target canonical system** the platform is moving toward.

Where they differ, that difference must be stated explicitly.

Rule:

> New development should move toward the target canonical model without misrepresenting the current runtime.

---

## Runtime Shape

WordPress is the runtime shell.  
The SoloDrive plugin provides the kernel layer that defines:

- tenant identity and resolution
- canonical metadata contracts
- token-routed public access
- quote, auth-attempt, ride, and capture workflows
- Stripe authorization/capture pathways
- operator/admin surfaces

---

## Architecture Posture

SoloDrive is platform-first and tenant-scoped.

Deployment posture:

- `solodrive.pro` = public marketing site
- `app.solodrive.pro` = application runtime shell
- `{tenant}.solodrive.pro` = tenant entry and tenant context resolver

After public entry, token-based flows should be resolved from the record, not from the URL.

---

## Canonical Direction vs Current Implementation

### Target Canon (LOCKED DIRECTION)

```txt
platform → tenant → lead → quote → auth_attempt → ride → capture

Meaning:

Lead owns visitor engagement lifecycle
Quote is reviewed before customer presentation
Auth attempt is the payment commitment layer
Ride is created only after authorization
Capture occurs after completion
Current Implementation (WORKING TODAY)
platform → tenant → ride → quote → attempt → capture

Meaning:

sd_ride currently acts as intake spine and lifecycle anchor
quote is currently linked to ride
attempt is currently linked into the ride-first model
the system has already reached payment capture under this structure
quote approval / presented-quote flow is not yet fully enforced end-to-end
lead-first architecture is documented as the target, not yet the governing runtime
Transitional Rule

The current ride-first implementation is valid as the working system of record until lead-first lifecycle ownership is implemented in code.

Corollary:

Docs must distinguish current runtime truth from target canonical direction.

Storefront Role

The storefront captures transportation intent.

Target Role

The storefront should:

collect minimum viable data
resolve and attach sd_tenant_id
create a lead record
mint a token
store the token on the lead
redirect to /trip/<token>
Current Role

Today, the storefront effectively:

collects minimum viable data
resolves tenant context
creates the current intake/lifecycle record (sd_ride)
mints a token
redirects to /trip/<token>

Rule:

The storefront must not be documented as creating a post-auth operational ride in the target model.

Principal Records
Tenant
CPT: sd_tenant
Purpose: first-class tenant record storing slug, domain, storefront config, and Stripe settings
Ride (Current Primary Record — Transitional)
CPT: sd_ride
Current role:
intake record
token anchor
lifecycle owner
operational trip spine
payment/capture linkage root
Common meta in current model:
sd_tenant_id
sd_trip_token
sd_lead_status (transitional naming on ride)
sd_ride_state
pickup/dropoff fields
timing/logistics fields

Transitional note:

sd_ride currently governs engagement flow, but this is a temporary implementation posture, not the target architectural doctrine.

Lead (Target Primary Record — Planned)
CPT: sd_lead
Target role:
intake record
lifecycle owner
token anchor
parent engagement identifier

Expected meta:

sd_tenant_id
sd_trip_token
stage/lifecycle meta
pickup/dropoff fields
requested time
customer identity fields

Target rule:

Lead will become the canonical parent of quote, auth-attempt, ride, and payment capture progression.

Quote
CPT: sd_quote
Purpose: pricing and decision record
Current linkage
linked to ride via sd_ride_id
Target linkage
linked to lead via sd_lead_id

Common meta:

sd_quote_status
_sd_quote_draft_json
relationship field to parent engagement record
Attempt
CPT: sd_attempt
Purpose: canonical Stripe authorization attempt / payment correlation record
Current linkage
linked to ride and quote
Target linkage
linked to lead and quote

Common meta:

attempt status
Stripe session id
Stripe payment intent id
Stripe event id
quote linkage
tenant linkage
Public-Facing Flow
Current Working Flow
Storefront submission
  → current intake record created (ride-first today)
  → quote created/attached
  → token minted
  → /trip/<token>
  → payment authorization
  → capture

This describes the working path that has already reached payment capture.

Target Canonical Flow
Storefront submission
  → lead created
  → quote draft created
  → tenant approves / adjusts / rejects
  → presented quote shown on /trip/<token>
  → auth attempt created on user acceptance
  → ride created after successful authorization
  → capture after completion
Quote Decision Gate

This is part of the target model and should guide current design direction.

Target flow:

quote draft is generated in response to an engagement record
tenant approves, adjusts, or rejects it
only an approved/presented quote becomes customer-visible

Rules:

no unapproved quote may be shown to the customer
only one presented quote may be active at a time
quote creation must be idempotent
passive reads must not create quotes
Stripe Authorization
Current Working Behavior
authorization exists in the current ride-first flow
attempt records correlate Stripe behavior to the current engagement structure
the system has already reached capture under this model
Target Behavior
authorization begins only after a presented quote is accepted
auth attempt becomes the commitment boundary
ride is created only after successful authorization

Rule:

Authorization is the commit point in the target architecture.

Ride Execution and Completion

Once a ride exists, operator surfaces manage:

queue / deadhead / waiting / in-progress / arrived / complete transitions
route inputs and completion metrics
final capture workflow on completion

In the current system, ride already exists earlier than target.
In the target system, ride becomes the post-auth execution object only.

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
075-quote-service.php
076-quote-state-service.php
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
admin metabox / debug modules
State Model
Lead Lifecycle

Target canonical owner: lead
Current storage in working system: transitional lead-stage meta on ride

Examples:

LEAD_CAPTURED
LEAD_WAITING_QUOTE
LEAD_OFFERED
LEAD_PROMOTED

Meaning:

these states describe engagement progression
they should ultimately belong to sd_lead
today they may still be carried on the current ride-first spine
Quote Lifecycle

Stored on quote in sd_quote_status

Working / planned states include:

PROPOSED
APPROVED
PRESENTED
LEAD_ACCEPTED
PAYMENT_PENDING
USER_REJECTED
USER_TIMEOUT
EXPIRED
SUPERSEDED
CANCELLED

Rule:

Only the approved/presented quote may be shown to the customer.

Ride Lifecycle

Stored on ride in sd_ride_state

States:

RIDE_QUEUED
RIDE_DEADHEAD
RIDE_WAITING
RIDE_INPROGRESS
RIDE_ARRIVED
RIDE_COMPLETE
RIDE_CANCELLED
Availability Posture

Canonical rule:

Assume availability unless constrained.

This means:

tenant does not need to pre-seed availability blocks for the system to function
missing schedule configuration must not block lead/intake capture
assignment is a constraint-resolution problem, not a required precondition for entry

Mental model:

ASSUME YES → PROVE NO
Operator Surfaces

Operator/admin surfaces exist to manage the operational layer, not to redefine lifecycle ownership.

Examples:

dispatch board
operator trip actions
operator location
admin metaboxes and debug views

Rule:

Admin/operator tools must respect canonical lifecycle boundaries rather than silently creating new ones.

Core Rule

Nothing creates downstream objects unless the state machine explicitly allows it.


---


