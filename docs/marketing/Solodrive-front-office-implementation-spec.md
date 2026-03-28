# SoloDrive Front-Office Implementation Spec

## 1) WordPress lifecycle model

### Core rule

A tenant is not a prospect and not a lead.
A tenant is a Stripe-linked, revenue-capable entity.

### Lifecycle

Anonymous
↓
Prospect
↓
Invited Prospect (optional fast lane)
↓
Lead (Stripe started)
↓
Tenant (Inactive)
↓
Tenant (Active)

## 2) Recommended WordPress object model

### A. CPT: `sd_prospect`

Purpose: pre-tenant intake and onboarding tracking.

This CPT covers:

* Prospect
* Invited Prospect
* Lead

Do not create a tenant CPT record until Stripe onboarding has started successfully and you intentionally promote the record.

### B. CPT: `sd_tenant`

Purpose: actual tenant entity.

This CPT covers:

* Tenant (Inactive)
* Tenant (Active)

### C. Anonymous

No CPT record. Anonymous exists only as sessionless public traffic until a validated form is submitted.

## 3) `sd_prospect` meta schema

### Identity

* `sd_prospect_id` — unique immutable public-safe identifier
* `sd_lifecycle_stage` — enum:

  * `prospect`
  * `invited_prospect`
  * `lead`
* `sd_source` — enum/string, e.g. `request_access_form`, `manual`, `referral`
* `sd_created_at`
* `sd_updated_at`

### Minimal intake fields

* `sd_full_name`
* `sd_phone`
* `sd_email`
* `sd_city`
* `sd_market`
* `sd_current_platform` — e.g. `uber`, `lyft`, `both`, `independent`, `other`

### Invitation fields

* `sd_invitation_code` — nullable
* `sd_invitation_status` — enum:

  * `none`
  * `submitted`
  * `valid`
  * `invalid`
  * `manual_override`
* `sd_invited_by` — nullable user/id/string
* `sd_priority_lane` — boolean

### Staff / workflow

* `sd_review_status` — enum:

  * `new`
  * `reviewed`
  * `qualified`
  * `disqualified`
  * `hold`
* `sd_staff_notes`
* `sd_last_staff_action_at`
* `sd_owner_user_id` — nullable internal owner

### Stripe onboarding transition

* `sd_stripe_onboarding_started_at`
* `sd_stripe_account_id` — nullable until returned
* `sd_stripe_onboarding_status` — enum:

  * `not_started`
  * `started`
  * `account_created`
  * `requirements_due`
  * `charges_enabled`
  * `failed`
* `sd_promoted_to_tenant_id` — nullable

## 4) `sd_tenant` meta schema

### Identity

* `sd_tenant_id` — unique immutable identifier
* `sd_slug`
* `sd_domain`
* `sd_status` — enum:

  * `inactive`
  * `active`
* `sd_created_at`
* `sd_updated_at`

### Relationship back to prospect

* `sd_origin_prospect_id`

### Stripe

* `sd_connected_account_id`
* `sd_charges_enabled` — boolean
* `sd_payouts_enabled` — boolean
* `sd_stripe_status_snapshot`

### Storefront / activation

* `sd_storefront_status` — enum:

  * `not_live`
  * `live`
* `sd_activation_ready` — boolean
* `sd_activated_at`

### Tenant ops summary (observe, not control)

* `sd_last_activity_at`
* `sd_support_flag`
* `sd_payment_flag`
* `sd_health_status` — enum:

  * `healthy`
  * `attention`
  * `critical`

## 5) Lifecycle transition rules

### Anonymous → Prospect

Trigger: validated `/request-access/` submission.
Action:

* create `sd_prospect`
* assign `sd_lifecycle_stage = prospect`
* store minimal fields

### Prospect → Invited Prospect

Trigger:

* valid invitation code on submission, or
* later manual validation by staff
  Action:
* keep same CPT
* change `sd_lifecycle_stage = invited_prospect`
* set `sd_priority_lane = true`

### Prospect / Invited Prospect → Lead

Trigger: Stripe onboarding started.
Action:

* keep same CPT
* set `sd_lifecycle_stage = lead`
* store Stripe onboarding state and account id when available

