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
| D6 | Both clinics share one Intuit app; separation is by realm, not by credentials | One app, one client_id/secret, a redirect URI per site. Each site stores its own realm_id and tokens. See P4 for the security consequence |
| D7 | A dedicated **classic** theme (`mhc-app`, repo `D:/Projects/mhc-theme`) replaces the database template override | Templates become files: versioned, reviewable, deployed with the code, and correct on a fresh install. Classic rather than block because block templates can still be overridden from the Site Editor into the database, reintroducing the same failure |

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

### Phase 0 — Code fixes and parameterization (local) — DONE (commit `0706bcb`)

- [x] **T1 — Fresh-install schema.** Bring `Activate::activate()` to the 1.4.10 shape so a
      clean activation produces the schema above. Keep `check_db_upgrade()` intact for the
      existing production install.
- [x] **T2 — Catalog seed.** Seed 5 roles (RBT, BCaBA, BCBA, LMHC, Other) and the 7 special
      rates with current amounts. Leave insurers empty (D3).
- [x] **T3 — Security.** `Ajax::ajax_delete_plugin_tables` drops 11 tables with a capability
      check but no nonce, reachable by GET on `admin-ajax.php` (CSRF → total payroll loss).
      `SeedController::ajax_seed_fake_data` inserts fake records, also without a nonce.
      Remove both from the production code path.
- [x] **T4 — Branding options.** New settings section: company name + logo (media uploader).
      Defaults reproduce today's values so production behavior is unchanged (D2).
- [x] **T5 — Replace hardcoded branding** at 11 sites:
      `PdfController.php` (4 name + 4 logo), `PayrollController.php` (3 logo),
      `email-template.html:44` (absolute URL to the production logo — must become a
      placeholder), `email-template.html:172` (copyright), `App.vue:9`, `TopMenu.vue:12`.
- [x] **T6 — `uninstall.php`.** Currently misses `mhc_patients`, `mhc_insurers`,
      `mhc_qb_checks`, `mhc_qb_queue` and every option, including the QuickBooks OAuth tokens.
- [x] **T7 — README.** Documents `[mhc]` / `[mhc__login]`; the real shortcodes are
      `[mhc_app]` / `[mhc_app_login]`. Whoever builds the new site from the README gets
      blank pages.
- [x] **T8 — Rebuild** `assets/dist` (`npm run build`) after the Vue changes.

### Phase 1 — Local validation (the gate that de-risks the whole operation)

V1, V2 and V4 executed against a scratch database (`mhc_fresh_test`, since dropped);
V3 against a dedicated local site. The shared dev sandbox data was never modified.
Phase 1 is complete.

- [x] **V1 — Clean-install test.** Deactivate → drop all `mhc_*` tables and `mhc_*` options →
      reactivate → diff resulting schema against the reference above. Must match exactly.
- [x] **V2 — Upgrade-path test.** Restore a 1.4.9-shaped DB → load a page → confirm
      `check_db_upgrade()` still migrates correctly (protects production).
- [x] **V3 — Regression on existing data.** Ran against a dedicated local site
      (`D:/xampp/htdocs/mhc-local`, DB `mhc_local`, plugin junctioned to the working tree)
      loaded with the dev dataset: 124 workers, 113 patients, 11 payrolls, 459 hour rows,
      21 extras, 24 checks. 32 checks pass: every list/detail AJAX endpoint, insurers CRUD,
      reports, and four mPDF outputs (worker slip 56 KB, summary by name 79 KB, summary by
      company 79 KB, all slips 326 KB). Both destructive endpoints return WP's `0`
      (unregistered). No new errors in `WP_DEBUG_LOG`; two pre-existing defects surfaced,
      see below.
- [x] **V4 — Branding test.** Change name and logo in settings, confirm the new values reach
      PDFs, emails and the Vue header; confirm defaults reproduce current output.

### Phase 2 — New site infrastructure (after local sign-off)

- [ ] Subdomain, SFTP user, MySQL database, Let's Encrypt certificate on the same VPS
- [ ] Clean WordPress + WP Mail SMTP with its own mailbox
- [ ] `git clone` → `composer install` → `npm install && npm run build`
      (`assets/dist/` and `vendor/` are gitignored and do not travel in the repo)
- [ ] Reproduce the front-end setup (see "Front-end setup" below). This is **not** a page:
      leave `show_on_front = posts` and override the block theme's `home` template
- [ ] Create the Login page only; its slug must be exactly `app-login` (hardcoded in the
      `template_redirect` handler)
- [ ] Writable `wp-content/uploads/mpdf`
- [ ] Configure branding, QuickBooks (new realm, new redirect URI, new account IDs),
      `mhc_week_start_day`; verify the `mhc_qb_process_queue_cron` schedule

## Pre-existing defects surfaced by V3

