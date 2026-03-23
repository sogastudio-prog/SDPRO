SoloDrive Kernel Guardrails

Status: LOCKED
Purpose: Prevent architectural drift and enforce platform doctrine.

The SoloDrive kernel is a tenant-first transportation platform runtime.
These guardrails define the non-negotiable rules governing the codebase.

All kernel modules must follow these rules.

Violations should trigger kernel guardrail warnings.

1. Kernel Philosophy

SoloDrive is designed around a small set of core invariants.

The platform must always maintain:

• tenant isolation
• deterministic state machines
• canonical metadata contracts
• operational traceability
• uncached public decision surfaces

2. Canonical Meta Key Doctrine

All system metadata must follow a strict naming convention.

Public metadata:

sd_*

Private/system metadata:

_sd_*

Legacy SoGa-Go keys may exist temporarily but must pass through the compatibility layer.

Example canonical keys:

sd_tenant_id
sd_trip_token
sd_lead_status
sd_ride_state
sd_quote_status

Example private keys:

_sd_quote_draft_json
_sd_exec_phase
_sd_stripe_session
3. Record Ownership Rules

Each record type has clear ownership boundaries.

Record	Owner
Ride	Operational pipeline
Quote	Pricing pipeline
Attempt	Payment pipeline
Tenant	Platform configuration

Modules must not cross-write domain responsibilities.

Example:

Ride modules must not modify quote states directly.

4. State Machine Discipline

SoloDrive uses three separate lifecycle state machines.

They must remain independent.

Lead lifecycle
Quote lifecycle
Ride lifecycle

A module may only control the state machine it owns.

Example:

Quote service controls:

sd_quote_status

Ride service controls:

sd_ride_state
5. Tenant Isolation

Every tenant-scoped record must include:

sd_tenant_id

This applies to:

sd_ride
sd_quote
sd_attempt
sd_waitlist

Kernel queries must always include tenant scope.

Example:

'meta_query' => [
  [
    'key' => 'sd_tenant_id',
    'value' => $tenant_id
  ]
]

Failure to enforce tenant scope is a critical violation.

6. Token-Based Public Access

Public surfaces must use token access, not authentication.

Example:

/trip/<token>

Tokens must:

be random
be indexed
support rotation
never expose internal IDs

Public surfaces must remain uncached.

7. Admin Interface Rules

Admin surfaces must follow a strict pattern.

Rule:

Admin views are read-only by default.

Editing must require explicit action.

Pattern:

View Mode
  ↓
Edit Button
  ↓
Editable Form
  ↓
Save Action

This prevents accidental state mutation.

8. Module Boundaries

Each kernel module must have a single responsibility.

Example module categories:

Foundation
tenant resolver
role setup
token services
Domain
ride services
quote services
attempt services
Infrastructure
Stripe integration
route services
external APIs
Surfaces
trip surface
operator surfaces
dispatch board
storefront

Modules must never mix concerns.

9. Storefront Separation

The storefront must remain independent of the ride pipeline.

Storefront responsibilities:

availability evaluation
customer workflow selection
lead creation

The storefront must not manage rides directly.

10. Payment Integrity

All Stripe operations must pass through the Attempt model.

Stripe identifiers must never be stored directly on ride records.

Canonical correlation record:

sd_attempt

Attempt records store:

stripe_session_id
payment_intent
event_id
11. Kernel Logging

The kernel must provide structured logging.

Log entries should include:

timestamp
module
event
context

Example:

[solodrive] {"event":"ride_state_transition","ride_id":123}
12. Query Performance Rules

Kernel queries must:

• avoid full-table scans
• use indexed meta keys
• avoid unnecessary joins

Critical indexes include:

sd_trip_token
sd_tenant_id
sd_quote_status
sd_ride_state
13. Public Surface Caching

Certain routes must never be cached.

/trip/*
/driver/*
/dispatch/*

These routes represent live operational surfaces.

14. Backward Compatibility

The kernel includes a compatibility layer.

Legacy keys:

sog_trip_token
sog_lead_status
state
sog_quote_status

These map to canonical equivalents.

The compatibility layer exists only for migration.

15. Kernel Versioning

Kernel releases must include a defined constant.

Example:

SD_KERNEL_VERSION

The health endpoint should expose:

• kernel version
• Stripe library status
• module load status

16. Release Hygiene

Release packages must exclude:

.DS_Store
node_modules
dev artifacts
test data

Kernel releases must include:

/docs
plugin bootstrap
modules
assets
vendor libraries
17. Guardrail Violations

Guardrail violations should:

log a warning

fail soft

notify admin

The system must never hard-fail in production.

18. Guiding Principle

The SoloDrive kernel must remain:

deterministic
traceable
tenant-isolated
operationally reliable

If a change compromises any of those properties, it must not be merged.

End of Document