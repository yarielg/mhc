# Task: Multi-Clinic Setup (MHC Payroll)

**Status:** In progress
**Branch:** `feature/multi-clinic-setup`
**Started:** 2026-08-05
**Owner skill:** `wordpress-plugin-blueprint` (support: `security-reviewer`, `testing-strategist`, `documentation-guardian`)

## Goal

Make the MHC Payroll plugin installable as a second, independent instance for a new
clinic on a subdomain of `agencyofmentalhealth.com`, without touching the existing
production site at `app.agencyofmentalhealth.com`.

## Confirmed facts

- Local plugin code and production are byte-identical across all 42 deployed files
  (verified 2026-08-05 by SHA-256 with CRLF/LF normalization). No drift.
- Production stack: WP 7.0.2, PHP 8.3.30, MySQL 8.0.41, table prefix `wp_hgeyb3_`,
  DreamHost VPS Business `vps66366`, SFTP user `aomhs`.
- Active theme is stock Twenty Twenty-Five. The inactive `mhc_theme` is an empty stub
  (`style.css` 0 bytes, `functions.php` 7 bytes). No theme work is required.
- The whole product lives in the plugin, surfaced through two pages: `Home`
  (`[mhc_app]`) and `Login` (slug `app-login`, `[mhc_app_login]`).
- Local dev DB (`wordpress`, prefix `wp_`) is at `mhc_db_version = 1.4.10` and carries
  the complete target schema. It is the reference for the `activate()` fix.
- QuickBooks credentials, process key and `mhc_week_start_day` already live in
  `wp_options` — they are per-site and portable by design.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Branding configured through a wp-admin settings page | Each site self-configures; a third clinic costs no code |
| D2 | Single codebase, backward compatible | Branding defaults reproduce current behavior; schema fix only affects fresh installs, so the same code can run both sites |
| D3 | Fresh installs seed 5 roles + 7 special rates, insurers left empty | Matches production catalogs; insurers vary by contract |
| D4 | Separate WordPress installation for the new clinic (not multisite, not multi-tenant) | PHI isolation, separate QuickBooks realm, independent backups |
| D5 | Work on `feature/multi-clinic-setup`, merge to `master` after local validation | Production only changes on an explicit SFTP deploy, which is out of scope |

## Root cause: incomplete fresh-install schema

`Activate::activate()` builds the tables and then sets
`update_option('mhc_db_version', '1.4.10')`. Several columns and one whole table only
exist inside `check_db_upgrade()`, which runs solely when the stored version differs
from the current one. On a fresh install the option is already `1.4.10`, so
`check_db_upgrade()` never runs and the following are never created:

| Missing object | Consumed by |
|---|---|
| table `mhc_insurers` | `Insurer.php`, `InsurersController.php`, `Patient.php` |
| `mhc_patients.insurer_id`, `.insurer_number` | `Patient.php` |
| `mhc_payrolls.payroll_print_date` | `Payroll.php`, `PayrollController.php`, `QuickBooksController.php` |
| `mhc_qb_checks.worker_patient_role_id`, `.check_number` | `QuickBooksController.php:263`, `PdfController.php`, `HoursEntry.php` |
| `mhc_hours_entries.deleted_at` | created by the migration but unused — cosmetic drift only |

## Target schema reference (from local DB, verified)

```
mhc_insurers            id, name VARCHAR(191) NOT NULL, is_active TINYINT(1) DEFAULT 1,
                        created_at, updated_at
                        PRIMARY KEY (id), UNIQUE KEY uniq_name (name), KEY idx_active (is_active)
mhc_patients            + insurer_id BIGINT UNSIGNED NULL   (after record_number)
                        + insurer_number VARCHAR(100) NULL  (after insurer_id)
mhc_payrolls            + payroll_print_date DATETIME NULL  (after end_date)
mhc_hours_entries       + deleted_at DATETIME NULL          (after updated_at)
mhc_qb_checks           id, payroll_id, worker_patient_role_id BIGINT UNSIGNED NULL,
                        check_number VARCHAR(100) NULL, worker_id BIGINT UNSIGNED NOT NULL,
                        qb_vendor_id VARCHAR(191), qb_check_id VARCHAR(191) NOT NULL,
                        amount DECIMAL(12,2) NOT NULL DEFAULT 0, created_at
                        UNIQUE KEY uniq_payroll_vendor_worker (payroll_id, qb_vendor_id, worker_id)
                        KEY idx_payroll (payroll_id), KEY idx_worker (worker_id)
```

## Work breakdown

