<?php

namespace Mhc\Inc\Base;

defined('ABSPATH') || exit;

/**
 * Plugin activation: creates DB tables aligned with the final ERD.
 *
 * Notes:
 * - Uses dbDelta(), so please KEEP the exact SQL formatting (indexes & columns).
 * - We avoid FOREIGN KEY constraints because WordPress/dbDelta does not manage them well.
 *   Instead, we create proper indexes to keep performance and enforce integrity in code.
 */
class Activate
{

    public static function get_db_version()
    {
        return '1.4.6'; // increment on DB schema changes
    }

    public static function activate()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $pfx = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = [];

        // 1) Roles (RBT, BCaBA, BCBA, etc.)
        $sql[] = "CREATE TABLE {$pfx}mhc_roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(255) NOT NULL,
            billable TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY idx_active (is_active)
        ) {$charset_collate};";

        // 2) Workers
        $sql[] = "CREATE TABLE {$pfx}mhc_workers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            supervisor_id BIGINT UNSIGNED NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL DEFAULT '',
            company VARCHAR(100) NOT NULL DEFAULT '',
            qb_vendor_id VARCHAR(100) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            start_date DATE NULL,
            end_date DATE NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_supervisor (supervisor_id),
            KEY idx_active (is_active)
        ) {$charset_collate};";

        // 3) Patients
        $sql[] = "CREATE TABLE {$pfx}mhc_patients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,            
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            record_number VARCHAR(80) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            start_date DATE NULL,
            end_date DATE NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_active (is_active)
        ) {$charset_collate};";

        // 4) WorkerRole (general rate per role with history)
        $sql[] = "CREATE TABLE {$pfx}mhc_worker_roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            worker_id BIGINT UNSIGNED NOT NULL,
            role_id BIGINT UNSIGNED NOT NULL,
            general_rate DECIMAL(10,2) NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_worker (worker_id),
            KEY idx_role (role_id),
            KEY idx_worker_role_dates (worker_id, role_id, start_date)
        ) {$charset_collate};";

        // 5) WorkerPatientRole (assignment with optional specific rate & history)
        $sql[] = "CREATE TABLE {$pfx}mhc_worker_patient_roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            worker_id BIGINT UNSIGNED NOT NULL,
            patient_id BIGINT UNSIGNED NOT NULL,
            role_id BIGINT UNSIGNED NOT NULL,
            rate DECIMAL(10,2) NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_worker (worker_id),
            KEY idx_patient (patient_id),
            KEY idx_role (role_id),
            KEY idx_worker_patient_role_dates (worker_id, patient_id, role_id, start_date)
        ) {$charset_collate};";

        // 7) Payrolls
        $sql[] = "CREATE TABLE {$pfx}mhc_payrolls (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'draft',
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_period (start_date, end_date),
            KEY idx_status (status)
        ) {$charset_collate};";

        // Segments (semanas o fracciones de semana dentro de un payroll)
        $sql[] = "CREATE TABLE {$pfx}mhc_payroll_segments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payroll_id BIGINT UNSIGNED NOT NULL,
            segment_start DATE NOT NULL,
            segment_end DATE NOT NULL,
            notes TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX (payroll_id)
        ) $charset_collate;";

        // 8) HoursEntries
        $sql[] = "CREATE TABLE {$pfx}mhc_hours_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            segment_id BIGINT UNSIGNED NOT NULL,
            worker_patient_role_id BIGINT UNSIGNED NOT NULL,
            hours DECIMAL(7,2) NOT NULL DEFAULT 0.00,
            used_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_segment (segment_id),
            KEY idx_wpr (worker_patient_role_id)
        ) {$charset_collate};";

        // 9) ExtraPayments (one row per extra concept)
        $sql[] = "CREATE TABLE {$pfx}mhc_extra_payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payroll_id BIGINT UNSIGNED NOT NULL,
            worker_id BIGINT UNSIGNED NOT NULL, -- who gets paid
            supervised_worker_id BIGINT UNSIGNED NULL, -- only for supervision
            patient_id BIGINT UNSIGNED NULL, -- optional (assessment/pending)
            special_rate_id BIGINT UNSIGNED NOT NULL, -- referencia a mhc_special_rates.id
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            hours DECIMAL(8,2) NULL,             -- NUEVO
            hours_rate DECIMAL(10,2) NULL,       -- NUEVO
            notes VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_payroll (payroll_id),
            KEY idx_worker (worker_id),
            KEY idx_patient (patient_id),
            KEY idx_special_rate (special_rate_id)
        ) {$charset_collate};";

        // 10) Special rates (lookup for standard amounts like assessments/supervision)
        $sql[] = "CREATE TABLE {$pfx}mhc_special_rates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL,
            label VARCHAR(255) NOT NULL,
            cpt_code VARCHAR(50) NULL,
            unit_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,            
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code (code),
            KEY idx_active (is_active)
        ) {$charset_collate};";

        // PatientPayrolls
        $sql[] = "CREATE TABLE {$pfx}mhc_patient_payrolls (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            patient_id BIGINT UNSIGNED NOT NULL,
            payroll_id BIGINT UNSIGNED NOT NULL,
            is_processed TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_patient (patient_id),
            KEY idx_payroll (payroll_id)
        ) {$charset_collate};";


        // Run dbDelta for each statement to allow incremental upgrades.
        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        // Seed default roles if missing
        $existing_roles = $wpdb->get_col("SELECT code FROM {$pfx}mhc_roles");
        if (empty($existing_roles)) {
            $wpdb->insert("{$pfx}mhc_roles", [
                'code' => 'RBT',
                'name' => 'Registered Behavior Technician',
                'billable' => 1,
                'is_active' => 1,
            ]);
            $wpdb->insert("{$pfx}mhc_roles", [
                'code' => 'BCaBA',
                'name' => 'Board Certified Assistant Behavior Analyst',
                'billable' => 1,
                'is_active' => 1,
            ]);
            $wpdb->insert("{$pfx}mhc_roles", [
                'code' => 'BCBA',
                'name' => 'Board Certified Behavior Analyst',
                'billable' => 1,
                'is_active' => 1,
            ]);
        }

        // Seed special rates (amounts validated in workshop)
        $existing_sr = $wpdb->get_col("SELECT code FROM {$pfx}mhc_special_rates");
        if (empty($existing_sr)) {
            $wpdb->insert("{$pfx}mhc_special_rates", [
                'code' => 'initial_assessment',
                'label' => 'Initial Assessment',
                'cpt_code' => '97151 (24U)',
                'unit_rate' => 457.20,
                'is_active' => 1,
            ]);
            $wpdb->insert("{$pfx}mhc_special_rates", [
                'code' => 'reassessment',
                'label' => 'Reassessment',
                'cpt_code' => '97151TS (18U)',
                'unit_rate' => 342.90,
                'is_active' => 1,
            ]);
            $wpdb->insert("{$pfx}mhc_special_rates", [
                'code' => 'supervision',
                'label' => 'Supervision (per supervisee)',
                'unit_rate' => 50.00,
                'is_active' => 1,
            ]);
            $wpdb->insert("{$pfx}mhc_special_rates", [
                'code' => 'pending_positive',
                'label' => 'Pending Adjustment (+)',
                'unit_rate' => 0.00,
                'is_active' => 1,
            ]);
            $wpdb->insert("{$pfx}mhc_special_rates", [
                'code' => 'pending_negative',
                'label' => 'Pending Adjustment (-)',
                'unit_rate' => 0.00,
                'is_active' => 1,
            ]);
            // New in v1.3.0 to support hours-based extras
            $wpdb->insert("{$pfx}mhc_special_rates", [
                'code' => 'pending_pos_hourly',
                'label' => 'Pending Adjustment (+) (hourly)',
                'unit_rate' => 0.00,
                'is_active' => 1,
            ]);
            $wpdb->insert("{$pfx}mhc_special_rates", [
                'code' => 'pending_neg_hourly',
                'label' => 'Pending Adjustment (-) (hourly)',
                'unit_rate' => 0.00,
                'is_active' => 1,
            ]);
        }

        update_option('mhc_db_version', self::get_db_version());
        // Ensure QbQueue tables exist immediately on activation
        if (class_exists('\Mhc\Inc\Services\QbQueue')) {
            \Mhc\Inc\Services\QbQueue::maybe_create_tables();
        }

        // Register custom cron schedule (in case it's not registered yet) so scheduling works during activation
        if (!function_exists('wp_get_schedules') || !wp_get_schedules()) {
            // noop - but keep the normal flow
        }
        add_filter('cron_schedules', function($schedules){
            if (!isset($schedules['five_minutes'])) {
                $schedules['five_minutes'] = ['interval' => 300, 'display' => 'Every Five Minutes'];
            }
            return $schedules;
        });

        // Schedule queue processor cron if not scheduled
        if (!wp_next_scheduled('mhc_qb_process_queue_cron')) {
            wp_schedule_event(time() + 60, 'five_minutes', 'mhc_qb_process_queue_cron');
        }
    }

    public static function check_db_upgrade()
    {
        global $wpdb;

        $installed_ver = get_option('mhc_db_version');
        $current_ver   = self::get_db_version();

        if ($installed_ver !== $current_ver) {
            $pfx = $wpdb->prefix;
            // Agregar columnas solo si no existen
            // 1. Extra payments: hours y hours_rate
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$pfx}mhc_extra_payments", 0);
            if (!in_array('hours', $columns)) {
                $wpdb->query("ALTER TABLE {$pfx}mhc_extra_payments ADD COLUMN hours DECIMAL(8,2) NULL AFTER amount");
            }
            if (!in_array('hours_rate', $columns)) {
                $wpdb->query("ALTER TABLE {$pfx}mhc_extra_payments ADD COLUMN hours_rate DECIMAL(10,2) NULL AFTER hours");
            }

            // 2. WorkerPatientRoles: deleted_at para soft delete
            $columns_wpr = $wpdb->get_col("SHOW COLUMNS FROM {$pfx}mhc_worker_patient_roles", 0);
            if (!in_array('deleted_at', $columns_wpr)) {
                $wpdb->query("ALTER TABLE {$pfx}mhc_worker_patient_roles ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER updated_at");
            }

            //3. Hours_entries: delete_at para soft delete (v1.4.2)
            $columns_he = $wpdb->get_col("SHOW COLUMNS FROM {$pfx}mhc_hours_entries", 0);
            if (!in_array('deleted_at', $columns_he)) {
                $wpdb->query("ALTER TABLE {$pfx}mhc_hours_entries ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL AFTER updated_at");
            }

            // 4. Vendor ID for QuickBooks integration
            $columns_w = $wpdb->get_col("SHOW COLUMNS FROM {$pfx}mhc_workers", 0);
            if (!in_array('qb_vendor_id', $columns_w)) {
                $wpdb->query("ALTER TABLE {$pfx}mhc_workers ADD COLUMN qb_vendor_id VARCHAR(100) NULL AFTER company");
            }

            // 5. Ensure QbQueue tables are up to date
            if (class_exists('\Mhc\Inc\Services\QbQueue')) {
                \Mhc\Inc\Services\QbQueue::maybe_create_tables();
            }

            // 6. Add worker_patient_role_id and check_number to wp_mhc_qb_checks (v1.4.5)
            $qchecks_table = $wpdb->prefix . 'mhc_qb_checks';
            $cols_checks = $wpdb->get_col("SHOW COLUMNS FROM {$qchecks_table}", 0);
            if (!in_array('worker_patient_role_id', $cols_checks)) {
                $wpdb->query("ALTER TABLE {$qchecks_table} ADD COLUMN worker_patient_role_id BIGINT UNSIGNED NULL AFTER payroll_id");
            }
            if (!in_array('check_number', $cols_checks)) {
                $wpdb->query("ALTER TABLE {$qchecks_table} ADD COLUMN check_number VARCHAR(100) NULL AFTER worker_patient_role_id");
            }

            //7. add payroll_print_date to wp_mhc_payrolls (v1.4.6)
            $payrolls_table = $wpdb->prefix . 'mhc_payrolls';
            $cols_payrolls = $wpdb->get_col("SHOW COLUMNS FROM {$payrolls_table}", 0);
            if (!in_array('payroll_print_date', $cols_payrolls)) {
                $wpdb->query("ALTER TABLE {$payrolls_table} ADD COLUMN payroll_print_date DATETIME NULL AFTER end_date");
            }

            update_option('mhc_db_version', $current_ver);
        }
    }
}
