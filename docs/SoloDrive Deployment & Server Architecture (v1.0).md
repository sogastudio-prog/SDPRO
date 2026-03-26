🔧 Refined: SoloDrive Deployment & Server Architecture (v1.0)
1. Platform Topology (Canonical)
solodrive.pro        → Marketing / Public Website (WordPress)
app.solodrive.pro    → Application Runtime (WordPress + Kernel)
{tenant}.solodrive.pro → Tenant Entry Point (DNS → app)
Purpose of Each Layer
Layer	Role	Notes
solodrive.pro	Marketing / acquisition	No system logic
app.solodrive.pro	Core system runtime	All logic lives here
{tenant}.solodrive.pro	Tenant resolver	Context only, no logic
2. Tenant Resolution Flow (CRITICAL)

This is the backbone of the platform.

Step-by-step:

User lands on:

mike.solodrive.pro
DNS routes → app.solodrive.pro

System extracts:

tenant_slug = "mike"

Kernel resolves:

tenant_id ← lookup(sd_tenant where slug = "mike")

Storefront loads:

app.solodrive.pro/storefront
Tenant context is now locked via sd_tenant_id
3. Lead → Token → System Entry (Public Flow)

After form submission:

Lead is created
CPT: sd_lead (or ride depending on current implementation)
Required:
sd_tenant_id
pickup/dropoff place_id
time
name + phone

Token is minted

token = secure_random()

Token stored on Lead:

sd_trip_token = token

Redirect:

/trip/<token>
4. Token Becomes the Public Interface

This is a core system rule:

After creation, the system no longer depends on URL tenant context.

Instead:

token → lead_id → sd_tenant_id → everything
Why this matters:
Supports third-party riders
Prevents tenant leakage
Enables secure public access
Allows stateless frontend
5. File Structure (Hostinger Reality)

Current structure:

/domains/solodrive.pro/public_html/

├── (root WP install)         → solodrive.pro
├── app/                      → app.solodrive.pro (MAIN SYSTEM)
├── mike/                     → tenant stub (can be deprecated later)
├── beta/ / alpha/            → staging (optional)

Inside app:

/app/wp-content/

├── plugins/
│   ├── solodrive-kernel      ← CORE SYSTEM (THIS IS THE PRODUCT)
│   └── contact-form-7        ← Intake layer
│
├── mu-plugins/               ← Bootstrap / enforcement
├── themes/                   ← Minimal (UI shell only)

6. Kernel Ownership (NON-NEGOTIABLE)
solodrive-kernel = the platform
WordPress = runtime shell
Rules:
No business logic in themes
No tenant logic in wp_options
No logic in subdomain folders
Everything flows through the kernel
7. Domain & Routing Rules
DNS
*.solodrive.pro → app.solodrive.pro
Behavior
Domain	Action
mike.solodrive.pro	Resolve tenant
app.solodrive.pro/trip/<token>	Run system
solodrive.pro	Marketing only
8. Data Ownership Model (LOCKED)

Every record must include:

sd_tenant_id
Record hierarchy:
Lead (entry point)
  ↓
Quote(s)
  ↓
Auth Attempt(s)
  ↓
Ride
  ↓
Payment Capture
Public access always begins with:
token → lead_id
9. What This Architecture Enables
✅ Multi-tenant SaaS (clean isolation)
✅ Token-based public UX (no login required)
✅ Third-party booking support
✅ Strong chargeback evidence (logs + tokens)
✅ SEO + location signal capture
✅ Stateless frontend rendering
10. What Needs Tightening (Action Items)

Based on your current state:

🔴 1. Tenant stub folders (/mike)

These should eventually be:

removed OR
reduced to pure DNS forwarding
🔴 2. Storefront routing clarity

Make explicit:

/storefront → reads sd_tenant_id from resolver
🔴 3. Lead vs Ride clarity

Right now slightly mixed:

You’ve said:

lead > quote > auth-attempt > ride > capture

So:

Ensure lead is the true entry object
Ride should NOT be created too early
🔴 4. Token authority

Ensure:

Token lives on lead (canonical)
Not duplicated across objects
🔴 5. App install isolation

You currently have:

root WP + app WP

That’s fine, but document clearly:

Only /app matters for system execution

11. Repository (Source of Truth)

GitHub:


Rule:

GitHub = canonical code
Hostinger = deployment target

12. Legacy Reference
_plugins.zip = pre-SaaS system (reference only)

🔑 Final Takeaway (What a New Dev Must Understand in 30 Seconds)
All logic lives in app.solodrive.pro
Tenants are resolved from subdomain → sd_tenant_id
Tokens replace identity for public users
Everything flows through Lead → Quote → Ride
Kernel owns all behavior