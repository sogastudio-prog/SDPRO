🧭 SoloDrive Platform Model (LOCK THIS)
⚡ The Core Truth (Read This First)

Drivers already have customers.

They meet them every day:

Uber rides
Lyft rides
airport trips
local demand

The problem:

They don’t own those relationships.

SoloDrive fixes that.

SoloDrive turns rides drivers are already giving into customers they actually own.

🚨 The Reality SoloDrive Captures

SoloDrive does not generate demand.

It captures demand that already exists — inside the driver’s current rides.

Every ride is:

a trust-building moment
a future booking opportunity
a potential repeat customer

SoloDrive provides the infrastructure to convert that moment into a business.

🧩 Two Systems, Two Jobs (NON-NEGOTIABLE)
1. solodrive.pro → Your Business (Control Plane)

Purpose:

acquire tenants
qualify drivers
collect Stripe Connect
onboard + support

This is:

👉 Tenant acquisition + onboarding + support system

2. app.solodrive.pro → Tenant Business (Execution Plane)

Purpose:

run storefronts
capture rides
process payments
collect application fees

This is:

👉 Revenue engine (for both tenant AND platform)

🔒 Critical System Law

These two systems must NEVER be mixed.

solodrive.pro NEVER processes rides
app.solodrive.pro NEVER handles onboarding

This separation ensures:

platform neutrality
legal protection
clean revenue model
scalable architecture
🔁 The Real-World Flow (What Actually Happens)
Before the System (Real World)

Driver gives a ride → passenger trusts driver

👉 This is where SoloDrive begins

After the Ride

Driver says:

“Next time, just book me directly.”

Shares link → SoloDrive storefront

What SoloDrive Does
captures the booking
processes payment
powers the experience
enables repeat rides

👉 The relationship now belongs to the driver

🔁 End-to-End Platform Flow (System)
Phase 1 — Marketing Intake (solodrive.pro)

solodrive.pro
↓
Driver submits onboarding form

You collect:

name
business info
phone/email

👉 No system resources allocated yet

Phase 2 — Stripe Connect (HARD GATE)

solodrive.pro
↓
Redirect → Stripe Connect onboarding

Result:

acct_***
charges_enabled = true

👉 This is your:

TENANT_CAPTURED moment

🔒 Rule

No Stripe → No Tenant → No Work

Phase 3 — Confirm + Configure (solodrive.pro)

solodrive.pro/admin
↓
“Stripe account ready”
↓
Create tenant

System actions:

generate:
slug (e.g. mike)
domain (mike.solodrive.pro)
create sd_tenant
attach:
connected_account_id = acct_***
enable storefront
Phase 4 — Live Tenant (Execution Begins)

https://mike.solodrive.pro

Now:

tenant resolves via slug
storefront is live
ride intake begins
Stripe authorization → capture → fees
💰 Revenue Model (Crystal Clear)
solodrive.pro earns:
onboarding control
future SaaS tools
tenant support services
app.solodrive.pro earns:
Stripe application fees
per-ride transaction revenue
🧠 The Two Funnels (NEVER MIX THESE)
Funnel A — Tenant Acquisition

solodrive.pro
→ onboarding form
→ Stripe Connect
→ tenant created

Funnel B — Ride Revenue

app.solodrive.pro
→ ride request
→ quote
→ auth
→ capture
→ application fee

🔒 Rule

These funnels must remain completely independent.

🧩 Why This Model Works

Because drivers are already doing the hardest part:

finding passengers
building trust
completing rides

SoloDrive simply:

👉 captures that value instead of letting it disappear

🧠 What You’re Actually Selling

You are not selling software.

You are selling:

“Turn your current driving into your own transportation business — instantly.”

🔒 System Rules (LOCK)
No Stripe → No Tenant
no sd_tenant
no domain
no storefront
Tenant exists only after:
Stripe connected + verified
solodrive.pro
onboarding + support ONLY
never touches rides
app.solodrive.pro
execution ONLY
never handles onboarding
🧩 What You Need to Build Next
1. Stripe → Tenant Bridge (Critical)

After Stripe onboarding:

Store:

acct_id
email
business_name
status

Then show in admin:

👉 “Ready to Create Tenant”

2. One-Click Tenant Creation

Button:

[Create Tenant]

Auto:

slug generation
domain assignment
tenant creation
Stripe connection
3. Auto Domain Assignment

slug → subdomain

mike → mike.solodrive.pro

4. Post-Creation Activation

After onboarding:

👉 “Your storefront is live:”
https://mike.solodrive.pro

🚀 Where This Leads (Next Phase)
Fully Automated Onboarding

solodrive.pro form
→ Stripe Connect
→ webhook
→ auto-create tenant
→ email:

“You’re live.”

🎯 Strategic Position (Final Alignment)

SoloDrive is not a rideshare marketplace.

It is:

👉 Infrastructure for driver-owned transportation businesses

🔥 Final Summary

Drivers already meet the customers.

SoloDrive makes those customers theirs.

System flow:

Rideshare ride
↓
Driver builds trust
↓
Driver shares link
↓
Passenger books directly
↓
SoloDrive processes ride
↓
Platform collects fee
↓
Driver builds repeat business

End of Document