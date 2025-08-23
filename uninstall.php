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
        $wpdb->prefix . 'mhc_patient_payroll',
        $wpdb->prefix . 'mhc_hours_entries',
        $wpdb->prefix . 'mhc_extra_payments',
        $wpdb->prefix . 'mhc_special_rates',
    ];
    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS `$table`");
    }
}
