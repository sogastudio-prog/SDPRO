# SoloDrive Kernel — Documentation Gaps

## What was missing

The zip did not contain:
- a `/docs` directory
- an architectural README
- a deployment/install guide
- an operator guide
- a Stripe configuration guide
- a state-machine reference
- a release/build checklist

## What had to be inferred

The reconstructed docs relied on:
- module names and inline comments
- canonical meta registry
- lifecycle definitions and services
- the uploaded doctrine and architecture PDFs
- the project scope text file

## Missing documents that should exist in-source

1. `docs/DOCTRINE.md`
   - tenant-first rules
   - canonical meta doctrine
   - lifecycle doctrine
   - Stripe doctrine

2. `docs/ARCHITECTURE.md`
   - request flow
   - token routing
   - payment correlation
   - operator surfaces

3. `docs/STATE-MACHINES.md`
   - lead lifecycle
   - quote lifecycle
   - ride lifecycle
   - allowed transitions and invariants

4. `docs/DEPLOYMENT.md`
   - required constants
   - Stripe bootstrap expectations
   - rewrite/activation steps
   - dependency packaging rules

5. `docs/TENANCY.md`
   - tenant resolution order
   - tenant-scoped records
   - access rules
   - domain/handle model

6. `docs/OPS-SURFACES.md`
   - operator trips
   - dispatch board
   - admin metaboxes
   - intended roles/caps

7. `docs/RELEASE-CHECKLIST.md`
   - remove `.DS_Store`
   - verify Stripe classes load
   - verify rewrite rules
   - verify webhook endpoint
   - verify `/trip/<token>` surface

## Bottom line

A first-party `/docs` tree should ship with the plugin. Right now, critical platform knowledge lives partly in code comments and partly outside the repository.
