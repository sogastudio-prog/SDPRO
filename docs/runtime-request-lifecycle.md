SoloDrive Runtime Request Lifecycle (v1.0)

Status: ACTIVE — DEBUG PRIORITY DOCUMENT

0. Purpose

This document defines the exact runtime flow of a customer request from:

Storefront → Lead → Quote → Auth → Ride → Capture

It exists to:

Debug duplicate quote issues
Enforce lifecycle isolation
Clarify hook execution order
Prevent state leakage and race conditions
1. Canonical Lifecycle Chain (LOCKED)
LEAD_CAPTURED
  ↓
LEAD_WAITING_QUOTE
  ↓
LEAD_OFFERED
  ↓
LEAD_PROMOTED
  ↓
QUOTE_PAYMENT_PENDING
  ↓
RIDE_QUEUED → RIDE_COMPLETE
Key Rule

Each phase owns its own records. No phase may pre-create the next.

2. Entry Point: Storefront Submission
Source
CF7 Form (Contact Form 7)
Trigger
wpcf7_before_send_mail
3. Lead Creation Phase
Hook Chain
wpcf7_before_send_mail
  → SD_Module_Intake::handle_submission()
      → validate_minimum_fields()
      → resolve_tenant_id()
      → create_lead()
      → mint_token()
      → redirect_to_trip()
Required Fields (LOCKED)
sd_tenant_id
pickup_place_id
dropoff_place_id
requested_datetime
customer_name
customer_phone
Output
lead_id
sd_trip_token
sd_lead_status = LEAD_CAPTURED
4. Public Redirect
/trip/<token>
Resolution Flow
token
  → lead_id
    → sd_tenant_id
      → full system context
Rule

After this point, URL no longer determines tenant.

5. Lead → Quote Transition
Trigger Condition
sd_lead_status = LEAD_WAITING_QUOTE
Worker (current or planned)
SD_Worker_GenerateQuote
Expected Behavior
IF no active quote exists:
    create QUOTE (PROPOSED)
    link to lead via sog_ride_id / lead_id
    advance lead → LEAD_OFFERED
🔴 Duplicate Quote Risk Zone

This is where your bug lives.

Common Causes:
Multiple hook triggers
CF7 fires twice
Page reload triggers worker again
Missing idempotency check

No guard like:

if (existing_active_quote($lead_id)) return;
Race condition
Two processes create quote simultaneously
State not updated fast enough
Lead still appears as WAITING_QUOTE
REQUIRED GUARD (CANON)
$existing = SD_Quote::get_active_by_lead($lead_id);

if ($existing) {
    return; // HARD STOP
}
6. Quote Lifecycle
States
PROPOSED
  → PRESENTED
    → LEAD_ACCEPTED
      → PAYMENT_PENDING
Decision Surface (LOCKED)

/trip/<token> is the ONLY place a human decides.

7. Quote → Auth Attempt
Trigger

User accepts quote on trip page

Flow
POST /trip/<token>
  → SD_Module_TripActions::accept_quote()
      → create_auth_attempt()
      → call Stripe
Output
auth_attempt_id
status = AUTHORIZED / FAILED
8. Auth → Ride Promotion
Trigger
Stripe authorization success
Flow
auth_success
  → promote_lead_to_ride()
      → create sd_ride
      → assign driver (later)
      → set:
          LEAD_PROMOTED
          RIDE_QUEUED
Rule

Ride must NOT exist before payment authorization

9. Ride Execution Phase
RIDE_QUEUED
  → RIDE_DEADHEAD
  → RIDE_ARRIVED
  → RIDE_INPROGRESS
  → RIDE_COMPLETE
10. Capture Phase
Trigger
RIDE_COMPLETE
Flow
capture_payment()
  → Stripe capture
  → finalize transaction
11. Full Hook Timeline (Simplified)
CF7 Submit
  → wpcf7_before_send_mail
    → create_lead()

Redirect → /trip/<token>

Trip Load
  → maybe_generate_quote()

User Action
  → accept_quote()

Stripe
  → auth_response()

System
  → promote_to_ride()

Driver Ops
  → ride_state_machine()

Completion
  → capture_payment()
12. CRITICAL DEBUG ZONES
🔴 Zone A: Intake Duplication

Check:

CF7 firing twice
AJAX + non-AJAX submission overlap
🔴 Zone B: Quote Generation (PRIMARY ISSUE)

Symptoms:

Two quotes created
Conflicting states

Fix:

Add idempotency guard
Lock by lead_id
🔴 Zone C: State Drift

Symptoms:

Lead stuck in wrong state
Workers re-trigger

Fix:

Ensure atomic updates:

update_post_meta($lead_id, 'sog_lead_status', NEW_STATE);
🔴 Zone D: Token Re-entry

Refreshing /trip/<token> should NOT:

create new quote
trigger state changes
13. Required Safeguards (NON-NEGOTIABLE)
1. Idempotency

Every creation must check:

Does this already exist?
2. Single Source of Truth
Lead owns lifecycle
Token resolves lead
No duplication across CPTs
3. State Machine Authority

Only allowed transitions:

current_state → allowed_next_state

No skipping.

4. No Side Effects on Read

GET /trip/<token> must NEVER:

create quotes
mutate state
14. Immediate Fix Plan (Your Current System)
Step 1

Add guard in quote creation:

if (SD_Quote::exists_active_for_lead($lead_id)) return;
Step 2

Log every quote creation:

error_log('[QUOTE_CREATE] lead_id=' . $lead_id);
Step 3

Verify trigger source:

CF7 hook?
Trip page load?
Worker loop?
Step 4

Move quote creation into:

state-driven worker ONLY

NOT:

page render
shortcode
template
15. Golden Rule (Burn This In)

Nothing creates anything unless the state machine says so.

16. What This Fix Unlocks

Once stable:

No duplicate quotes ✅
Deterministic lifecycle ✅
Clean audit logs ✅
Reliable Stripe flow ✅
Production readiness ✅