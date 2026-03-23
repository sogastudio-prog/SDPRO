SoloDrive Tenancy Model

Status: LOCKED

SoloDrive is a tenant-first platform.

Tenants represent independent transportation businesses.

1. Tenant Record

Tenant CPT:

sd_tenant

Tenant records store:

tenant_domain
tenant_slug
storefront_settings
stripe_account
operational_policies
2. Tenant Resolution Order

Tenant must be resolved in this order:

1. Subdomain
2. Custom domain
3. Path slug
4. Fallback platform tenant

Example:

acme.solodrive.pro

or

ride.acme.com
3. Tenant-Scoped Records

All tenant data must include:

sd_tenant_id

Applies to:

sd_ride
sd_quote
sd_attempt
sd_waitlist
4. WordPress Users

Authenticated actors are WordPress users.

Roles:

tenant_owner
tenant_operator
sog_driver
platform_admin

Passengers remain anonymous.

They access rides using:

/trip/<token>
5. Token Access Model

Public surfaces use token-based access.

Example:

/trip/<token>

Tokens provide:

status visibility
driver ETA
ride tracking
decision UI

Tokens must:

be unguessable
be indexed
support rotation
6. Tenant Isolation

Kernel rule:

No tenant data may leak across tenants.

Isolation is enforced by:

tenant resolver
tenant scoped queries
admin tenant filtering