### Lead → Tenant (Inactive)

Trigger:

* Stripe account exists and tenant creation is intentionally executed
  Action:
* create `sd_tenant`
* link prospect via `sd_origin_prospect_id`
* set `sd_status = inactive`
* set `sd_storefront_status = not_live`

### Tenant (Inactive) → Tenant (Active)

Trigger:

* connected account is ready
* storefront/domain is live
* activation approved
  Action:
* set `sd_status = active`
* set `sd_storefront_status = live`
* store activation timestamp

## 6) Architectural rules to lock

1. Anonymous has no record.
2. Prospect, Invited Prospect, and Lead are all `sd_prospect`.
3. Tenant states live only in `sd_tenant`.
4. No tenant exists before intentional promotion from prospect/lead.
5. Email is never the source of truth.
6. CF7 submission creates or updates system records first; notifications are side effects.

## 7) `/apply/` replacement

### New slug

`/request-access/`

### CTA language

Primary CTA: **Request Access**

Secondary utility text: **Have an invitation code?**

## 8) `/request-access/` page copy

### H1

Request Access

### Intro

Access to SoloDrive is currently controlled.

Drivers are onboarded in phases to maintain system quality and operational stability.

### Form fields

* Full Name
* Mobile Phone
* Email Address
* Invitation Code (optional)

### Submit button

Request Access

### Supporting line under invitation field

Invitation codes are issued by active drivers and partners.

## 8.1) Immediate post-submit behavior (current phase)

For now, do not send users directly into Stripe from the public intake form.

Current behavior:

* validated submission creates `sd_prospect`
* user is redirected to a staged success screen
* staff controls when a prospect is moved into the onboarding workflow

This preserves lifecycle staging while Stripe handoff and Stripe success handling are still being finalized.

### Current rule

`/request-access/` creates staged prospects, not immediate onboarding sessions.

### Future rule

Once Stripe handoff control and Stripe success handling are complete, qualified prospect creation may flow directly into onboarding.

## 8.2) Success screen role

Purpose:

* confirm submission
* manage expectations
* preserve excitement
* reinforce controlled access

This screen should feel:

* calm
* selective
* forward-moving
* professional

## 8.3) Success screen copy (recommended)

### H1

Request Received

### Lead message

You’re in.

SoloDrive is onboarding drivers in controlled stages to maintain quality and readiness.

Your request has been received and added to the next review queue.

### Supporting message

If your next onboarding step is available, we will contact you directly.

### Optional excitement line

You are not joining another app.
You are entering a system designed to help drivers build direct repeat business.

### Invitation-priority variant

If a valid invitation code was recognized, use:

**Invitation Recognized**

Your request has been prioritized for the next onboarding stage.

### CTA options on success screen

Use one low-pressure CTA only:

* `Return to Home`
* `Learn How SoloDrive Works`

Do not place a second intake CTA on the success screen.

## 9) CF7 implementation for `/request-access/`

### Keep CF7 for

* front-end form rendering
* field validation
* spam protection
* thank-you UX

### Do not rely on CF7 mail for

* lifecycle state
* dedupe
* invite logic
* Stripe state
* staff queue truth

### Required submission flow

1. Public user submits CF7 form.
2. Custom handler validates normalized payload.
3. System creates `sd_prospect`.
4. If invitation code is valid:

   * set `sd_lifecycle_stage = invited_prospect`
   * set priority lane true.
5. If no code or invalid code:

   * remain `prospect`.
6. Send notifications only after successful record write.
7. Redirect to staged success screen.
8. Do not initiate public Stripe onboarding from this step yet.

## 10) Thank-you states

### No valid code

Request received.

SoloDrive is onboarding drivers in controlled stages.
If your next onboarding step is available, we will contact you directly.

### Valid code

Invitation recognized.

Your request has been prioritized for the next onboarding stage.

## 11) Landing page update structure

### Section order

1. Hero
2. Reality layer
3. CTA #1
4. Visual block #1 — product screenshots
5. Features
6. CTA #2
7. Visual block #2 — financial reality
8. Final positioning
9. Final CTA

## 12) Landing page copy skeleton

### Hero

