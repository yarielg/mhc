<?php

/*
*
* @package Yariko
*/

namespace Mhc\Inc\Base;

use WP_Error;
use wpdb;

class Ajax{

    public static function register(){
        // Endpoint AJAX para borrar tablas: /wp-admin/admin-ajax.php?action=mhc_delete_plugin_tables
        add_action('wp_ajax_mhc_delete_plugin_tables', [__CLASS__, 'ajax_delete_plugin_tables']);
    }

    /**
     * AJAX endpoint para borrar las tablas del plugin
     */
    public static function ajax_delete_plugin_tables() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No tienes permisos suficientes.'], 403);
        }
        // A capability check alone is not enough: without a nonce any page an authenticated
        // admin visits can trigger this and destroy the whole payroll history.
        if (!function_exists('mhc_check_ajax_access')) {
            require_once dirname(__DIR__) . '/util/helpers.php';
        }
        mhc_check_ajax_access();

        global $wpdb;
        $tables = [
            $wpdb->prefix . 'mhc_roles',
            $wpdb->prefix . 'mhc_workers',
            $wpdb->prefix . 'mhc_patients',
            $wpdb->prefix . 'mhc_worker_roles',
            $wpdb->prefix . 'mhc_worker_patient_roles',
            $wpdb->prefix . 'mhc_payrolls',
            $wpdb->prefix . 'mhc_payroll_segments',
            $wpdb->prefix . 'mhc_hours_entries',
            $wpdb->prefix . 'mhc_extra_payments',
            $wpdb->prefix . 'mhc_special_rates',
            $wpdb->prefix . 'mhc_patient_payrolls',
        ];
        $message = 'Tablas eliminadas: ';
        foreach ($tables as $table) {
            $message .= "$table, ";
            $wpdb->query("DROP TABLE IF EXISTS `$table`");
        }
        wp_send_json_success(['message' => rtrim($message, ', ')]);
    }

}