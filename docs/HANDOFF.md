# HANDOFF — MHC Payroll

**Last updated:** 2026-08-05
**Active task:** [Multi-Clinic Setup](tasks/multi-clinic-setup.md)
**Branch:** `feature/multi-clinic-setup`

## Where things stand

Discovery and review are complete. Local code and production were verified byte-identical
across all 42 deployed files, so local is a trustworthy base. The environment inventory,
the root cause of the fresh-install schema gap, and the full branding-hardcode inventory
are recorded in the task file.

Phase 0 (code fixes and parameterization) is starting. Nothing has been deployed anywhere.

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

1. T1–T2: fresh-install schema and catalog seed in `inc/Base/Activate.php`
2. T3: remove the two unprotected AJAX endpoints
3. T4–T5: branding settings + replace the 11 hardcoded sites
4. T6–T7: `uninstall.php` and README
5. T8 + V1–V4: rebuild assets, then run the local validation gate

## Blocked on the user

Only Phase 2 items: the exact subdomain, the QuickBooks app strategy, and which WP admin
users the new site needs. Phase 0 and Phase 1 can complete without these answers.

## Knowledge base

`D:\Projects\kb` has no MHC notes yet. On task close, capture: the multi-instance WordPress
plugin pattern (activate/upgrade schema parity), and a decision note for D1–D4.