**You Already Have the Customer.**
Now own the relationship.

SoloDrive gives independent drivers the infrastructure to turn existing rides into direct repeat business.

Primary CTA: **Request Access**
Secondary utility text: **Have an invitation code?**

### Reality layer

Drivers do not need more random trips.
They need to keep the customers they already earn.

Every day, a driver meets passengers, builds trust, completes the ride, and loses the relationship.
SoloDrive changes that.

### CTA block

**Request Access**

### Visual block #1 title

**What This Actually Looks Like**

Use 3 visuals:

* storefront
* trip page
* booking flow

Captions:

* Your storefront
* Live trip experience
* Direct booking

### Features title

**What’s Included**

Feature list:

* Driver storefront
* Direct ride requests
* Real-time trip status pages
* Passenger communication
* Payment processing
* Repeat booking capability
* Third-party ride support

### CTA #2

**Activate Your Storefront**

### Visual block #2 title

**What You’re Currently Giving Away**

Comparison framing:

Typical rideshare trip:

* passenger relationship belongs to the platform
* repeat access is limited
* economics are controlled elsewhere

Direct booking with SoloDrive:

* driver owns the relationship
* repeat bookings stay accessible
* platform charges a small application service fee

Supporting line:
If you do not use it, you do not pay.

### Final positioning

SoloDrive is not a rideshare marketplace.
It is infrastructure for independent transportation businesses.

### Final CTA

**Turn Your Rides Into Clients**

## 13) Recommended next implementation order

1. Register CPTs and lifecycle meta
2. Build `sd_prospect` admin columns and filters
3. Replace `/apply/` with `/request-access/`
4. Wire CF7 submission into custom prospect creation
5. Add invitation code validation path
6. Add manual promote-to-tenant action
7. Update landing page copy and visual placements

## 14) Final lock statement

Front-Office owns prospect intake, onboarding state, invitation handling, Stripe readiness, tenant activation workflow, and account-level support.

Execution runtime owns storefronts, rides, payment execution, and live operational truth.

## 15) WordPress registration spec

### 15.1 CPT registration — `sd_prospect`

#### Registration intent

Pre-tenant intake and onboarding record.

#### Recommended `register_post_type()` args

```php
[
    'labels' => [
        'name' => 'Prospects',
        'singular_name' => 'Prospect',
        'menu_name' => 'Prospects',
        'add_new' => 'Add Prospect',
        'add_new_item' => 'Add New Prospect',
        'edit_item' => 'Edit Prospect',
        'new_item' => 'New Prospect',
        'view_item' => 'View Prospect',
        'search_items' => 'Search Prospects',
        'not_found' => 'No prospects found',
        'not_found_in_trash' => 'No prospects found in Trash',
    ],
    'public' => false,
    'show_ui' => true,
    'show_in_menu' => true,
    'menu_position' => 25,
    'menu_icon' => 'dashicons-id',
    'show_in_admin_bar' => false,
    'show_in_nav_menus' => false,
    'show_in_rest' => false,
    'has_archive' => false,
    'hierarchical' => false,
    'exclude_from_search' => true,
    'publicly_queryable' => false,
    'query_var' => false,
    'rewrite' => false,
    'supports' => ['title'],
    'capability_type' => 'post',
    'map_meta_cap' => true,
]
```

#### Title convention

Use a deterministic generated title for admin readability.

Recommended format:

* `Prospect — {Full Name} — {Phone}`
* fallback: `Prospect — {Email}`

Do not use the post title as canonical identity.

---

### 15.2 CPT registration — `sd_tenant`

#### Registration intent

Actual tenant entity created only after qualified promotion.

#### Recommended `register_post_type()` args

