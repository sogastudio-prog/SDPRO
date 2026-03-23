# SoloDrive Time Blocks

Status: LOCKED  
Purpose: Define the canonical availability and scheduling model used by storefront, reservations, stacking, and future dispatch planning.

## 1. Core Principle

All rides consume time. All time is modeled as blocks.

Time blocks are the canonical source of truth for:

- availability
- reservations
- scheduled conflicts
- assigned ride occupancy
- blackout windows

## 2. Canonical Record

Recommended record type:

- `sd_time_block`

This record must be tenant-scoped and may optionally be driver-scoped.

## 3. Required Fields

### Ownership / Scope

- `sd_tenant_id`
- `sd_driver_user_id` (optional in tenant-wide v1, required for driver-specific scheduling)

### Block Identity

- `sd_block_type`
- `sd_block_status`
- `sd_block_source`

### Time

- `sd_start_ts`
- `sd_end_ts`
- `sd_timezone`

### Optional Linkage

- `sd_ride_id`
- `sd_parent_block_id`
- `sd_reservation_token`

### Notes / Audit

- `sd_label`
- `sd_note`

## 4. Canonical Enums

### Block Type

- `AVAILABILITY`
- `BLACKOUT`
- `RESERVATION_HOLD`
- `RESERVATION_CONFIRMED`
- `ASSIGNED_RIDE`

### Block Status

- `ACTIVE`
- `RELEASED`
- `CANCELLED`
- `EXPIRED`

### Block Source

- `TENANT_ADMIN`
- `DRIVER_PORTAL`
- `STOREFRONT_RESERVATION`
- `SYSTEM`
- `OPERATOR`

## 5. Canonical Rules

- all overlap math uses unix timestamps
- `sd_end_ts` must be greater than `sd_start_ts`
- timezone is stored for display and audit, not overlap arithmetic
- overlap checks must happen in repository/service code, not form code

## 6. Availability Repository Rules

Availability is determined by querying active blocks for the relevant tenant and, when applicable, driver.

A requested service block is feasible only if it does not overlap active blocking records such as:

- `BLACKOUT`
- `RESERVATION_HOLD`
- `RESERVATION_CONFIRMED`
- `ASSIGNED_RIDE`

`AVAILABILITY` blocks are positive windows. Blocking records are subtractive constraints inside or around those windows.

## 7. Unified Ride Timing Model

The platform does not maintain separate scheduling math for ASAP, stacked, and reserved rides.

All accepted rides produce a candidate service block.

The only difference between ride entry modes is the time anchor used to compute the block.

### ASAP

- request mode: `ASAP`
- anchor: current server time

### STACKED

- request mode: `ASAP`
- anchor: projected completion of current ride / prior dropoff context

### RESERVE

- request mode: `RESERVE`
- anchor: rider-selected future pickup time

## 8. Candidate Block Construction

Recommended service:

- `SD_ServiceBlockBuilder::build_from_ride($ride_id)`

Inputs may include:

- requested timestamp
- pickup/dropoff
- base location or prior ride dropoff
- route duration estimate
- pre/post buffers
- deadhead estimate

Outputs should include:

- `service_start_ts`
- `service_end_ts`
- duration
- deadhead contribution
- route contribution

## 9. Two-Step Storefront Fit Relationship

Time blocks support the storefront’s two-step availability model.

### Filter 1 — Admission

Uses:

- next future block
- storefront ASAP buffer minutes
- current capacity context

This controls what storefront mode should be presented.

### Filter 2 — Specific Fit

Uses:

- candidate service block for the submitted ride
- overlap check against committed future blocks

This controls whether the specific ride may be accepted.

## 10. Reservation Hold Lifecycle

Reservations are not a separate truth system. They use the same time-block model.

Recommended reservation lifecycle:

- `REQUESTED`
- `HOLD_PLACED`
- `CONFIRMED`
- `DECLINED`
- `CANCELLED`
- `EXPIRED`

Flow:

1. Rider submits future request.
2. System computes candidate service block.
3. System checks availability.
4. If feasible, create a `RESERVATION_HOLD` block.
5. Later convert hold to `RESERVATION_CONFIRMED` or release it.

## 11. Why This Model Exists

This model prevents the system from treating “driver idle right now” as the same thing as “driver available for new work.”

A driver may be idle in the present while already committed in the near future. Time blocks make those commitments visible and enforceable.
