SOLODRIVE.PRO — System Lifecycle Overview

Lead → Quote → Ride → Payment

Status: CANONICAL SYSTEM MODEL

This document explains the core lifecycle pipeline that powers the SOLODRIVE.PRO platform.

It describes how a passenger request becomes:

Lead → Quote → Ride → Payment

The lifecycle is designed so the public trip surface (/trip/<token>) acts as the primary interaction surface for riders while the platform orchestrates the backend state machines.

1. Lifecycle Pipeline
Passenger Request
       │
       ▼
LEAD_CAPTURED
       │
       ▼
LEAD_WAITING_QUOTE
       │
       ▼
QUOTE_PROPOSED
       │
       ▼
QUOTE_PRESENTED
       │
 Rider Decision
       │
       ├───────────────┐
       ▼               ▼
LEAD_ACCEPTED     USER_REJECTED
       │
       ▼
PAYMENT_PENDING
       │
       ▼
RIDE_QUEUED
       │
       ▼
RIDE_DEADHEAD
       │
       ▼
RIDE_ARRIVED
       │
       ▼
RIDE_INPROGRESS
       │
       ▼
RIDE_COMPLETE
       │
       ▼
PAYMENT_CAPTURED

This pipeline represents the complete journey of a ride transaction.

2. Lead Lifecycle

The Lead Lifecycle represents the early phase where a passenger request enters the system and awaits quoting.

Stored on:

sd_ride

Meta key:

sd_lead_status

States:

State	Meaning
LEAD_CAPTURED	Request submitted through storefront
LEAD_WAITING_QUOTE	Awaiting driver or system quote
LEAD_OFFERED	Quote prepared
LEAD_PROMOTED	Converted into a confirmed ride
LEAD_DECLINED	Passenger declined
LEAD_EXPIRED	Request timed out
LEAD_AUTH_FAILED	Payment authorization failed
3. Quote Lifecycle

Quotes are separate records stored as:

sd_quote

Meta key:

sd_quote_status

Quote states:

State	Meaning
PROPOSED	Quote created by system/driver
APPROVED	Approved internally
PRESENTED	Visible to rider
USER_REJECTED	Rider declined
USER_TIMEOUT	Rider did not respond
LEAD_ACCEPTED	Rider accepted
PAYMENT_PENDING	Authorization captured
LEAD_REJECTED	Rejected by operator
EXPIRED	Quote expired
SUPERSEDED	Replaced by new quote
CANCELLED	Cancelled

Important rule:

The only state where the rider makes a decision is:

PRESENTED

The rider decision occurs on:

/trip/<token>

The system never exposes pricing math, only final results.

4. Ride Execution Lifecycle

Once a quote is accepted and payment authorized, the ride enters the execution lifecycle.

Stored on:

sd_ride

Meta key:

state

Execution states:

State	Meaning
RIDE_QUEUED	Awaiting driver assignment
RIDE_DEADHEAD	Driver traveling to pickup
RIDE_WAITING	Driver waiting at pickup
RIDE_ARRIVED	Driver arrived
RIDE_INPROGRESS	Passenger onboard
RIDE_COMPLETE	Ride finished
RIDE_CANCELLED	Ride cancelled

Execution telemetry powers the live trip surface.

5. Public Trip Surface

The platform exposes a public token-based ride surface:

/trip/<token>

Purpose:

rider decision surface

ride progress display

driver ETA visualization

pickup coordination

Characteristics:

Property	Behavior
token based	no login required
uncached	real-time updates
decision surface	quote acceptance
telemetry	driver tracking
third-party compatible	token can be forwarded

Passengers see logistics only:

driver location

ETA

ride status

They do not see payment details.

6. Payment Lifecycle

Payments use Stripe manual capture.

Flow:

Quote accepted
      │
      ▼
Payment Authorization
      │
      ▼
Ride Completion
      │
      ▼
Payment Capture

Advantages:

protects drivers

prevents fraudulent disputes

enables final adjustments if needed

7. Operational Surfaces

The system has three primary surfaces:

Passenger Surface
/trip/<token>

Purpose:

quote decisions

ride tracking

Driver Surface

Driver portal and telemetry surfaces.

Purpose:

availability status

GPS telemetry

ride execution updates

Tenant Operations Surface

Internal tenant dispatch tools.

Purpose:

quote management

ride monitoring

driver status control

8. Platform Principle

The lifecycle model enforces a strict separation:

Lead Lifecycle
Quote Lifecycle
Ride Lifecycle
Payment Lifecycle

Each lifecycle is independent but coordinated.

This architecture prevents:

state corruption

payment inconsistencies

race conditions in dispatch logic

9. Role of SoGa-Go

SoGa-Go functions as Tenant-001 of the platform and serves as the operational proof-of-concept and live testing environment for the system.

It provides:

real-world operational feedback

UX validation

driver field testing

platform iteration

Future tenants will operate on the same lifecycle model.