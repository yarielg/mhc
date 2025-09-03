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
        $wpdb->prefix . 'mhc_payrolls',        
        $wpdb->prefix . 'mhc_hours_entries',
        $wpdb->prefix . 'mhc_extra_payments',
        $wpdb->prefix . 'mhc_special_rates',
        $wpdb->prefix . 'mhc_roles',
        $wpdb->prefix . 'mhc_worker_roles',
        $wpdb->prefix . 'mhc_worker_patient_roles',
        $wpdb->prefix . 'mhc_payroll_segments',
        $wpdb->prefix . 'mhc_patient_payrolls',        
    ];
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS `$table`");
    }
}