```php
[
    'labels' => [
        'name' => 'Tenants',
        'singular_name' => 'Tenant',
        'menu_name' => 'Tenants',
        'add_new' => 'Add Tenant',
        'add_new_item' => 'Add New Tenant',
        'edit_item' => 'Edit Tenant',
        'new_item' => 'New Tenant',
        'view_item' => 'View Tenant',
        'search_items' => 'Search Tenants',
        'not_found' => 'No tenants found',
        'not_found_in_trash' => 'No tenants found in Trash',
    ],
    'public' => false,
    'show_ui' => true,
    'show_in_menu' => true,
    'menu_position' => 26,
    'menu_icon' => 'dashicons-building',
    'show_in_admin_bar' => false,
    'show_in_nav_menus' => false,
    'show_in_rest' => false,
    'has_archive' => false,
    'hierarchical' => false,
    'exclude_from_search' => true,
    'publicly_queryable' => false,
    'query_var' => false,
    'rewrite' => false,
    'supports' => ['title'],
    'capability_type' => 'post',
    'map_meta_cap' => true,
]
```

#### Title convention

Recommended format:

* `Tenant — {Slug}`
* fallback: `Tenant — {Business Name}`

Again, title is for admin readability only.

---

### 15.3 Exact meta keys — `sd_prospect`

#### Identity and lifecycle

* `sd_prospect_id` — string, unique, immutable
* `sd_lifecycle_stage` — enum:

  * `prospect`
  * `invited_prospect`
  * `lead`
* `sd_source` — string
* `sd_created_at_gmt` — unix timestamp or MySQL datetime GMT
* `sd_updated_at_gmt` — unix timestamp or MySQL datetime GMT

#### Intake payload

* `sd_full_name` — string
* `sd_phone_raw` — original submitted string
* `sd_phone_normalized` — normalized canonical phone string
* `sd_email_raw` — original submitted string
* `sd_email_normalized` — lowercase trimmed email
* `sd_city` — string
* `sd_market` — string
* `sd_current_platform` — enum/string

#### Invitation fields

* `sd_invitation_code` — string nullable
* `sd_invitation_status` — enum:

  * `none`
  * `submitted`
  * `valid`
  * `invalid`
  * `manual_override`
* `sd_invited_by` — string nullable
* `sd_priority_lane` — `0|1`

#### Review and ownership

* `sd_review_status` — enum:

  * `new`
  * `reviewed`
  * `qualified`
  * `disqualified`
  * `hold`
* `sd_owner_user_id` — int nullable
* `sd_staff_notes` — long text
* `sd_last_staff_action_at_gmt` — datetime/timestamp nullable

#### Stripe transition

* `sd_stripe_onboarding_started_at_gmt` — datetime/timestamp nullable
* `sd_stripe_account_id` — string nullable
* `sd_stripe_onboarding_status` — enum:

  * `not_started`
  * `started`
  * `account_created`
  * `requirements_due`
  * `charges_enabled`
  * `failed`
* `sd_promoted_to_tenant_id` — string nullable
* `sd_promoted_to_tenant_post_id` — int nullable

#### Dedupe and audit

* `sd_dedupe_key_email` — normalized string nullable
* `sd_dedupe_key_phone` — normalized string nullable
* `sd_last_intake_channel` — string, e.g. `cf7_request_access`
* `sd_last_submission_at_gmt` — datetime/timestamp
* `sd_submission_count` — int
* `sd_last_submission_payload_json` — JSON string, sanitized/minimized

---

### 15.4 Exact meta keys — `sd_tenant`

#### Identity and status

* `sd_tenant_id` — string, unique, immutable
* `sd_slug` — string, unique
* `sd_domain` — string
* `sd_status` — enum:

  * `inactive`
  * `active`
* `sd_created_at_gmt` — datetime/timestamp
* `sd_updated_at_gmt` — datetime/timestamp

#### Origin linkage

* `sd_origin_prospect_id` — string
* `sd_origin_prospect_post_id` — int

#### Stripe

* `sd_connected_account_id` — string
* `sd_charges_enabled` — `0|1`
* `sd_payouts_enabled` — `0|1`
* `sd_stripe_status_snapshot_json` — JSON string

#### Storefront and activation

* `sd_storefront_status` — enum:

  * `not_live`
  * `live`
* `sd_activation_ready` — `0|1`
* `sd_activated_at_gmt` — datetime/timestamp nullable

#### Observe-only summary

* `sd_last_activity_at_gmt` — datetime/timestamp nullable
* `sd_support_flag` — `0|1`
* `sd_payment_flag` — `0|1`
* `sd_health_status` — enum:

  * `healthy`
  * `attention`
  * `critical`

