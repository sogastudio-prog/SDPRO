# System Lifecycle Overview (Canonical)

## Core Flow

Lead → Quote → Auth → Ride → Capture

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

System-generated offer in response to a Lead.

- No pricing logic exposed
- Generated once per decision cycle
- Multiple quotes must be prevented via idempotency

--- >>>>>>>>>>>>>> Tenant muust approve before quote is presentedd to lead

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