### Phase 0 — Code fixes and parameterization (local)

- [ ] **T1 — Fresh-install schema.** Bring `Activate::activate()` to the 1.4.10 shape so a
      clean activation produces the schema above. Keep `check_db_upgrade()` intact for the
      existing production install.
- [ ] **T2 — Catalog seed.** Seed 5 roles (RBT, BCaBA, BCBA, LMHC, Other) and the 7 special
      rates with current amounts. Leave insurers empty (D3).
- [ ] **T3 — Security.** `Ajax::ajax_delete_plugin_tables` drops 11 tables with a capability
      check but no nonce, reachable by GET on `admin-ajax.php` (CSRF → total payroll loss).
      `SeedController::ajax_seed_fake_data` inserts fake records, also without a nonce.
      Remove both from the production code path.
- [ ] **T4 — Branding options.** New settings section: company name + logo (media uploader).
      Defaults reproduce today's values so production behavior is unchanged (D2).
- [ ] **T5 — Replace hardcoded branding** at 11 sites:
      `PdfController.php` (4 name + 4 logo), `PayrollController.php` (3 logo),
      `email-template.html:44` (absolute URL to the production logo — must become a
      placeholder), `email-template.html:172` (copyright), `App.vue:9`, `TopMenu.vue:12`.
- [ ] **T6 — `uninstall.php`.** Currently misses `mhc_patients`, `mhc_insurers`,
      `mhc_qb_checks`, `mhc_qb_queue` and every option, including the QuickBooks OAuth tokens.
- [ ] **T7 — README.** Documents `[mhc]` / `[mhc__login]`; the real shortcodes are
      `[mhc_app]` / `[mhc_app_login]`. Whoever builds the new site from the README gets
      blank pages.
- [ ] **T8 — Rebuild** `assets/dist` (`npm run build`) after the Vue changes.

### Phase 1 — Local validation (the gate that de-risks the whole operation)

- [ ] **V1 — Clean-install test.** Deactivate → drop all `mhc_*` tables and `mhc_*` options →
      reactivate → diff resulting schema against the reference above. Must match exactly.
- [ ] **V2 — Upgrade-path test.** Restore a 1.4.9-shaped DB → load a page → confirm
      `check_db_upgrade()` still migrates correctly (protects production).
- [ ] **V3 — Regression on existing data.** With the current local dataset, exercise
      payroll detail, PDF slip, PDF summary, email send, insurers CRUD, QuickBooks check
      listing. `WP_DEBUG_LOG` must stay clean.
- [ ] **V4 — Branding test.** Change name and logo in settings, confirm the new values reach
      PDFs, emails and the Vue header; confirm defaults reproduce current output.

### Phase 2 — New site infrastructure (after local sign-off)

- [ ] Subdomain, SFTP user, MySQL database, Let's Encrypt certificate on the same VPS
- [ ] Clean WordPress + WP Mail SMTP with its own mailbox
- [ ] `git clone` → `composer install` → `npm install && npm run build`
      (`assets/dist/` and `vendor/` are gitignored and do not travel in the repo)
- [ ] Create both pages; the login page slug must be exactly `app-login` (hardcoded in the
      `template_redirect` handler)
- [ ] Writable `wp-content/uploads/mpdf`
- [ ] Configure branding, QuickBooks (new realm, new redirect URI, new account IDs),
      `mhc_week_start_day`; verify the `mhc_qb_process_queue_cron` schedule

## Open questions

- **Q1** — Exact subdomain for the new clinic. Blocks Phase 2 only.
- **Q2** — QuickBooks: add the new `/qb/callback` redirect URI to the existing Intuit app,
  or create a separate app? Blocks Phase 2 only.
- **Q3** — Who administers the new site (WP admin users to create)?

## Risks

| Risk | Mitigation |
|---|---|
| A change intended for the new site alters production behavior | D2 backward-compatible defaults + V2/V3 regression tests; production is not redeployed |
| `dbDelta` is whitespace- and format-sensitive | V1 diffs the real resulting schema rather than trusting the SQL by eye |
| Vue rebuild produces a bundle that differs from the deployed one for unrelated reasons | Bundle is only shipped to the new site; production keeps its current `assets/dist` |
| Local QuickBooks sandbox credentials were exposed in session output | Rotate the sandbox app credentials in the Intuit portal when the task closes |

## Out of scope

- Any change to `app.agencyofmentalhealth.com` (files, database, settings)
- Multisite or in-plugin multi-tenancy
- Migrating clinical or payroll data between clinics