---

### 15.5 Optional taxonomy verdict

Do not introduce taxonomies yet.

Reason:

* lifecycle is controlled and finite
* admin filtering is better handled by custom columns + meta queries
* taxonomies would add operational overhead without meaningful benefit

---

## 16) Admin columns and filters

### 16.1 `sd_prospect` admin columns

Recommended columns in this order:

1. Checkbox
2. Title
3. `Prospect ID`
4. `Lifecycle`
5. `Name`
6. `Phone`
7. `Email`
8. `Market`
9. `Platform`
10. `Invite`
11. `Review`
12. `Stripe`
13. `Owner`
14. `Updated`

#### Column value rules

* `Prospect ID` → `sd_prospect_id`
* `Lifecycle` → badge from `sd_lifecycle_stage`
* `Name` → `sd_full_name`
* `Phone` → `sd_phone_normalized`
* `Email` → `sd_email_normalized`
* `Market` → combine city/market if useful
* `Platform` → `sd_current_platform`
* `Invite` → compact badge from `sd_invitation_status`
* `Review` → `sd_review_status`
* `Stripe` → `sd_stripe_onboarding_status`
* `Owner` → mapped internal user
* `Updated` → `sd_updated_at_gmt`

### 16.2 `sd_tenant` admin columns

Recommended columns in this order:

1. Checkbox
2. Title
3. `Tenant ID`
4. `Slug`
5. `Domain`
6. `Status`
7. `Storefront`
8. `Stripe`
9. `Health`
10. `Last Activity`
11. `Updated`

#### Column value rules

* `Tenant ID` → `sd_tenant_id`
* `Slug` → `sd_slug`
* `Domain` → `sd_domain`
* `Status` → badge from `sd_status`
* `Storefront` → `sd_storefront_status`
* `Stripe` → summary of charges/payouts enabled
* `Health` → `sd_health_status`
* `Last Activity` → `sd_last_activity_at_gmt`
* `Updated` → `sd_updated_at_gmt`

---

### 16.3 `sd_prospect` admin filters

Add top-of-list filters for:

* lifecycle stage
* review status
* invitation status
* stripe onboarding status
* current platform
* owner user
* created date range if useful later

Recommended quick views:

* All
* New Prospects
* Invited Prospects
* Leads
* Ready for Tenant Creation
* On Hold

#### “Ready for Tenant Creation” logic

Meta conditions:

* `sd_lifecycle_stage = lead`
* `sd_stripe_onboarding_status = charges_enabled`
* `sd_promoted_to_tenant_post_id` empty
* `sd_review_status` in `reviewed|qualified`

### 16.4 `sd_tenant` admin filters

Add filters for:

* status
* storefront status
* health
* support flag
* payment flag

Recommended quick views:

* All
* Inactive
* Active
* Needs Attention
* Payment Flagged

---

## 17) CF7 hook flow

### 17.1 CF7 role

CF7 remains:

* form renderer
* validation layer
* spam gate
* public UX wrapper

CF7 is not:

* lifecycle authority
* dedupe authority
* queue truth
* onboarding truth

---

### 17.2 Form slug and intent

Replace `/apply/` with `/request-access/`.

Use a dedicated CF7 form for this flow only.
Recommended internal form identifier/name:

* `sd_request_access`

---

### 17.3 Submission event flow

Recommended sequence:

1. CF7 validates native required fields.
2. Custom hook intercepts validated submission.
3. Normalize payload:

   * trim strings
   * lowercase email
   * normalize phone
   * sanitize market/platform
4. Compute dedupe keys.
5. Evaluate invitation code.
6. Search for existing open `sd_prospect` by dedupe rules.
7. Either:

   * update existing prospect record, or
   * create new `sd_prospect`
8. Persist canonical meta first.
9. Increment audit fields (`sd_submission_count`, timestamps, payload snapshot).
10. Trigger notifications after successful write.
11. Redirect/render thank-you state based on invitation outcome.

---

### 17.4 Recommended hooks

Exact implementation can vary, but the logic should live on successful submission after validation.

Use CF7 hooks that let you:

* access sanitized form data
* avoid duplicate mailer-only behavior
* override response messaging if needed

