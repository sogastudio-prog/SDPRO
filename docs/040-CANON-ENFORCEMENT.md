# Replacement: `040-CANON-ENFORCEMENT.md`

```md
# Canon Enforcement

Status: LOCKED  
Purpose: Prevent architectural drift, naming drift, and reintroduction of invalid patterns during the transition from ride-first runtime to lead-first canon.

---

## Document Rule

A new document may only be created if:

- it cannot logically fit inside an existing doc
- it will be used during active development
- it defines behavior, not opinion

Otherwise:

> extend an existing doc

---

## Canon vs Current Rule

The platform has:

1. a **current working runtime** and
2. a **target canonical architecture**

Both may be documented.

But:

- current runtime must not be misrepresented as target canon
- target canon must not be written as though already implemented when it is not
- every meaningful difference must be labeled explicitly

---

## 1. Entity Hierarchy (TARGET CANON)

```txt
platform → tenant → lead → quote → auth_attempt → ride → capture

This is the intended long-term architecture.

2. Current Transitional Runtime
platform → tenant → ride → quote → attempt → capture

This is valid as the current working runtime until migration is complete.

Rule:

Transitional reality is allowed. Unlabeled ambiguity is not.

3. Lifecycle Ownership
Target
Lead owns engagement lifecycle
Quote hangs from lead
Attempt hangs from lead + quote
Ride is post-auth execution record
Current
Ride may still carry lifecycle ownership and token anchoring

Rule:

New work should move toward lead-owned lifecycle rather than hardening ride-first assumptions.

4. Creation Rules
Lead
target: created at storefront submission
Quote
created only by allowed lifecycle transition
must be idempotent
must not be created on passive read
Attempt
created only after explicit customer acceptance / payment action
Ride
target: created only after successful authorization
5. Availability Doctrine

Canonical rule:

ASSUME YES → APPLY CONSTRAINTS

Implications:

tenant does not need to pre-seed availability blocks for baseline operation
lack of configured schedule must not block initial entry
assignment is a constraint-resolution problem, not a required precondition for intake
6. Forbidden Patterns

The following are not allowed as canonical doctrine:

documenting ride as the permanent intake parent
documenting tenant-seeded availability as required for baseline operation
creating quote on passive page load
creating attempt on passive page load
creating ride at intake in the target model
showing unapproved quote to the customer
multiple active presented quotes for one engagement
GET requests that mutate lifecycle state
7. Token Authority

Target rule:

token → lead → tenant

Current runtime may still resolve through ride-first structure.

But:

public context must become record-owned after entry
tenant must not continue to govern the flow purely from URL after token entry
8. Read vs Write Separation

GET requests must never:

create records
mutate lifecycle state
advance payment state
silently create quote / attempt / ride

Any write must come from:

explicit state transition
explicit user action
explicit worker / webhook action
9. Naming Enforcement

Use these terms intentionally:

Canonical Target Terms
Lead
Quote Draft
Presented Quote
Auth Attempt
Ride
Capture
Transitional Terms

Where current code still uses ride-first naming, docs must label it as transitional.

Rule:

Do not use “ride” and “lead” interchangeably without a label.

10. Debug Rule

If something is duplicated, out of order, or firing unexpectedly, check in this order:

state machine authority
idempotency guard
hook duplication
passive-read side effects
stale transitional assumptions
11. Documentation Control

A doc must earn its existence.

Keep docs only if they:

define active behavior
reduce implementation ambiguity
support current debugging or migration

Delete or fold docs that:

repeat another doc
describe deprecated architecture without transition labeling
speculate about future features without immediate implementation value
Final Law

Nothing happens unless the state machine says so.# SoloDrive Runtime Request Lifecycle

Status: ACTIVE — DEBUG / IMPLEMENTATION DOCUMENT  
Purpose: Define the current runtime flow, the target runtime flow, and the guardrails that prevent duplication, drift, and race conditions.

---

## Document Intent

This doc is operational, not aspirational.

It should help answer:

- what happens today
- what should happen next
- where bugs are likely to appear
- what must never happen on passive reads

---

## Lifecycle Views

### Current Working Runtime
This is the ride-first runtime that has already reached payment capture.

