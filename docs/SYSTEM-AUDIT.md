# SoloDrive Kernel — System Audit

## Scope

Audited archive: `solodrive-kernel.zip`

Audit method:
- unpacked the archive
- enumerated runtime files excluding most of Stripe vendor internals
- syntax-checked PHP files
- inspected bootstrap, registries, tenant/lifecycle modules, Stripe modules, and ops surfaces
- compared the implementation against the uploaded doctrine and architecture notes

## Executive summary

The plugin is a credible early kernel for a tenant-first ride platform. Its strongest qualities are:
- a central canonical meta registry (`SD_Meta`)
- explicit tenant resolution and tenant scoping
- dedicated quote, ride, token, attempt, and Stripe modules
- doctrine-aligned separation of lead lifecycle, quote lifecycle, and ride execution lifecycle
- a `/trip/<token>` decision surface consistent with the architecture note

The biggest operational risks are packaging and drift, not the overall design. In particular, the Stripe packaging is incomplete for the boot path, documentation is absent from the zip, and a few registries/comments/files still reflect transitional evolution.

## Key findings

### 1) `/docs` directory is absent from the shipped zip

There is no native `/docs` tree in `solodrive-kernel.zip`.

Implication:
- the zip is shipping code without bundled architectural and operator documentation
- doctrine must currently be reconstructed from comments, PDFs, and module names

Severity: **Medium**

### 2) Stripe bootstrap is incomplete

`includes/module-loader.php` only attempts to load:
- `vendor/autoload.php`

But the zip ships:
- `vendor/stripe/stripe-php/init.php`
- Stripe library files

and does **not** ship `vendor/autoload.php`.

Impact:
- `class_exists('\\Stripe\\StripeClient')` and `class_exists('\\Stripe\\Webhook')` checks may fail even though the Stripe library files exist in the zip
- checkout, webhook handling, and capture can silently degrade into “library missing” behavior

Severity: **High**

Recommended fix:
- either ship a Composer autoloader, or
- explicitly `require_once SD_KERNEL_PATH . 'vendor/stripe/stripe-php/init.php';`
- add an admin/kernel health check that reports whether Stripe classes are actually loadable

### 3) Duplicate lifecycle-listener lineage

Two files exist:
- `114-stripe-return.php`
- `115-stripe-lifecycle-listener.php`

But `114-stripe-return.php` actually defines `SD_Module_StripeLifecycleListener`, not a return module.
`115-stripe-lifecycle-listener.php` also defines `SD_Module_StripeLifecycleListener`, but protects itself with:
- `if (class_exists('SD_Module_StripeLifecycleListener', false)) { return; }`

Impact:
- naming drift/confusion in the payload
- the earlier file can shadow the later one depending on load order
- architecture intent is harder to trust during maintenance

Current behavior in this payload:
- because modules load in filename order, `114` is loaded first
- `115` returns early because the class already exists
- effectively, the older implementation wins

Severity: **High**

Recommended fix:
- remove or rename the obsolete file
- keep only one canonical lifecycle-listener implementation
- add a CI rule forbidding duplicate class names across module files

### 4) Version reporting drift

The plugin header reports `Version: 0.1.0`, while `SD_Core::shortcode_health()` tries to show `SD_KERNEL_VERSION`, which is not defined in the payload.

Impact:
- health output reports `unknown`
- operational versioning is unreliable

Severity: **Medium**

Recommended fix:
- define `SD_KERNEL_VERSION` from plugin header or a single constant in bootstrap
- expose it in health/admin diagnostics

### 5) Tenant-scope registry contains post types not present in this payload

`SD_TenantScope::tenant_scoped_post_types()` includes:
- `sd_lead`
- `sd_capture`

But this zip does not include CPT modules for those records.

Impact:
- indicates historical/planned drift
- may confuse future contributors about what is actually canonical in v1

Severity: **Low to Medium**

Recommended fix:
- either add the missing CPTs, or
- remove them from the current registry until introduced

### 6) Transitional compatibility layer is still present

`includes/sd-compat-meta.php` maps legacy keys such as:
- `sog_trip_token`
- `sog_lead_status`
- `state`
- `sog_quote_status`

This is reasonable during migration, but it also confirms the kernel is still in a doctrine-hardening phase.

Impact:
- migration flexibility is good
- but it raises the risk that older code paths or imported data continue to depend on legacy semantics

Severity: **Low**

Recommended fix:
- keep the compatibility layer temporarily
- add explicit logging/metrics for legacy key reads
- define a removal milestone

### 7) Packaging contains macOS metadata artifacts

The archive includes `.DS_Store` files.

Impact:
- harmless at runtime
- signals packaging hygiene issues

Severity: **Low**

Recommended fix:
- strip OS metadata from release archives

## Doctrine alignment assessment

### Aligned

- Tenant-first model is reflected in resolver/access/scope modules.
- Canonical `sd_*` / `_sd_*` meta doctrine is strongly represented by `SD_Meta`.
- Lead, quote, and ride execution lifecycles are separated in code.
- Attempt-first payment correlation exists.
- `/trip/<token>` appears to be the primary public decision/status surface.
- Tenant records are first-class via tenant CPT module.

### Partially aligned / hardening needed

- Stripe merchant-of-record doctrine is structurally present, but runtime bootstrap risk threatens it.
- Packaging/documentation discipline is not yet at doctrine quality.
- Some registry entries and compatibility shims still show SoGa-Go → SoloDrive transition residue.

## Runtime inventory summary

Top-level custom runtime files found outside Stripe vendor payload:
- plugin bootstrap: 1
- core includes: 7
- modules: 37+
- JS assets: 1

Largest modules by size:
- `140-operator-trips.php`
- `035-ride-request-intake-cf7.php`
- `141-operator-trip-actions.php`
- `040-trip-surface.php`
- `142-operator-location.php`

Interpretation:
- most complexity is concentrated in the public intake/trip surface and the tenant operator surface
- that matches the project scope and architecture direction

## Remediation priority

### P0

- fix Stripe bootstrap so the shipped zip can actually load Stripe classes
- delete or consolidate duplicate lifecycle-listener modules

### P1

- add a real `/docs` directory to source control and release payloads
- unify version reporting and health diagnostics
- add release/build hygiene checks

### P2

- clean tenant-scope registry drift
- define sunset plan for legacy `sog_*` compatibility
- publish module ownership/contracts

## Audit verdict

**Verdict: architecturally promising, operationally not yet release-clean.**

The kernel is close to a coherent v1 foundation, but the current zip should be treated as a hardening candidate rather than a final release artifact.
