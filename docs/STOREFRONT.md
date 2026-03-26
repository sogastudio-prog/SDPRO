# SoloDrive Storefront Architecture

Status: LEGACY  
Applies to: SoloDrive Kernel + Tenant Storefronts  
Audience: Kernel developers, tenant operators, platform maintainers

PENDING UPDATE:

## Storefront Role

The storefront captures a Lead.

It is responsible for:
- collecting minimum viable data
- creating a lead record
- minting a token
- redirecting to /trip/<token>

END UPDATE *******

## 1. Purpose

The storefront is the public front-office surface where customers engage a tenant to request transportation.

Its purpose is not merely to render a form. Its purpose is to convert a visitor into the earliest viable paying commitment while remaining operationally honest.

Storefront priorities are:

1. Book an immediate paid ride when possible.
2. Capture a future paid ride when immediate service is not the right fit.
3. Avoid promising service that would interfere with committed future work.

The storefront is therefore a tenant-scoped decision engine that determines the best available customer action based on:

- tenant policy
- operational state
- driver availability
- capacity rules
- business hours
- stacking rules
- committed future time blocks

The storefront then renders the appropriate workflow.

## 2. Architectural Role in the Platform

The storefront sits before the canonical ride pipeline.

Visitor  
   │  
   ▼  
Storefront Decision Engine  
   │  
   ▼  
Workflow Submission  
   │  
   ▼  
Ride / Waitlist / Reservation Intent Creation  
   │  
   ▼  
Quote / Scheduling Logic  
   │  
   ▼  
Trip Surface or Reservation Follow-up

This aligns with the kernel architecture where:

- Ride is the operational spine.
- Quote is the pricing decision record.
- Attempt is the Stripe payment authorization record.
- Time blocks are the canonical availability model.

## 3. Core Design Principle

The storefront is a decision engine, not a collection of forms.

Old architecture:

State → Choose CF7 form

Current architecture:

Tenant Policy  
     +  
Operational Context  
     ↓  
Storefront Decision Engine  
     ↓  
Customer Action  
     ↓  
Workflow Adapter  
     ↓  
Domain Record Creation

The storefront may still use CF7 as a workflow adapter, but CF7 is not the source of truth. CF7 is only a renderer/submission mechanism.

## 4. Storefront Decision Model

The storefront resolves two layers of state.

### 4.1 Public Display State

Public states are simplified for rider clarity.

- `open`
- `busy`
- `closed`

These are presentation states only.

### 4.2 Internal Availability Mode

Internal decision states drive workflow selection.

- `instant`
- `stacked_asap`
- `waitlist`
- `reserve_only`
- `unavailable`

Meaning:

| Mode | Description |
| --- | --- |
| `instant` | Driver/service capacity available now |
| `stacked_asap` | A next ride may be accepted after a current ride without violating stack limits |
| `waitlist` | Immediate ride not offered, but demand capture remains open |
| `reserve_only` | On-demand unavailable, future reservation path remains open |
| `unavailable` | No public booking workflow should be offered |

### 4.3 Reason Codes

Every storefront decision includes a reason code.

Examples:

- `OPEN_INSTANT`
- `OPEN_STACK_ONLY`
- `CAPACITY_REACHED_WAITLIST_OPEN`
- `CAPACITY_REACHED_RESERVE_ONLY`
- `CLOSED_HOURS`
- `NO_DRIVERS_ONLINE`
- `MANUAL_BUSY`
- `MANUAL_CLOSED`
- `TENANT_DISABLED`
- `OUT_OF_SERVICE_AREA`

Reason codes are used for:

- analytics
- debugging
- customer messaging
- operational alerts

## 5. Storefront Decision Algorithm (v1)

The storefront follows this decision order:

1. If storefront disabled  
   → `unavailable`
2. If manual override set  
   → honor override
3. If outside service hours  
   → `reserve_only`
4. If no drivers online  
   → `reserve_only` if reservations allowed  
   → otherwise `unavailable`
5. If instant capacity available  
   → `instant`
6. If stack capacity available  
   → `stacked_asap`
7. If waitlist enabled  
   → `waitlist`
8. If reservations enabled  
   → `reserve_only`
9. Otherwise  
   → `unavailable`

This algorithm controls what the storefront is allowed to present. It does not by itself guarantee that a specific requested ride fits. Specific fit is handled later by block-based validation.

## 6. Storefront Selector Row

The storefront now uses a selector row above the active workflow form.

The left side is always the default load for the current store state.

### OPEN

`[ASAP] [RESERVE]`

- default mode: `asap`
- alternate mode: `reserve`
- page load defaults to ASAP
- clicking RESERVE reloads the page and renders the reservation workflow form

### BUSY

`[WAITLIST] [RESERVE]`

- default mode: `waitlist`
- alternate mode: `reserve`
- page load defaults to waitlist
- clicking RESERVE reloads the page and renders the reservation workflow form

### CLOSED

`[RESERVE] [STATUS_PILL]`

- default mode: `reserve`
- right slot is informational only
- ASAP is not presented
- waitlist is not presented

### Resolver rules

Selector resolution must consider:

- storefront state
- default left mode
- allowed right mode
- selected mode from query arg
- resolved workflow binding

The query arg is a preference, not authority. If the selected mode is not valid for the current storefront state, the selector must fall back to the default left-side mode.

## 7. Customer Workflows

