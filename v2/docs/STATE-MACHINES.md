SoloDrive State Machines

Status: LOCKED
Purpose: Define all lifecycle state models used by the SoloDrive kernel.

SoloDrive uses three independent state machines:

Lead lifecycle (ride acquisition)

Quote lifecycle (pricing decision)

Ride lifecycle (trip execution)

These models must never be merged.

This separation prevents operational ambiguity.

1. Lead Lifecycle

Stored on Ride

Meta key:

sd_lead_status

States:

LEAD_CAPTURED
LEAD_WAITING_QUOTE
LEAD_OFFERED
LEAD_PROMOTED
LEAD_DECLINED
LEAD_EXPIRED
LEAD_AUTH_FAILED

Meaning:

State	Meaning
LEAD_CAPTURED	Ride lead created from storefront
LEAD_WAITING_QUOTE	Quote engine preparing quote
LEAD_OFFERED	Quote ready and presented
LEAD_PROMOTED	Lead becomes operational ride
LEAD_DECLINED	Passenger rejected quote
LEAD_EXPIRED	Quote expired
LEAD_AUTH_FAILED	Stripe authorization failed
2. Quote Lifecycle

Stored on Quote

Meta key:

sd_quote_status

States:

PROPOSED
APPROVED
PRESENTED
USER_REJECTED
USER_TIMEOUT
LEAD_ACCEPTED
PAYMENT_PENDING
LEAD_REJECTED
EXPIRED
SUPERSEDED
CANCELLED

Important rule:

Only the PRESENTED state allows human decision.

Decision surface:

/trip/<token>

The quote lifecycle exists to control pricing decisions and prevent race conditions.

3. Ride Lifecycle

Stored on Ride

Meta key:

sd_ride_state

States:

RIDE_QUEUED
RIDE_DEADHEAD
RIDE_WAITING
RIDE_INPROGRESS
RIDE_ARRIVED
RIDE_COMPLETE
RIDE_CANCELLED

Meaning:

State	Meaning
RIDE_QUEUED	Ride accepted but not started
RIDE_DEADHEAD	Driver en route to pickup
RIDE_WAITING	Driver waiting for passenger
RIDE_INPROGRESS	Passenger onboard
RIDE_ARRIVED	Passenger dropped off
RIDE_COMPLETE	Ride completed
RIDE_CANCELLED	Ride cancelled
4. Lifecycle Interaction
Storefront
   ↓
Ride Created (LEAD_CAPTURED)
   ↓
Quote Engine
   ↓
Quote PRESENTED
   ↓
Passenger Decision
   ↓
Stripe Authorization
   ↓
Ride PROMOTED
   ↓
Ride Execution Lifecycle
5. Invariants

The following rules are kernel guardrails:

Ride lifecycle cannot begin until lead is PROMOTED.

Quote cannot move to PAYMENT_PENDING unless Stripe authorization exists.

Only one active quote may exist per ride.

Completed rides must trigger payment capture.