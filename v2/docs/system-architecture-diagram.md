SOLODRIVE.PRO — System Architecture Diagram

Passenger · Driver · Tenant · Kernel · Stripe · Telemetry

Status: CANONICAL HIGH-LEVEL ARCHITECTURE

This document provides a fast visual understanding of the SOLODRIVE.PRO system.

It is intended to help a new developer understand, in under 30 seconds, how the major actors and platform layers relate to one another.

1. Core Architecture Diagram
                                 SOLODRIVE.PRO PLATFORM

 ┌─────────────────────────────────────────────────────────────────────────────┐
 │                               Public Internet                              │
 └─────────────────────────────────────────────────────────────────────────────┘

          PASSENGER                              DRIVER
              │                                    │
              │                                    │
              ▼                                    ▼
   ┌─────────────────────┐              ┌────────────────────────┐
   │  Public Trip Page   │              │   Driver Surfaces      │
   │   /trip/<token>     │              │  portal + live tools   │
   │                     │              │                        │
   │ - quote decision    │              │ - availability         │
   │ - trip status       │              │ - telemetry pings      │
   │ - ETA / location    │              │ - trip execution       │
   │ - pickup guidance   │              │ - status updates       │
   └──────────┬──────────┘              └────────────┬───────────┘
              │                                      │
              └──────────────────┬───────────────────┘
                                 │
                                 ▼
                    ┌──────────────────────────────┐
                    │      SOLODRIVE KERNEL        │
                    │      solodrive-kernel        │
                    │                              │
                    │ - tenant resolution          │
                    │ - intake / ride creation     │
                    │ - quote lifecycle            │
                    │ - ride lifecycle             │
                    │ - token surfaces             │
                    │ - dispatch support           │
                    │ - payment orchestration      │
                    │ - telemetry ingestion        │
                    └───────┬─────────────┬────────┘
                            │             │
                            │             │
                            ▼             ▼
                 ┌────────────────┐   ┌──────────────────┐
                 │     STRIPE     │   │    TELEMETRY     │
                 │                │   │                  │
                 │ - auth hold    │   │ - driver GPS     │
                 │ - manual cap   │   │ - last ping      │
                 │ - payment refs │   │ - ETA inputs     │
                 │ - connected ac │   │ - execution intel│
                 └────────────────┘   └──────────────────┘
                            ▲
                            │
                            │
                            ▼
                 ┌──────────────────────────────┐
                 │            TENANT            │
                 │          sd_tenant           │
                 │                              │
                 │ - storefront identity        │
                 │ - domain / subdomain         │
                 │ - drivers                    │
                 │ - rides / quotes             │
                 │ - Stripe account             │
                 │ - operator configuration     │
                 └──────────────────────────────┘
2. Architecture at a Glance

The system is built around a simple rule:

Passenger and Driver interact with surfaces.
Surfaces talk to the Kernel.
The Kernel enforces lifecycle, tenant isolation, payments, and telemetry.

This means the kernel is the shared runtime, while tenants are the business operators using that runtime.

3. Actor Responsibilities
Passenger

The passenger is typically anonymous and interacts through:

/trip/<token>

The passenger surface is responsible for:

viewing quote results

accepting or rejecting a presented quote

seeing ride status

seeing driver ETA and live progress

following pickup instructions

The passenger does not log into WordPress by default.

Driver

The driver is an authenticated platform user with a driver role and uses driver-facing tools for:

availability

telemetry/location reporting

assigned ride visibility

ride execution updates

Driver surfaces are operational, while the rider surface is token-based and public.

Tenant

A tenant is the independent operator running a ride service on the platform.

A tenant owns:

storefront identity

domain or mapped hostname

drivers

rides

quotes

payment account connection

operating rules

The tenant is a first-class record, not a theme setting or install assumption.

Kernel

The kernel is the shared platform runtime:

solodrive-kernel

It is responsible for:

canonical data models

lifecycle orchestration

public token access

payment coordination

telemetry ingestion

tenant resolution

operator/admin tooling

The kernel must remain tenant-agnostic.

Stripe

Stripe is the payment execution layer.

Its role is to support:

authorization before fulfillment

manual capture after ride completion

tenant/operator payout model

durable payment references for rides and quotes

Conceptually:

quote accepted -> payment authorized -> ride completed -> payment captured
Telemetry

Telemetry provides the live operational signal that powers trip awareness.

It includes:

driver GPS updates

last-known timestamp

location accuracy

distance-to-pickup context

execution intelligence for trip surfaces

Telemetry is not just a map feature.
It is part of the execution model.

4. System Layer Diagram
┌──────────────────────────────────────────────┐
│              EXPERIENCE LAYER                │
│  Passenger trip page / Driver tools / Ops UI │
└──────────────────────────┬───────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────┐
│               KERNEL LOGIC LAYER             │
│   lifecycle + routing + tokens + payments    │
└──────────────────────────┬───────────────────┘
                           │
              ┌────────────┴────────────┐
              ▼                         ▼
┌──────────────────────┐      ┌──────────────────────┐
│   PAYMENT LAYER      │      │   TELEMETRY LAYER    │
│       Stripe         │      │ driver pings / intel │
└────────────┬─────────┘      └────────────┬─────────┘
             │                             │
             └────────────┬────────────────┘
                          ▼
           ┌────────────────────────────────┐
           │       TENANT DATA LAYER        │
           │ sd_tenant / sd_ride / sd_quote │
           └────────────────────────────────┘
5. Hosting Architecture

The system is hosted centrally and serves tenant storefronts from the platform runtime.

                    app.solodrive.pro
                           │
        ┌──────────────────┼──────────────────┐
        │                  │                  │
        ▼                  ▼                  ▼
   soga-go.com      tenant.solodrive.pro   future domains
        │                  │                  │
        └──────────────────┴──────────────────┘
                           │
                           ▼
                    solodrive-kernel

Once a ride, quote, or attempt record exists, tenant context must be read from the record's assigned tenant id, not inferred from the current URL.

6. SoGa-Go's Role in the Architecture

SoGa-Go is not the platform.
SoGa-Go is the first tenant operating on the platform and serves as the operational proof-of-concept and live testing environment for SOLODRIVE.PRO.

That means:

SOLODRIVE.PRO is the system

solodrive-kernel is the runtime

SoGa-Go is Tenant-001

This distinction must remain clear in code, docs, and naming.

7. Naming Conventions
Platform Naming
SOLODRIVE.PRO = platform
solodrive-kernel = runtime kernel
SoGa-Go = tenant brand
Prefix Naming
sd_      = kernel data / canonical platform primitives
sd-      = shared UI primitives
tenant-  = tenant-specific visual overrides
Conceptual Naming Rule
Platform != Tenant
Kernel != Brand
Tenant config != core logic
8. Developer Reading Order

A new developer should understand the system in this order:

1. Project Identity
2. System Lifecycle Overview
3. System Architecture Diagram

That sequence explains:

who the system is
how the lifecycle works
how the major components connect
9. Final Principle
Passenger experience, driver operations, payments, and telemetry
all converge through the kernel,
but always remain tenant-scoped.

That is the core architectural idea of SOLODRIVE.PRO.