SOLODRIVE.PRO — Project Identity

Platform Architecture Overview

Status: LOCKED
Applies to: SOLODRIVE.PRO — System Build

1. Platform

SOLODRIVE.PRO is a multi-tenant ride-service platform that enables independent transportation operators to run their own branded ride services.

The platform provides:

ride request intake

driver dispatch

quote lifecycle management

trip execution surfaces

payments

tenant storefront infrastructure

The system is designed so each tenant operates an independent ride business while the platform provides the shared infrastructure.

Core principle:

Tenant-first architecture.

Tenants are first-class system entities, not configuration.

2. Kernel

The SoloDrive Kernel is the core runtime system.

Repository / plugin:

solodrive-kernel

The kernel provides the shared platform capabilities:

data models (CPTs)

lifecycle state machines

Stripe payment orchestration

public token surfaces

driver telemetry infrastructure

tenant routing

admin operational tools

The kernel contains no tenant-specific logic.

Tenant behavior must always be configured through:

tenant records

tenant metadata

tenant storefront configuration

3. Tenants

A tenant represents an independent ride service operator.

Each tenant has:

its own domain or subdomain

its own storefront

its own drivers

its own rides and quotes

its own Stripe account

Tenants are stored as records:

sd_tenant (CPT)

Every operational record must contain:

sd_tenant_id

Examples of tenant-scoped records:
sd_lead
sd_ride
sd_quote
sd_attempt
sd_driver_application

This guarantees strict tenant data isolation.

4. SoGa-Go Role

SoGa-Go is Tenant-001 of the SoloDrive platform.

SoGa-Go serves three purposes:

Operational ride service

Platform proof-of-concept

Live testing environment for new platform features

SoGa-Go is not the platform itself.

It is simply the first tenant operating on the platform.

Domain:

soga-go.com

Hosting:

Powered by app.solodrive.pro
5. Hosting Architecture

The platform runs on a centralized host:

app.solodrive.pro

Tenants resolve to the platform through:

tenant.solodrive.pro

or

custom domains:

tenantdomain.com

Example:

app.solodrive.pro
   ├─ soga-go.com
   ├─ tenant2.solodrive.pro
   └─ future tenants

Tenant resolution occurs through:

Domain mapping

Tenant slug

Fallback platform tenant

Once a record is created, tenant context is always read from sd_tenant_id, never from the URL.

6. Naming Conventions

To maintain architectural clarity, strict naming conventions apply.

Code Prefixes
Prefix	Purpose
sd_	SoloDrive kernel primitives
tenant-	tenant visual overrides
sd-	shared UI components
CPT Names
CPT	Purpose
sd_tenant	tenant record
sd_ride	ride request / trip record
sd_quote	quote lifecycle record
sd_attempt	payment attempts
sd_driver_application	driver intake
Identity Model

WordPress users represent authenticated humans:

Role	Description
Owner	tenant operator
Driver	vehicle operator
Staff	dispatch/admin

Passengers remain anonymous and access the system through:

/trip/<token>

Token-based surfaces are intentionally public but unguessable.

7. Canonical System Model
SOLODRIVE.PRO
   │
   ├─ solodrive-kernel
   │      (platform runtime)
   │
   ├─ Tenants
   │      ├─ SoGa-Go (Tenant-001)
   │      ├─ Future Tenant
   │      └─ Future Tenant
   │
   └─ Platform Host
          app.solodrive.pro
Final Principle

The system must always maintain the separation:

Platform ≠ Tenant

SoloDrive is the platform.

SoGa-Go is a tenant of the platform.