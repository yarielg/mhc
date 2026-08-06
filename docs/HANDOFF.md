# HANDOFF — MHC Payroll

**Last updated:** 2026-08-05
**Active task:** [Multi-Clinic Setup](tasks/multi-clinic-setup.md)
**Branch:** `feature/multi-clinic-setup`

## Where things stand

Discovery and review are complete. Local code and production were verified byte-identical
across all 42 deployed files, so local is a trustworthy base. The environment inventory,
the root cause of the fresh-install schema gap, and the full branding-hardcode inventory
are recorded in the task file.

Phase 0 (code fixes and parameterization) is complete and committed. Phase 1 validation
(V1 clean install, V2 upgrade path, V3 functional regression, V4 branding) all pass.
Nothing has been deployed anywhere.

A dedicated local site exists for MHC work and should be used instead of the shared
sandbox at `D:/xampp/htdocs/wordpress` (which hangs on load and would be broken by the
plugin's global anonymous redirect):

    URL   http://localhost/mhc-local/
    admin http://localhost/mhc-local/wp-admin/  (mhcadmin / mhc-local-dev-password)
    DB    mhc_local
    plugin  wp-content/plugins/mhc is an NTFS junction to the real working tree, so
            edits are picked up with no copy step

It was provisioned by the same sequence Phase 2 will follow on the subdomain: install WP,
activate the plugin, create Home (`[mhc_app]`) and Login (slug `app-login`,
`[mhc_app_login]`), write `.htaccess`, create `uploads/mpdf`.

## Ground rules for this task

1. **Production is frozen.** `app.agencyofmentalhealth.com` gets no file, database or
   settings changes. It is only ever read.
2. **One codebase, backward compatible.** Branding defaults must reproduce today's output
   exactly; the schema fix must only affect fresh installs. The same code has to be safe on
   both sites.
3. **Everything is validated locally first** against the XAMPP install
   (`D:\xampp\htdocs\wordpress`, DB `wordpress`, prefix `wp_`, `WP_DEBUG_LOG` on).

## Key context an agent needs before touching code

- The bug that motivates the task: `Activate::activate()` sets `mhc_db_version` to the
  current version, so `check_db_upgrade()` never runs on a fresh install and the schema
  ends up missing `mhc_insurers`, `payroll_print_date`, `check_number`,
  `worker_patient_role_id` and the patient insurer columns. Target schema is in the task file.
- `assets/dist/` and `vendor/` are gitignored. Any deploy needs `composer install` and
  `npm run build`; the repo alone is not deployable.
- The login page slug `app-login` is hardcoded in `Settings::redirect_users()`. Any new
  site must use exactly that slug or every anonymous request enters a redirect loop.
- Real shortcodes are `[mhc_app]` and `[mhc_app_login]`. The README is wrong (task T7).

## Next actions

1. Merge `feature/multi-clinic-setup` into `master`. Phase 0 and Phase 1 are complete and
   P1/P2/P3 are all closed
2. Phase 2: provision the subdomain, following the same sequence used for `mhc-local`

## Blocked on the user

The exact subdomain, the QuickBooks app strategy, and which WP admin users the new site
needs — all Phase 2.

## Knowledge base

`D:\Projects\kb` has no MHC notes yet. On task close, capture: the multi-instance WordPress
plugin pattern (activate/upgrade schema parity), and a decision note for D1–D4.