```txt
Storefront
  → intake record created (ride-first today)
  → quote created / attached
  → token minted
  → /trip/<token>
  → payment authorization
  → ride completion
  → payment capture
Target Runtime

This is the lead-first runtime the system is moving toward.

Storefront
  → lead created
  → quote draft created
  → tenant approves / adjusts / rejects
  → presented quote shown
  → user accepts
  → auth attempt created
  → authorization succeeds
  → ride created
  → ride completes
  → capture
Key Rule

Each phase owns its own records.
No phase may silently pre-create a downstream phase.

1. Entry Point: Storefront Submission
Source
CF7-based intake surface
Current Trigger
wpcf7_before_send_mail
Current Working Behavior
validate minimum fields
resolve tenant
create current intake/lifecycle record
mint token
redirect to /trip/<token>
Target Behavior
validate minimum fields
resolve tenant
create lead
mint token on lead
redirect to /trip/<token>

Required data:

sd_tenant_id
pickup place id
dropoff place id
requested time
customer name
customer phone
2. Public Redirect
/trip/<token>
Current Meaning

Token resolves the current engagement record that owns the public flow.

Target Meaning
token → lead_id → sd_tenant_id → full context

Rule:

After token entry, public context should be record-owned, not URL-owned.

3. Quote Creation Phase
Current Working Reality

Quote is currently generated/attached within the ride-first model.

Target Model

Quote draft is generated in response to lead state and then reviewed by tenant/operator.

Target transition shape:

LEAD_CAPTURED
  ↓
LEAD_WAITING_QUOTE
  ↓
QUOTE_DRAFT_CREATED
  ↓
tenant approves / adjusts / rejects
  ↓
approved quote becomes PRESENTED

Rule:

A customer must never see an unapproved quote.

4. Quote Risk Zone

This is a historical bug zone and remains a critical guardrail area.

Common causes of duplicate or invalid quote creation:

hook fires multiple times
page refresh re-enters side-effect logic
no idempotency check
state not advanced atomically
quote creation happens on passive render instead of explicit lifecycle trigger

Required guard pattern:

$existing = SD_Quote::get_active_for_parent($parent_id);
if ($existing) {
    return;
}

Where parent_id is:

ride in current runtime
lead in target runtime
5. Tenant Decision Gate

This is target architecture and should guide refactors.

Allowed outcomes:

approve
adjust
reject with apology / decline outcome

Rules:

unapproved quote is not customer-visible
only one presented quote may be active
presentation happens on /trip/<token>
6. Authorization Phase
Current Working Behavior

The current system has already reached payment authorization and capture through the ride-first runtime.

Target Behavior

User accepts the presented quote, then:

POST /trip/<token>
  → create auth attempt
  → create Stripe session / authorization flow
  → Stripe webhook / return handling
  → authorization outcome updates lifecycle

Output:

attempt_id
authorization status
linkage to engagement parent and quote

Rule:

Authorization is the commitment boundary.

7. Promotion to Ride
Current Working Reality

Ride already exists in the current ride-first runtime.

Target Behavior

Ride is created only after successful authorization.

Target shape:

authorization success
  → create sd_ride
  → assign operational context
  → advance lifecycle

Rule:

Ride must not exist before authorization in the target model.

8. Ride Execution Phase

Ride execution states:

RIDE_QUEUED
RIDE_DEADHEAD
RIDE_WAITING
RIDE_INPROGRESS
RIDE_ARRIVED
RIDE_COMPLETE
RIDE_CANCELLED

This phase is operational and should remain separate from quote/engagement state.

9. Capture Phase
Current Working Reality

The current system has already reached payment capture.

Target Rule

Capture occurs after ride completion.

Target shape:

RIDE_COMPLETE
  → capture payment
  → record final payment result
10. Full Runtime Timeline
Current
CF7 submit
  → create ride-first engagement record
  → create/attach quote
  → mint token
  → /trip/<token>
  → payment authorization
  → completion
  → capture
Target
CF7 submit
  → create lead
  → mint token
  → /trip/<token>
  → quote draft generation
  → tenant decision
  → presented quote
  → user acceptance
  → auth attempt
  → authorization success
  → create ride
  → completion
  → capture
11. Critical Debug Zones
Zone A: Intake Duplication

Check:

CF7 firing twice
AJAX + non-AJAX overlap
duplicate post creation paths
Zone B: Quote Generation

Check:

idempotency guard
duplicate hooks
page-load mutation
missing state advancement
Zone C: State Drift

Check:

stale status values
transitions that do not update atomically
logic reading one state and writing another object
Zone D: Token Re-entry

Refreshing /trip/<token> must not:

create quotes
create attempts
mutate state without explicit user or worker action
12. Required Safeguards
Idempotency

Every creation path must answer:

Does this already exist?
Single Source of Truth
current working source: ride-first engagement record
target source: lead
no duplicate ownership of the same lifecycle concern
State Machine Authority

Only allowed transitions may create or advance downstream records.

No Side Effects on Read

GET requests must never:

create records
mutate state
trigger downstream promotion implicitly
13. Payment Correlation Rule

Attempt records are the canonical Stripe correlation layer.

They should carry:

tenant linkage
quote linkage
parent engagement linkage
Stripe identifiers
attempt status

Current parent linkage may be ride-first.
Target parent linkage should be lead-first.

14. Transitional Implementation Rule

Until lead-first ownership is implemented:

current working ride-first flow remains valid
new work must avoid deepening ride-first assumptions unnecessarily
docs must distinguish current behavior from target behavior
Final Rule

Nothing creates anything unless the state machine says so.


---