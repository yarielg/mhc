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
class Activate {

    public static function activate() {
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

        // 8) HoursEntries
        $sql[] = "CREATE TABLE {$pfx}mhc_hours_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            payroll_id BIGINT UNSIGNED NOT NULL,
            worker_patient_role_id BIGINT UNSIGNED NOT NULL,
            hours DECIMAL(7,2) NOT NULL DEFAULT 0.00,
            used_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_payroll (payroll_id),
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
                'cpt_code' => '97151',
                'unit_rate' => 457.20,
                'is_active' => 1,
            ]);
            $wpdb->insert("{$pfx}mhc_special_rates", [
                'code' => 'reassessment',
                'label' => 'Reassessment',
                'cpt_code' => '97151',
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
        }
    }
}
