🧾 SoloDrive Tenant Onboarding SOP (v2 — Stripe-First Model)
🎯 Purpose

To onboard new tenants into the SoloDrive platform using a zero-effort-before-payment model.

No Stripe Connect → No Tenant → No Work

🧭 System Overview
solodrive.pro (Control Plane)
Tenant acquisition
Stripe onboarding
Tenant creation + support
app.solodrive.pro (Execution Plane)
Storefronts
Ride intake
Payments + application fees
🔒 Core Rule (NON-NEGOTIABLE)

A tenant is ONLY created after Stripe Connect is completed.

🔁 Onboarding Workflow
🟢 STEP 1 — Tenant Intake (solodrive.pro)
Action:

Tenant submits onboarding form

Collect:
Name
Business name
Phone
Email
Result:
Tenant Lead created (no system resources allocated)
DO NOT:
Create tenant
Configure anything
Touch WordPress
🟡 STEP 2 — Stripe Connect (HARD GATE)
Action:

Send tenant to Stripe onboarding link

Tenant must:
Complete Stripe Connect onboarding
Enable payments (charges_enabled = true)
Result:

You receive:

acct_XXXXXXXX
This is:

TENANT_CAPTURED

If NOT completed:
Stop process
No follow-up work
🔵 STEP 3 — Confirm Stripe Status
Verify:
Stripe account exists
charges_enabled = true
If not:
Follow up with tenant
Do NOT proceed
🟣 STEP 4 — Create Tenant (Manual — for now)
In WordPress Admin:

Create new sd_tenant

Required Fields:
Identity
sd_tenant_slug → unique (e.g. mike)
Domain

sd_tenant_domain →

mike.solodrive.pro
Stripe

Connected Account ID →

acct_XXXXXXXX
Storefront
Enabled → YES
Accepting Requests → YES
🟠 STEP 5 — Activate Storefront
Visit:
https://{slug}.solodrive.pro
Confirm:
Page loads (NOT “Storefront unavailable”)
tenant_id != 0 (debug card)
🔴 STEP 6 — Run Test Transaction

Perform full flow:

Submit ride request
Generate quote
Accept quote
Authorize payment (Stripe)
Confirm:
Payment authorized
Tenant Stripe account receives transaction
Platform fee applied
🟢 STEP 7 — Tenant Handoff

Send tenant:

Your SoloDrive storefront is live:

https://{slug}.solodrive.pro

You can now begin accepting ride requests immediately.
⚠️ Common Failure Points
❌ Tenant shows “Storefront unavailable”
Resolver not matching
sd_tenant_slug mismatch
MU resolver not running
❌ tenant_id = 0
Slug mismatch
Tenant not published
Resolver/storefront contract mismatch
❌ Payments not working
Stripe not fully onboarded
charges_enabled = false
Wrong account ID stored
❌ Wrong domain behavior
DNS misconfigured
Subdomain not resolving
Domain field mismatch
🧠 Operational Rules
1. Never create tenant before Stripe
2. Never troubleshoot unpaid tenants
3. Never manually “fix” incomplete onboarding
4. Always verify Stripe before proceeding
🚀 Near-Term Automation Goals
Phase 1 (Next Build)
“Unassigned Stripe Accounts” list
Button: Create Tenant
Phase 2

Stripe webhook:

account.updated (charges_enabled = true)

→ Auto-create tenant
→ Auto-assign domain
→ Auto-enable storefront

Phase 3

Fully automated:

Form → Stripe → Auto Tenant → Live URL → Email sent
💡 Key Insight (Cultural Rule)

This system is not:

“Sign up and we’ll help you”

This system is:

“Connect payments, and your business goes live instantly”

✅ Final State

A successful onboarding always ends with:

Tenant Stripe Account → Connected
Tenant Storefront → Live
Payments → Flowing
Platform Fees → Captured