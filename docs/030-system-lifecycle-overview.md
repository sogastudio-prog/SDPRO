# Replacement: `030-system-lifecycle-overview.md`

```md
# System Lifecycle Overview

Status: ACTIVE  
Purpose: Give a fast, high-signal overview of the current system posture and the target canonical lifecycle.

---

## Two Truths

### Current Working System
The current runtime is ride-first and has already reached payment capture.

### Target Canonical System
The target runtime is lead-first and will eventually govern engagement end-to-end.

Rule:

> This document is intentionally brief. It orients. It does not replace Architecture.md or runtime-request-lifecycle.md.

---

## Core Flows

### Current Working Flow

```txt
Ride-first intake → Quote → Authorization → Capture
Target Canonical Flow
Lead → Quote Draft → Tenant Decision → Presented Quote → Auth Attempt → Ride → Capture
Definitions
Lead

Captured transportation intent.
Target canonical parent of the engagement lifecycle.

Quote Draft

Internal/proposed quote not yet shown to the customer.

Presented Quote

Tenant-approved quote that is customer-visible and actionable.

Auth Attempt

Payment authorization attempt and Stripe correlation layer.

Ride

Operational execution record.
Target posture: post-auth only.

Capture

Final payment capture after ride completion.

Lifecycle Meaning
Current
intake and lifecycle spine currently live on ride-first structure
the system has proven payment authorization/capture under that implementation
Target
lead becomes the primary engagement identifier
quote presentation must pass through tenant approval
ride becomes a downstream operational object
Quote Rule

A quote is first created as an internal draft.
The tenant must approve, adjust, or reject it.
Only an approved/presented quote may be shown to the customer.

Availability Rule

Availability is assumed by default and reduced by constraints later.

Mental model:

ASSUME YES → PROVE NO
Core Rule

Nothing creates downstream objects unless the state machine explicitly allows it.


---

