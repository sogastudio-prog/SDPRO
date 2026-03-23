What changed:

Added sd_lead as a real CPT root.
Added new lead-root meta constants in includes/sd-meta.php.
Added SD_Module_LeadTokenService.
Reworked the token index to resolve lead_id instead of ride_id.
Changed CF7 intake to create sd_lead only, mint the token on lead, and redirect to /trip/<token>/.
Changed /trip/<token> resolution to load lead first.
Added a lead-only public surface fallback for pre-promotion states.

Important current state:

This slice establishes the new root architecture.
It does not yet rewire quote, auth, or ride-promotion flows end-to-end.
Existing ride-first downstream modules are still pending refactor in the next slices.

Most important behavior now:

storefront no longer creates sd_ride
token belongs to sd_lead
trip page can render a lead before any ride exists

Next locked move:

build lead capture/storefront cleanly on top of this kernel slice, then document it immediately.

Built the next slice and packaged it here:

solodrive-kernel-lead-capture-storefront.zip

What changed:

Added a canonical SD_Module_LeadService for storefront/intake lead creation.
Added new lead-root meta constants in includes/sd-meta.php, including:
sd_lead_id
sd_request_mode
sd_requested_ts
sd_requested_date
sd_requested_time
sd_availability_status
sd_current_quote_id
sd_current_attempt_id
sd_promoted_ride_id
_sd_attempt_lead_id
Refactored CF7 intake to call the lead service instead of manually building the lead inline.
Added intake validation for request mode plus reservation date/time when reserve mode is used.
Lead creation now stores:
request mode
requested timing
reservation timing fields when applicable
intake snapshot JSON
pending availability state
Updated the lead-only /trip/<token> surface to show:
ASAP vs Reservation
requested timing
lead-first status context

Current state of this slice:

Storefront/intake now behaves much more like true lead capture.
It still depends on your existing form/UI structure, so this is a kernel/storefront data-model upgrade, not the final storefront UX pass.
Quote, auth, and timeblock orchestration are not yet wired into this slice.

Next locked move:

build timeblocks supply/spend next, then document that immediately after.

Timeblocks — Supply / Spend (Kernel Layer)

This is where:

availability becomes real (not a guess)
reservations become enforceable
ASAP becomes schedulable
quotes become grounded in capacity
🎯 What we are building (LOCKED)

A timeblock engine that:

models driver supply
models lead demand
determines feasibility
allocates soft holds (spend)
supports ASAP + reservation
feeds the quote engine
🧠 Core concept

We are introducing:

sd_timeblock (NEW SYSTEM ENTITY)

Represents a unit of available or reserved time.

Think:

“this chunk of time is either available, held, or committed”

🧱 Data Model
sd_timeblock

Core fields:

sd_tenant_id
sd_driver_id (nullable for pooled supply later)
sd_block_start_ts
sd_block_end_ts
sd_block_capacity (minutes or slots)
sd_block_spent
sd_block_status
Status:
OPEN
HELD (soft reserved for lead)
COMMITTED (ride exists)
EXPIRED
Lead relationship (IMPORTANT)

We do not assign blocks directly to rides anymore.

Instead:

lead → consumes block capacity (soft hold)
auth success → converts hold → committed ride
🔁 Flow (CRITICAL)
ASAP
Lead captured
Scheduler finds nearest viable blocks
Blocks are soft-held
Lead → LEAD_AVAILABLE
Quote can be generated
Reservation
Lead captured with requested time
Scheduler checks matching blocks
If available → hold block
If not → mark unavailable
Lead updated accordingly
🧩 New Kernel Module

Create:

SD_Module_Timeblocks

Responsibilities:

query available blocks
evaluate feasibility
allocate holds
release holds
convert holds → committed
🔐 Critical rules
1. No block → no quote

Quote must not exist unless:

lead has valid timeblock feasibility
2. Holds expire

Timeblocks held by leads must expire if:

no auth within window
lead expires
quote superseded
3. One lead = one active hold set

No overlapping holds per lead.

4. Ride creation consumes blocks

On auth success:

held blocks → committed
linked to ride
🔌 Required new meta
On sd_lead
sd_timeblock_ids (array or JSON)
sd_timeblock_status
On sd_timeblock
sd_lead_id (when held)
sd_ride_id (when committed)
⚙️ Immediate Implementation Plan
Step 1 — CPT

Create:
includes/cpt/060-timeblock-cpt.php

Step 2 — Meta constants

Add to sd-meta.php:

SD_TIMEBLOCK_START
SD_TIMEBLOCK_END
SD_TIMEBLOCK_CAPACITY
SD_TIMEBLOCK_SPENT
SD_TIMEBLOCK_STATUS
SD_TIMEBLOCK_LEAD_ID
SD_TIMEBLOCK_RIDE_ID
Step 3 — Service

Create:

includes/modules/070-timeblock-service.php

Core methods:

find_available_blocks($lead)
hold_blocks($lead, $blocks)
release_blocks($lead)
commit_blocks($lead, $ride_id)
Step 4 — Hook into lead lifecycle

After lead creation:

LEAD_CAPTURED
→ LEAD_PENDING_AVAILABILITY
→ (run scheduler)
→ LEAD_AVAILABLE or LEAD_UNAVAILABLE
⚠️ What we are NOT doing yet
no driver assignment UI
no geo-routing optimization
no pricing logic
no operator UI

This is purely:
👉 supply + feasibility layer

💥 Why this matters

Without this:

quotes are fake
reservations are guesses
ASAP is unreliable
dispatch becomes reactive chaos

With this:

every quote is grounded in capacity
reservations are enforceable
operators trust the system
future automation becomes trivial
🚀 Your next move

Build this slice exactly as above:

Create sd_timeblock CPT
Add meta constants
Build TimeblockService
Wire it to lead lifecycle (post-capture → availability evaluation)
Update lead state accordingly

When that’s done:

👉 we immediately document:
/docs/timeblock-supply-spend.md

Then move to:
👉 quote cycle (now backed by real capacity)