Recommended architecture:

* a small custom plugin or mu-plugin owns the handler
* CF7 only forwards the event

---

## 18) Dedupe behavior

### 18.1 Dedupe goal

Prevent duplicate prospect records while preserving repeated attempts and updated intent.

### 18.2 Canonical dedupe priority

Primary key order:

1. normalized email
2. normalized phone
3. invitation code plus email if useful for diagnostics only

Do not dedupe on name alone.

### 18.3 Matching rules

#### Rule A — existing non-promoted prospect by email

If `sd_email_normalized` matches an existing `sd_prospect` and no tenant promotion exists:

* update existing prospect
* refresh latest fields
* increment submission count
* preserve original `sd_prospect_id`

#### Rule B — fallback by normalized phone

If no email match, search by `sd_phone_normalized`.
If matched:

* update existing record
* increment submission count

#### Rule C — promoted prospect already linked to tenant

If a matching prospect exists and `sd_promoted_to_tenant_post_id` is populated:

* do not overwrite tenant state
* optionally create staff alert
* optionally attach note or increment resubmission count

#### Rule D — active tenant exists for matching origin email/phone

If a tenant already exists for the same person/business:

* do not create a new prospect silently
* surface a controlled message or internal review flag

---

### 18.4 Field update rules during dedupe

When deduping into an existing prospect:

* always update:

  * `sd_updated_at_gmt`
  * `sd_last_submission_at_gmt`
  * `sd_submission_count`
  * `sd_last_submission_payload_json`
* update intake fields if new value is non-empty and cleaner
* do not downgrade lifecycle stage
* do not remove valid invitation status with later empty submissions
* do not clear stripe data once onboarding has started

#### Lifecycle precedence

Highest to lowest:

1. `lead`
2. `invited_prospect`
3. `prospect`

A new submission may advance a stage, but never downgrade it.

#### Invitation precedence

If prior status is `valid` or `manual_override`, never replace with `invalid` or `none` from a later submission.

---

## 19) Invitation code behavior

### 19.1 No code supplied

* `sd_invitation_status = none`
* `sd_priority_lane = 0`
* lifecycle remains `prospect`

### 19.2 Code supplied but not validated

* `sd_invitation_status = submitted`
* optionally remain `prospect` pending validation

### 19.3 Code valid

* `sd_invitation_status = valid`
* `sd_priority_lane = 1`
* lifecycle becomes `invited_prospect` unless already `lead`

### 19.4 Manual override

Staff may set:

* `sd_invitation_status = manual_override`
* `sd_priority_lane = 1`

---

## 20) Staff actions

### 20.1 Prospect row actions

Recommended custom actions:

* Mark Reviewed
* Mark Qualified
* Put On Hold
* Start Stripe
* Promote to Tenant

### 20.2 Promote to Tenant preconditions

Require:

* `sd_lifecycle_stage = lead`
* `sd_stripe_onboarding_status = charges_enabled`
* no existing `sd_promoted_to_tenant_post_id`

On action:

* create `sd_tenant`
* copy/link origin data
* set tenant inactive initially
* write back origin prospect linkage

---

## 21) Thank-you and response behavior

### 21.1 Standard response

Your request has been received.

Access is granted in stages.
You will be notified when your next onboarding step is available.

### 21.2 Invitation-priority response

Invitation recognized.

Your request has been prioritized for onboarding.

### 21.3 Duplicate existing-active response

Do not expose internal duplicate logic in detail.
Use calm language.

Recommended:
We already have your information on file.
If a next onboarding step is available, you will be notified.

---

## 22) Implementation notes

### 22.1 Performance

* use direct meta keys with predictable names
* avoid taxonomies for lifecycle
* keep list-table queries indexed as much as WordPress allows
* only store small sanitized payload snapshots

### 22.2 Security and privacy

* never expose prospect or tenant CPTs publicly
* never trust raw CF7 payload without normalization
* restrict staff actions by capability
* do not expose Stripe IDs on public pages

### 22.3 Upgrade path

This structure is intentionally compatible with later migration away from CF7.
If CF7 is replaced, the same lifecycle, meta keys, dedupe rules, and staff workflows should remain unchanged.
