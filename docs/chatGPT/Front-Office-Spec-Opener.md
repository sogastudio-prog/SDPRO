Front-Office Spec Opener

We are defining the SoloDrive Front-Office as the control-plane system that lives in solodrive.pro.

Its job is to manage the tenant relationship, not tenant ride operations.

The current platform model already locks solodrive.pro as the system for acquiring tenants, qualifying them, collecting Stripe Connect, and providing onboarding + support, while app.solodrive.pro remains the execution plane for storefronts, rides, payments, and fees. The two funnels must remain separate: tenant acquisition/support in Front-Office, ride revenue/execution in the app runtime.

Working definition

Front-Office = tenant acquisition + onboarding + Stripe support + tenant relationship management + platform staff support workflows

It is the internal system of record for the tenant account lifecycle.

It is not the dispatch console, ride queue, quote decision surface, or rider operations system. Those remain in app.solodrive.pro.

Primary purpose

Front-Office exists to help platform staff:

acquire new tenants
guide them through onboarding
manage Stripe Connect readiness
create and launch tenants once qualified
support tenants after launch
monitor tenant health at the account level
intervene when onboarding, payouts, settlements, or account compliance issues arise

This aligns with the locked rule that a tenant should not exist before Stripe is connected and verified, and that solodrive.pro handles onboarding/support while app.solodrive.pro handles execution.

Biggest support domains

The two highest-value Front-Office support domains are expected to be:

1. Onboarding

prospect intake
business qualification
onboarding progress
launch readiness
tenant creation
storefront activation

2. Stripe

Connect onboarding status
charges enabled / payouts enabled
account restrictions
settlement and payout support
payment-related tenant support
support follow-up around Stripe compliance or account issues

These belong in Front-Office because Stripe is the hard gate to tenant creation and the main support-intensive dependency across the tenant lifecycle.

What Front-Office owns

Front-Office should own:

tenant lead/prospect records
onboarding milestones
Stripe Connect account status
tenant activation readiness
one-click tenant creation workflow
slug/domain assignment workflow
storefront enablement status
tenant support notes
staff interventions
tenant health summaries
relationship/account stewardship over time
What Front-Office must not own

Front-Office must not become:

a ride operations dashboard
a dispatch surface
a driver management runtime
a quote decision surface
a rider support console for live trip execution
the system that processes rides

Those belong to the execution plane in app.solodrive.pro.

Integration boundary with app.solodrive.pro

Front-Office may observe execution-plane health, but should not duplicate execution controls.

Allowed backflow from the app runtime into Front-Office:

storefront live/not live
recent ride activity
auth/capture failure summaries
payout or Stripe anomaly flags
support-needed alerts
tenant health signals

Not allowed:

direct ride manipulation from Front-Office
dispatching drivers from Front-Office
quote/ride lifecycle control from Front-Office
Canon statement

solodrive.pro Front-Office is the tenant control plane: acquisition, onboarding, Stripe support, tenant relationship management, and platform staff support. app.solodrive.pro is the tenant execution plane: storefront, rider funnel, ride operations, and payment execution.

Immediate design target

The Front-Office spec should now focus on defining:

tenant record model
onboarding stages
Stripe support states
tenant creation trigger
post-launch support workflow
tenant health summary contract
platform staff UI boundaries
explicit “observe vs control” rules between Front-Office and execution plane