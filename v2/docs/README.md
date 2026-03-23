# SoloDrive Kernel — Reproduced `/docs`

This reconstructed `/docs` set was generated from an audit of `solodrive-kernel.zip`, cross-checked against the governing doctrine and architecture notes in the uploaded SoloDrive PDFs and project scope.

## Source inputs

- `solodrive-kernel.zip` — audited plugin payload
- `SOLODRIVE.pro System Source of Truth v1.1`
- `SOLODRIVE.PRO Planned System Architecture v1.1`
- `Project-Scope.txt`

## What is in this docs set

- `SYSTEM-AUDIT.md` — audit findings, risks, and remediation priorities
- `ARCHITECTURE.md` — reconstructed runtime architecture and request/payment flow
- `MODULE-INVENTORY.md` — module-by-module inventory of the kernel payload
- `DOC-GAPS.md` — what was missing from the zip and what had to be inferred

## High-level conclusion

The kernel payload has a strong doctrinal spine: tenant-first scoping, canonical `sd_*` meta, a trip token surface, quote/ride lifecycle services, and attempt-first Stripe correlation. However, the zip does **not** contain an original `/docs` directory, so this set is a reconstruction. The most important audit risks are:

1. Stripe library bootstrap is incomplete (`vendor/autoload.php` is expected but not shipped).
2. Two lifecycle-listener modules exist (`114` and `115`), with `115` shadowing `114` because of a class guard.
3. Health/version reporting is inconsistent (`Plugin Version: 0.1.0`, but `SD_KERNEL_VERSION` is undefined).
4. Tenant-scope registry includes post types not present in this payload (`sd_lead`, `sd_capture`).

## Recommended next step

Treat this reconstructed `/docs` as a baseline, then replace it with a first-party `/docs` folder committed alongside the plugin so doctrine, architecture, operational flow, and module contracts travel with the code.
