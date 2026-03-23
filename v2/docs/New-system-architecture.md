1. Lead becomes the anchor record

Yes: lead_id should be the parent of quote and ride.

That makes the lead the canonical visitor-engagement record:

first intake anchor
tenant-scoped
token owner
lifecycle root
audit root

That is better than making ride the root, because many leads will never become rides, and they should not pollute ride operations.

So I would treat lead as:

“someone engaged the system”
“we captured enough information to continue”
“this token now represents a live request lifecycle”

Not as a pre-ride hack.

2. Storefront should do almost nothing

Your storefront step is right:

capture lead CPT
basic input validation only
create token
redirect to /trip/<token>

That is exactly the right level of responsibility.

The storefront should not:

price
decide business eligibility
allocate driver time
create rides
create auth attempts
do quote logic

It should only:

resolve tenant
validate minimum required fields
persist lead
mint token
redirect

Everything after that belongs to kernel orchestration.

3. Reservation vs ASAP should be a token-surface behavior, not a form architecture split

This is the key upside of your revised model.

You do not need:

one form for ASAP
one form for reservation

You need:

one lead capture entry
one token surface
mode-aware progression

So:

ASAP path: token page defaults to “start now”
Reservation path: token page exposes start date/time selection

That keeps the variation where it belongs: in the lifecycle progression after lead capture, not in separate intake implementations.

4. Availability should not block lead capture

Your sequence is right:

lead captured
scheduler checks availability
token updated if unavailable

That is much healthier than trying to prove availability before a canonical record exists.

Operationally this means:

you always preserve demand
you can audit failed/unavailable opportunities
you can reprocess later if rules change
rider sees state evolve on the token surface instead of losing context in a dead form submit

That is exactly the kind of “hold and trigger” architecture you want.

5. Draft quote should be prepared before operator presentation

Also correct.

I would phrase it as:

scheduler/enrichment produces feasibility and block-spend candidate
quote engine produces draft quote
operator can approve/adjust
approved quote becomes presented
token surface shows CTA

This keeps the quote engine pure:
it prepares a machine draft,
but presentation is still an operator/business decision.

That separation will matter later when you introduce:

tenant pricing rules
service areas
blackout logic
reservation lead times
operator overrides
6. Ride creation at auth is the correct promotion event

This is the most important architectural decision in your message.

I would define:

Lead → Ride promotion happens on successful authorization, not on quote presentation.

That gives you a very clean meaning for sd_ride:
an authorized, operationally committed trip.

Not:
a maybe-trip,
a request shell,
or a prequote placeholder.

That will simplify:

dispatch boards
scheduling views
active trip ops
driver assignment
completion/payment capture logic

because rides will mean something operationally real.

7. Suggested state model

I would stop thinking in one long universal lifecycle and use phase-separated state machines linked by parent/child relationships.

Lead lifecycle
LEAD_CAPTURED
LEAD_PENDING_AVAILABILITY
LEAD_AVAILABLE
LEAD_UNAVAILABLE
LEAD_QUOTING
LEAD_QUOTED
LEAD_EXPIRED
LEAD_DECLINED
LEAD_PROMOTED
Quote lifecycle
DRAFT
PENDING_OPERATOR
APPROVED
PRESENTED
ACCEPTED
REJECTED
EXPIRED
SUPERSEDED
Auth lifecycle
AUTH_PENDING
AUTH_STARTED
AUTH_FAILED
AUTHORIZED
AUTH_EXPIRED
Ride lifecycle
your existing operational ride states after promotion

This is better than stuffing everything into one state field, because the lead, quote, auth, and ride are not the same entity.

8. Single active lifecycle per lead: yes

Your “one active lifecycle at a time, no overlaps” rule is good.

I’d define it more precisely:

A lead can have many historical child records, but only one active commercial path at a time.

So:

one active draft/presented quote
one active auth window
zero or one promoted ride
superseded quotes preserved historically

That gives you replayability without ambiguity.

9. Recommended parent/child model

I would shape it like this:

sd_lead

owns:

sd_tenant_id
trip token
rider identity/contact snapshot
raw intake snapshot
mode preference
requested timing
lead status
availability result
current active quote id
current active auth id
eventual promoted ride id
sd_quote

child of sd_lead
contains:

pricing snapshot
service assumptions
time block spend / reservation hold data
operator adjustments
quote lifecycle state
sd_attempt or auth record

child of sd_lead or sd_quote
contains:

authorization session metadata
Stripe/payment intent references
auth state
sd_ride

child of sd_lead
created only on successful auth
contains:

operational trip record
dispatch data
driver assignment
ride execution state

That is a much more honest data model.

10. One refinement I would suggest

I would not make the scheduler directly “update trip/token if unavailable” as the primary act.

Instead:

scheduler updates the lead
token surface renders the lead state

That keeps token as presentation, not storage semantics.

So the real sequence is:

scheduler writes LEAD_UNAVAILABLE or LEAD_AVAILABLE
token page reads current lead/quote/auth state
token page renders the right CTA or explanation

Same UX, cleaner architecture.

11. Why this is better than current model

Your current model creates ride + quote immediately, which causes three problems:

First, it collapses inquiry and commitment.
Second, it pollutes operational ride objects with non-rides.
Third, it lets intake shape the domain.

Your new model fixes all three.

It also gives you stronger reporting:

captured leads
unavailable leads
quoted leads
authorized leads
promoted rides
completed rides
capture success after completion

That funnel will be much more valuable than a blob of prematurely created rides.

My verdict

I think this is the right direction.

I would endorse it as:

Lead is the canonical engagement root.
Quote is the commercial artifact.
Auth is the promotion gate.
Ride begins only after authorization.
Payment capture completes after ride fulfillment.

That is a much better kernel-centered architecture than the current flow.