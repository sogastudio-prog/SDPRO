# System Lifecycle Overview (Canonical)

## Core Flow

Lead → Quote → Auth → Ride → Capture

Lead = captured intent
Quote Draft = internal/proposed
Presented Quote = tenant-approved and customer-visible
Authorization = customer commitment
Ride = post-auth execution object

---

## 1. Lead (Entry Point)

A Lead represents a qualified transportation intent.

Created via storefront submission.

Required:
- tenant_id
- pickup/dropoff place_id
- time
- customer identity

---

## 2. Quote

A Quote is first created as an internal draft in response to a Lead.

The tenant must approve, adjust, or reject it.

Only an approved quote may be presented to the lead.

Rules:
- no pricing logic exposed before presentation
- generated once per decision cycle
- multiple active quotes must be prevented by idempotency
- only one presented quote may be active per lead

## 3. Authorization

User accepts quote → payment authorization occurs.

This is the **commit point**.

---

## 4. Ride

Ride is created ONLY after successful authorization.

Ride represents:
- a funded job
- an execution contract

---

## 5. Capture

Occurs after ride completion.

---

## Core Rule

Nothing creates downstream objects unless:

→ State machine explicitly allows it