# Canon Enforcement (LOCKED)

## Purpose

Prevent architectural drift and reintroduction of invalid patterns.

---

## 1. Entity Hierarchy (LOCKED)

platform → tenant → lead → quote → auth → ride

---

## 2. Creation Rules

### Lead
- Created at storefront submission

### Quote
- Created by state machine only
- Must be idempotent

### Ride
- Created ONLY after authorization

---

## 3. Availability Doctrine

ASSUME YES → APPLY CONSTRAINTS

---

## 4. Forbidden Patterns

The following are NOT allowed:

❌ Creating ride at intake  
❌ Checking availability before quote  
❌ Requiring driver schedule setup  
❌ Creating objects on page load (GET request)  
❌ Multiple active quotes per lead  

---

## 5. State Machine Authority

No object may be created or transitioned without:

→ explicit state transition approval

---

## 6. Token Authority

Public access must always resolve:

token → lead → tenant

Never:
- tenant from URL after entry
- ride as entry point

---

## 7. Read vs Write Separation

GET requests must NEVER:
- create records
- mutate state

---

## 8. Naming Enforcement

Use ONLY:

- Lead (not request, not ride)
- Quote
- Ride (post-auth only)

---

## 9. Debug Rule

If something is duplicated or out of order:

→ Check:
1. state machine
2. idempotency
3. hook duplication

---

## Final Law

Nothing happens unless the state machine says so.