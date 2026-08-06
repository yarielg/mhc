<?php
/*
* Trigger this file  on Plugin Uninstall
*
* @package yariko
*/

if( ! defined('WP_UNINSTALL_PLUGIN') ){
    die;
}

// Elimina las tablas creadas por el plugin
if (class_exists('wpdb')) {
    global $wpdb;
    $tables = [
        $wpdb->prefix . 'mhc_workers',
        $wpdb->prefix . 'mhc_patients',
        $wpdb->prefix . 'mhc_insurers',
        $wpdb->prefix . 'mhc_payrolls',
        $wpdb->prefix . 'mhc_hours_entries',
        $wpdb->prefix . 'mhc_extra_payments',
        $wpdb->prefix . 'mhc_special_rates',
        $wpdb->prefix . 'mhc_roles',
        $wpdb->prefix . 'mhc_worker_roles',
        $wpdb->prefix . 'mhc_worker_patient_roles',
        $wpdb->prefix . 'mhc_payroll_segments',
        $wpdb->prefix . 'mhc_patient_payrolls',
        $wpdb->prefix . 'mhc_qb_queue',
        $wpdb->prefix . 'mhc_qb_checks',
    ];
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS `$table`");
    }
}

// Elimina las opciones. Importante: mhc_qb_access_token y mhc_qb_refresh_token son
// credenciales vivas de QuickBooks; dejarlas atrás tras un uninstall es una fuga.
$options = [
    'mhc_db_version',
    'mhc_week_start_day',
    'mhc_company_name',
    'mhc_company_logo_id',
    'mhc_qb_client_id',
    'mhc_qb_client_secret',
    'mhc_qb_realm_id',
    'mhc_qb_base_url',
    'mhc_qb_checking_account_id',
    'mhc_qb_expense_account_id',
    'mhc_qb_process_key',
    'mhc_qb_access_token',
    'mhc_qb_refresh_token',
];
foreach ($options as $option) {
    delete_option($option);
}

// Limpia el cron de procesamiento de la cola de QuickBooks
$timestamp = wp_next_scheduled('mhc_qb_process_queue_cron');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'mhc_qb_process_queue_cron');
}
wp_clear_scheduled_hook('mhc_qb_process_queue_cron');
