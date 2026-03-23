SoloDrive Kernel — Source of Truth (v3)

Status: LOCKED
Model: Lead-Root Architecture

0. Core Principle (Non-Negotiable)

Lead is the canonical engagement root.

Everything begins with a lead.

A lead represents: “someone engaged the system”
Not all leads become rides
Rides must not represent non-committed demand
1. Entity Model (LOCKED)
sd_lead (ROOT)

The canonical lifecycle and audit record.

Owns:

sd_tenant_id
sd_trip_token
rider snapshot
intake snapshot
request mode (ASAP / reservation)
requested timing
lead lifecycle state
availability result
sd_current_quote_id
sd_current_attempt_id
sd_promoted_ride_id
sd_quote (CHILD OF LEAD)

Commercial artifact.

Owns:

sd_lead_id
pricing snapshot
service assumptions
operator adjustments
quote lifecycle state
sd_attempt (CHILD OF LEAD)

Authorization artifact.

Owns:

sd_lead_id
optional sd_quote_id
Stripe/payment references
auth lifecycle state
sd_ride (CHILD OF LEAD)

Operational trip.

Created only after successful authorization.

Owns:

sd_lead_id
dispatch data
driver assignment
ride execution lifecycle
2. Token Model (LOCKED)

/trip/<token> resolves to:

👉 sd_lead

The token:

is owned by the lead
represents the full lifecycle
is the single public surface

The token page:

reads state
renders state
does NOT store state
3. Storefront Responsibilities (LOCKED)

The storefront does ONLY:

resolve tenant
validate minimum required fields
create sd_lead
mint/store token
redirect to /trip/<token>

The storefront does NOT:

create rides
create quotes
create auth attempts
price
check availability
assign drivers
make business decisions
4. Promotion Rule (CRITICAL)

A ride is created only after successful authorization.

lead → quote → auth → ride

Never:

lead → ride
5. State Machines (LOCKED)
Lead Lifecycle
LEAD_CAPTURED
LEAD_PENDING_AVAILABILITY
LEAD_AVAILABLE
LEAD_UNAVAILABLE
LEAD_QUOTING
LEAD_QUOTED
LEAD_AUTH_PENDING
LEAD_PROMOTED
LEAD_DECLINED
LEAD_EXPIRED
Quote Lifecycle
DRAFT
PENDING_OPERATOR
APPROVED
PRESENTED
ACCEPTED
REJECTED
EXPIRED
SUPERSEDED
CANCELLED
Auth Lifecycle
AUTH_PENDING
AUTH_STARTED
AUTH_FAILED
AUTHORIZED
AUTH_EXPIRED
Ride Lifecycle
RIDE_QUEUED
RIDE_DEADHEAD
RIDE_WAITING
RIDE_INPROGRESS
RIDE_ARRIVED
RIDE_COMPLETE
RIDE_CANCELLED
6. Active Path Rule (LOCKED)

Per lead:

one active quote
one active auth attempt
zero or one ride

Historical records are preserved, but only one active commercial path exists.

7. Availability Model (LOCKED)

Availability does NOT block lead capture.

Flow:

Lead is captured
Scheduler evaluates
Lead state updated
Token reflects state
8. ASAP vs Reservation (LOCKED)

There is:

one intake
one token surface

Behavior diverges AFTER capture.

9. System Philosophy
Capture demand first
Decide later
Promote only on commitment
Keep operational records clean
10. Explicitly Removed Concepts

The following are no longer valid:

ride as intake anchor
token owned by ride
quote parented by ride
ride created before auth
storefront doing business logic
11. Final Statement

Lead is the root.
Quote is the offer.
Auth is the gate.
Ride is the commitment.