<?php
namespace Mhc\Inc\Base;

class Activate {
    public static function activate() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $pfx = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1) Roles (ARBT/RBT, BCaBA, BCBA, etc.)
        /*$sql[] = "CREATE TABLE {$pfx}mhc_roles (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(50) NOT NULL,
            label VARCHAR(100) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug)
        ) $charset_collate;";*/

        // 2) Workers (link to wp_users)
        /*$sql[] = "CREATE TABLE {$pfx}mhc_workers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            name VARCHAR(150) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id)
        ) $charset_collate;";*/

        // 3) Patients
        $sql[] = "CREATE TABLE {$pfx}mhc_patients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        // 4) Default assignment of workers to patients (+ optional patient-specific rate override)
        /*$sql[] = "CREATE TABLE {$pfx}mhc_patient_workers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            patient_id BIGINT UNSIGNED NOT NULL,
            worker_id  BIGINT UNSIGNED NOT NULL,
            role_id    BIGINT UNSIGNED NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 1,
            override_rate DECIMAL(10,2) NULL,
            is_active  TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_patient_worker_role (patient_id, worker_id, role_id),
            KEY patient_id (patient_id),
            KEY worker_id (worker_id),
            KEY role_id (role_id)
        ) $charset_collate;";*/

        // 5) Default hourly rates per worker per role
        /*$sql[] = "CREATE TABLE {$pfx}mhc_worker_role_rates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            worker_id BIGINT UNSIGNED NOT NULL,
            role_id   BIGINT UNSIGNED NOT NULL,
            hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_worker_role (worker_id, role_id),
            KEY worker_id (worker_id),
            KEY role_id (role_id)
        ) $charset_collate;";*/

        // 6) Payroll periods (bi-weekly, etc.)
        /*$sql[] = "CREATE TABLE {$pfx}mhc_payroll_periods (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            start_date DATE NOT NULL,
            end_date   DATE NOT NULL,
            status ENUM('draft','open','locked','paid') NOT NULL DEFAULT 'draft',
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_range (start_date, end_date),
            KEY status (status)
        ) $charset_collate;";*/

        // 7) Work entries (hours per patient/worker/role within a period)
        /*$sql[] = "CREATE TABLE {$pfx}mhc_payroll_entries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period_id  BIGINT UNSIGNED NOT NULL,
            patient_id BIGINT UNSIGNED NOT NULL,
            worker_id  BIGINT UNSIGNED NOT NULL,
            role_id    BIGINT UNSIGNED NOT NULL,
            hours DECIMAL(6,2) NOT NULL DEFAULT 0.00,
            hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            notes VARCHAR(255) NULL,
            locked TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_line (period_id, patient_id, worker_id, role_id),
            KEY period_id (period_id),
            KEY worker_id (worker_id),
            KEY patient_id (patient_id),
            KEY role_id (role_id)
        ) $charset_collate;";*/

        // 8) Adjustments (pending +/-; assessments; supervision; custom)
        /*$sql[] = "CREATE TABLE {$pfx}mhc_adjustments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period_id  BIGINT UNSIGNED NOT NULL,
            worker_id  BIGINT UNSIGNED NOT NULL,
            patient_id BIGINT UNSIGNED NULL,
            adj_type ENUM('pending','initial_assessment','reassessment','supervision','custom') NOT NULL,
            description VARCHAR(255) NULL,
            units DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            unit_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- can be negative for deductions
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY period_id (period_id),
            KEY worker_id (worker_id),
            KEY patient_id (patient_id),
            KEY adj_type (adj_type)
        ) $charset_collate;";*/

        // 9) Fixed rates for special items (optional config table)
        /*$sql[] = "CREATE TABLE {$pfx}mhc_special_rates (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code VARCHAR(50) NOT NULL, -- e.g., 'initial_assessment', 'reassessment', 'supervision'
            label VARCHAR(100) NOT NULL,
            unit_rate DECIMAL(10,2) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY code (code)
        ) $charset_collate;";*/

        // 10) Simple settings key/value (if you want to keep it inside the plugin scope)
        /*$sql[] = "CREATE TABLE {$pfx}mhc_settings (
            option_key VARCHAR(191) NOT NULL,
            option_value LONGTEXT NULL,
            autoload TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (option_key)
        ) $charset_collate;";*/

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        // Seed the standard roles and special rates if empty
       // self::seed_defaults();
        add_option('mhc_db_version', '1.0.0');
    }

    protected static function seed_defaults() {
        global $wpdb;
        $pfx = $wpdb->prefix;

        // Roles
        $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$pfx}mhc_roles");
        if (!$existing) {
            $wpdb->insert("{$pfx}mhc_roles", ['slug' => 'rbt',   'label' => 'RBT / ABT', 'is_active' => 1]);
            $wpdb->insert("{$pfx}mhc_roles", ['slug' => 'bcaba', 'label' => 'BCaBA',     'is_active' => 1]);
            $wpdb->insert("{$pfx}mhc_roles", ['slug' => 'bcba',  'label' => 'BCBA',      'is_active' => 1]);
        }

        // Special (fixed) rates — tweak to your actual amounts
        $existing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$pfx}mhc_special_rates");
        if (!$existing) {
            $wpdb->insert("{$pfx}mhc_special_rates", [
                'code' => 'initial_assessment',
                'label' => 'Initial Assessment',
                'unit_rate' => 457.20,
                'is_active' => 1,
            ]);
            $wpdb->insert("{$pfx}mhc_special_rates", [
                'code' => 'reassessment',
                'label' => 'Reassessment',
                'unit_rate' => 342.90,
                'is_active' => 1,
            ]);
            $wpdb->insert("{$pfx}mhc_special_rates", [
                'code' => 'supervision',
                'label' => 'Supervision (per supervisee)',
                'unit_rate' => 150.00,
                'is_active' => 1,
            ]);
        }
    }
}