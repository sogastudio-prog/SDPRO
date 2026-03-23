                                     SOLODRIVE KERNEL
                           (tenant-first transportation runtime)

 ┌────────────────────────────────────────────────────────────────────────────┐
 │                             TENANT RESOLUTION                             │
 │                                                                            │
 │   Host / Domain / Subdomain / Path  ───────────────►  sd_tenant           │
 │                                                                            │
 │   Tenant owns: storefront settings, hours, Stripe config, policy          │
 └────────────────────────────────────────────────────────────────────────────┘
                                         │
                                         ▼
 ┌────────────────────────────────────────────────────────────────────────────┐
 │                           PUBLIC CUSTOMER ENTRY                            │
 │                                                                            │
 │   Storefront                                                               │
 │   - instant ride                                                           │
 │   - stacked ASAP                                                           │
 │   - waitlist                                                               │
 │   - reservation                                                            │
 │   - unavailable / explanation                                              │
 │                                                                            │
 │   Request Surface / Intake                                                 │
 │   - collect trip logistics                                                 │
 │   - create ride lead                                                       │
 └────────────────────────────────────────────────────────────────────────────┘
                                         │
                                         ▼
 ┌────────────────────────────────────────────────────────────────────────────┐
 │                             DOMAIN RECORD SPINE                            │
 │                                                                            │
 │   sd_ride      = lead + operational trip spine                             │
 │   sd_quote     = pricing / customer decision record                        │
 │   sd_attempt   = Stripe authorization correlation record                   │
 │                                                                            │
 │   Canonical meta examples:                                                 │
 │   - sd_tenant_id                                                           │
 │   - sd_trip_token                                                          │
 │   - sd_lead_status                                                         │
 │   - sd_ride_state                                                          │
 │   - sd_quote_status                                                        │
 └────────────────────────────────────────────────────────────────────────────┘
                                         │
                        ┌────────────────┴────────────────┐
                        ▼                                 ▼
 ┌───────────────────────────────┐          ┌───────────────────────────────┐
 │         QUOTE PIPELINE        │          │        PAYMENT PIPELINE       │
 │                               │          │                               │
 │  Quote Engine                 │          │  Stripe Checkout Session      │
 │  - build draft                │          │  - create attempt             │
 │  - attach to ride             │          │  - store Stripe linkage       │
 │  - present to passenger       │          │                               │
 │                               │          │  Webhook / Lifecycle          │
 │  Human decision happens only  │          │  - resolve session → attempt  │
 │  on /trip/<token> when quote  │          │  - advance quote / ride       │
 │  is PRESENTED                 │          │                               │
 └───────────────────────────────┘          └───────────────────────────────┘
                        └────────────────┬────────────────┘
                                         ▼
 ┌────────────────────────────────────────────────────────────────────────────┐
 │                        PUBLIC TRIP DECISION SURFACE                        │
 │                                                                            │
 │   /trip/<token>                                                            │
 │   - uncached                                                               │
 │   - token-routed public access                                             │
 │   - quote presentation                                                     │
 │   - passenger decision                                                     │
 │   - payment outcome banners                                                │
 │   - live trip status / ETA / location context                              │
 └────────────────────────────────────────────────────────────────────────────┘
                                         │
                                         ▼
 ┌────────────────────────────────────────────────────────────────────────────┐
 │                         RIDE EXECUTION / OPERATIONS                        │
 │                                                                            │
 │   Operator Trips / Dispatch / Driver Telemetry                             │
 │   - assign / monitor / progress ride                                       │
 │   - deadhead                                                               │
 │   - waiting                                                                │
 │   - in progress                                                            │
 │   - arrived / complete                                                     │
 │                                                                            │
 │   Completion Service                                                       │
 │   - finalize ride metrics                                                  │
 │   - trigger capture workflow                                               │
 └────────────────────────────────────────────────────────────────────────────┘
                                         │
                                         ▼
 ┌────────────────────────────────────────────────────────────────────────────┐
 │                            STRIPE FINAL CAPTURE                            │
 │                                                                            │
 │   RIDE_COMPLETE  ───────────────►  capture authorized PaymentIntent        │
 │                                                                            │
 └────────────────────────────────────────────────────────────────────────────┘
 
 2. Principal Records

These are the core records of the system:

Record	CPT	Purpose
Tenant	sd_tenant	tenant identity, domain/slug, storefront settings, Stripe settings
Ride	sd_ride	intake record and operational trip spine
Quote	sd_quote	pricing / decision record associated to a ride
Attempt	sd_attempt	canonical Stripe transaction / authorization correlation record

This record model is already reflected in the reconstructed architecture and module inventory.

3. Lifecycle Overlay
STORE FRONT / REQUEST
    │
    ▼
Ride created
(sd_ride, lead lifecycle begins)
    │
    ▼
Quote built
(sd_quote)
    │
    ▼
Quote presented
(/trip/<token>)
    │
    ├── reject / expire / timeout
    │
    └── accept
          │
          ▼
Stripe authorization
(sd_attempt)
          │
          ▼
Ride promoted into execution
(sd_ride_state begins operational progression)
          │
          ▼
RIDE_COMPLETE
          │
          ▼
Payment capture

The kernel separates lead lifecycle, quote lifecycle, and ride execution lifecycle rather than merging them into one status field. That separation is part of the current doctrinal spine.

4. Module Grouping View
FOUNDATION
- tenant resolver
- tenant access
- token index / token service
- roles / caps
- guardrails

PUBLIC UX
- storefront
- request surface
- intake bridge
- /trip/<token>

DOMAIN SERVICES
- ride CPT / ride state service
- quote CPT / quote service / quote state service
- attempt CPT / attempt service
- quote engine
- ride completion service

PAYMENTS
- tenant Stripe settings
- checkout
- webhook
- lifecycle listener
- Stripe return
- capture

OPS / ADMIN
- dispatch board
- operator trips
- operator trip actions
- operator location
- base location
- admin metaboxes / meta debug

That grouping matches the reconstructed module inventory and architecture summary.

5. Public vs Private Surface Model
PUBLIC
- storefront
- request surface
- /trip/<token>

PRIVATE TENANT OPS
- operator trips
- dispatch board
- driver / operator location surfaces

PRIVATE ADMIN
- tenant admin
- metaboxes
- debug tools
- policy/configuration screens

This also aligns with the project scope note that private surfaces are now formally split into tenant operations and tenant administrative surfaces.

6. Core Doctrinal Rules Visible in the Diagram

A new developer should infer these immediately:

Tenant-first: the system resolves a tenant before storefront, intake, Stripe, or ops behavior is decided.

Ride is the operational spine: sd_ride carries lead status, trip token, and ride execution state.

Quote is separate from ride: customer pricing/decision lives on sd_quote, not on the ride itself.

Attempt is the payment spine: Stripe linkage is correlated through sd_attempt.

/trip/<token> is the primary public decision surface: it is uncached and token-routed.

Operator surfaces manage execution after authorization: they do not replace the public trip decision surface.