| # | Defect | Impact |
|---|---|---|
| P1 | Duplicate rows in `mhc_qb_checks` in the dev dataset: payroll 1 has worker 109 with **five** distinct QuickBooks check ids for the same $2,340.00, plus two workers duplicated twice. 28 of 30 rows have `qb_vendor_id` NULL, and MySQL does not enforce uniqueness across NULLs, so `uniq_payroll_vendor_worker` never fired. Same root cause as the fresh-install bug: that DB was created at 1.4.10, so the `MODIFY ... NOT NULL` in `check_db_upgrade()` never ran. | **Production verified clean** (read-only, 2026-08-05): 42 payrolls, 1,820 check rows, 0 with NULL/empty `qb_vendor_id`, 0 duplicate groups. Production was upgraded incrementally so the MODIFY did run and the unique index has been enforcing correctly. The defect is confined to the local dev sandbox, which was created fresh at 1.4.10. No production remediation needed. |
| P2 | `PdfController` passed NULL to `htmlspecialchars()` for nullable columns. 20 deprecations per summary PDF on PHP 8.3, fatal on PHP 9. | **Fixed**: `self::esc()` casts first; all 16 call sites routed through it. PDF output byte-identical. |
| P3 | `wpdb::prepare()` called with a placeholder-free query and empty params on unfiltered listings. One notice per request. | **Fixed** in `Role`, `SpecialRate`, `Insurer` (count + rows), `Worker` (count), `Patient` (count). `Worker::search` and `WorkerPatientRole` were false positives. |

| P4 | The OAuth callback never validates `state`. `Settings.php:459` generates `wp_create_nonce('mhc_qb_auth')`, `QuickBooksController.php:91` reads it into `$state` and never checks it. The handler runs on `init` with no capability check, so it is reachable unauthenticated. An attacker can start the authorization flow with `client_id` + the victim's registered `redirect_uri`, approve against their own QuickBooks company, and Intuit delivers the code straight to the victim site, which exchanges it and overwrites `mhc_qb_access_token`, `mhc_qb_refresh_token` and `mhc_qb_realm_id`. Result: the clinic's checks start being written into a company the attacker controls. | **Open.** Amplified by D6: one shared `client_id` means a valid code works against either clinic's callback. Fix is small - verify the nonce and require `manage_options` before storing tokens. Needs the user's go-ahead since it changes the connect flow. |

P2 and P3 fixed on request. `WP_DEBUG_LOG` is now empty across the whole V3 suite, down
from 20 deprecations and 5 notices. Both fixes are behavior-preserving, verified by
byte-identical PDF output and a full V1/V2/V3/V4 re-run.

## Front-end setup (corrected 2026-08-06)

The app is **not** served from a WordPress page. Production keeps
`show_on_front = posts` and overrides the block theme's `home` template through the Site
Editor. The whole template body is:

```
<!-- wp:shortcode -->
[mhc_app]
<!-- /wp:shortcode -->
```

No header part, no title, no footer, no wrapping group. That is what makes `#vwp-plugin` a
direct child of `.wp-site-blocks` and lets the SPA fill the viewport:

```
body.home.blog
  div.wp-site-blocks
    div#vwp-plugin          width = viewport
```

Creating a page and setting it as the static front page instead produces Twenty
Twenty-Five's stock `page` template - header, page title, footer, and `entry-content`
capped at 645px - which boxes the SPA into a narrow column. That is the mistake made when
`mhc-local` was first provisioned; it has been corrected there.

**This override lives in the database, not in the theme files.** It is a `wp_template`
post whose `post_name` is `home`, tied to the theme through the `wp_theme` taxonomy. The
file-level comparison that found local and production byte-identical covered only the
plugin, so it could never have surfaced this. Production also carries a custom `footer`
template part, unused by the app template but part of the same class of DB-only state.

Recipe for a new site (superseded by D7 - kept for reference, since this is what the
frozen production site does):

1. Leave `show_on_front = posts` (the WordPress default - do not create a front page)
2. Appearance -> Editor -> Templates -> Home, replace the entire content with a single
   Shortcode block containing `[mhc_app]`
3. Create one page only: Login, slug exactly `app-login`, content `[mhc_app_login]`

### With the `mhc-app` theme (D7, what the new site will do)

Steps 1 and 2 disappear - `index.php` in the theme is the app shell, and
`page-app-login.php` is the login screen:

1. `git clone <theme-repo> wp-content/themes/mhc-app`, activate it
2. Leave `show_on_front = posts`
3. Create one page: Login, slug exactly `app-login`, content `[mhc_app_login]`

Nothing in the Site Editor, and no `wp_template` rows in the database. The theme also
brands `wp-login.php`, which password resets go through.

## Open questions

- **Q1** — Exact subdomain for the new clinic. Blocks Phase 2 only.
- ~~**Q2** — QuickBooks app strategy.~~ Resolved: same Intuit app (D6).
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