Each storefront outcome maps to a workflow.

| Mode | Workflow |
| --- | --- |
| `asap` | Instant on-demand booking |
| `stacked_asap` | ASAP booking using stack-aware quote context |
| `waitlist` | Waitlist enrollment |
| `reserve` | Reservation intake |
| `unavailable` | Informational / contact only |

Important rule:

- `ASAP` may lead to `instant`, `stacked_asap`, or `waitlist` depending on current decision state.
- `RESERVE` is an intentional future-booking path.
- `RESERVE` must never be downgraded to `WAITLIST`.

## 8. Workflow Adapters

The storefront does not depend on a specific form system.

Workflows are executed via adapters.

Example adapters:

- `CF7WorkflowAdapter`
- `NativeFormWorkflowAdapter`
- `APIWorkflowAdapter`
- `EmbeddedWidgetAdapter`

This allows tenants to change form systems without modifying storefront decision logic.

## 9. Tenant Storefront Policy

All storefront configuration is tenant-scoped.

Configuration includes:

### Availability

- `storefront_enabled`
- `manual_mode`
- `on_demand_enabled`
- `stacked_enabled`
- `waitlist_enabled`
- `reservations_enabled`

### Capacity Rules

- `max_active_rides_per_driver`
- `max_stacked_rides_per_driver`
- `waitlist_limit`
- `auto_close_if_no_drivers`

### Hours

- `weekly_hours`
- `holiday_overrides`
- `manual_closures`

### Messaging

Tenant may override messages such as:

- `open_headline`
- `busy_headline`
- `closed_headline`
- `resume_message`
- `no_driver_message`

### Workflow Bindings

Tenant maps workflows to adapters.

Example:

- `instant_workflow`
- `stacked_workflow`
- `waitlist_workflow`
- `reservation_workflow`

### Storefront Availability Buffer

The tenant must be able to define a storefront ASAP buffer in minutes.

Recommended canonical setting:

- `sd_storefront_asap_buffer_minutes`

Meaning:

This is the minimum runway before the next committed future block required for ASAP to remain generally offerable at the storefront presentation layer.

## 10. Unified Ride and Time-Block Model

SoloDrive does not treat reservation as a separate ride universe.

All accepted rides consume time. All time is modeled as blocks.

The only difference between:

- ASAP ride
- stacked ride
- reserved ride

is the time anchor used to compute the service block.

### Ride planning meta

Recommended canonical ride planning fields:

- `sd_request_mode = ASAP | RESERVE`
- `sd_requested_ts`
- `sd_service_start_ts`
- `sd_service_end_ts`
- `sd_block_id`

### Time anchors

- ASAP: anchor = current server time
- STACKED: anchor = projected completion of prior ride / prior dropoff context
- RESERVE: anchor = rider-selected future pickup time

Everything else uses the same block model.

## 11. Stacking v1 Rules

Stacking v1 is a quote-context adjustment, not a separate ride type.

Locked rules:

1. Deadhead leg begins from the current ride’s dropoff location.
2. Pickup ETA is derived from completion of the current ride plus travel to the next pickup.
3. Stack is allowed only if resulting ETA stays within the configured threshold.

Tenant-configurable limits:

- maximum stack chain length
- maximum projected rider wait window in minutes

Pricing adjustments for stacked rides are explicitly deferred to later quote-engine evolution.

## 12. Two-Step Availability Filter

ASAP availability is governed by a two-step filter.

### 12.1 Filter 1 — Storefront Admission Filter

Purpose:

Determine what the storefront should generally present before rider submission.

Inputs:

- next committed future block
- tenant storefront ASAP buffer minutes
- current ride/stack context

This filter controls whether the storefront should present:

- OPEN / ASAP
- BUSY / WAITLIST
- CLOSED / RESERVE ONLY

This is a coarse presentation filter.

### 12.2 Filter 2 — Quote / Block Fit Filter

Purpose:

Determine whether a specific requested ride actually fits.

Inputs:

- requested pickup/dropoff
- deadhead estimate
- route duration estimate
- candidate service block
- committed future blocks

A request must be declined if the resulting service block would interfere with a scheduled or otherwise committed future block, even if the storefront was open at the time of initial presentation.

### Example

- tenant buffer = 30 minutes
- driver appears free now
- storefront may remain open under coarse admission rules
- rider submits an edge-case trip requiring 45 minutes of service time
- system computes a candidate block that conflicts with an existing scheduled block
- request must be declined with apology

Canonical rule:

Storefront ASAP availability must be determined by block fit, not by present-moment idleness alone.

## 13. Committed Future Blocks

A driver may be idle right now but still unavailable for a new ASAP ride if the driver has future committed work that would be endangered by accepting a new block.

Example:

- scheduled ride in 20 minutes
- pickup for that scheduled ride is 15 minutes away
- storefront must not accept a new ASAP request that would make the scheduled ride late

Therefore storefront availability must consider:

- current ride block
- stacked continuation possibility
- next committed future block
- tenant-configured ASAP runway buffer

## 14. Reservation Implications

Reservations require richer rider inputs and a canonical availability repository.

CF7 may remain a temporary renderer for reservation intake, but reservation truth must ultimately live in SoloDrive-owned records and time blocks, not in CF7 field behavior or external calendar plugins.

Reservations are a planning workflow. ASAP is an execution workflow. The storefront may render both, but their business meaning is distinct.
