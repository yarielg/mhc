<?php

/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Base;

class Settings{    

    public function register(){
        add_action('template_redirect', array($this, 'redirect_users'));

        // Endpoints AJAX para día de inicio de semana
        add_action('wp_ajax_mhc_get_week_start_day', [$this, 'ajax_get_week_start_day']);
        add_action('wp_ajax_mhc_set_week_start_day', [$this, 'ajax_set_week_start_day']);
        add_action('wp_ajax_mhc_reset_week_start_day', [$this, 'ajax_reset_week_start_day']);
    }

    /**
     * Valida acceso AJAX (puedes personalizar según tu lógica de seguridad)
     */
    private function check() {
        if (!function_exists('mhc_check_ajax_access')) {
            require_once dirname(__DIR__, 2) . '/util/helpers.php';
        }
        mhc_check_ajax_access();
    }
    
    /**
     * Devuelve el día configurado de inicio de semana
     */
    public function ajax_get_week_start_day() {
        $this->check();
        $day = get_option('mhc_week_start_day', 'monday');
        wp_send_json_success(['week_start_day' => $day]);
    }

    /**
     * Actualiza el día de inicio de semana
     * POST: week_start_day (string)
     */
    public function ajax_set_week_start_day() {
        $this->check();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No autorizado'], 403);
        }
        $day = isset($_POST['week_start_day']) ? sanitize_text_field($_POST['week_start_day']) : '';
        $valid_days = ['monday','sunday','tuesday','wednesday','thursday','friday','saturday'];
        if (!in_array($day, $valid_days, true)) {
            wp_send_json_error(['message' => 'Valor inválido'], 400);
        }
        update_option('mhc_week_start_day', $day);
        wp_send_json_success(['week_start_day' => $day]);
    }

    /**
     * Restaura el valor por defecto (lunes)
     */
    public function ajax_reset_week_start_day() {
        $this->check();
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'No autorizado'], 403);
        }
        update_option('mhc_week_start_day', 'monday');
        wp_send_json_success(['week_start_day' => 'monday']);
    }

    public function redirect_users(){

        if (is_404()) {
            $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

            // List all your Vue routes here (without leading slash)
            $vue_routes = [
                'workers',
                'patients',
                'payrolls',
                '/payrolls/new',
                'reports',
                'reports/all',
            ];

            // If the requested path matches a Vue route, load your plugin's root page
            if (in_array($request_uri, $vue_routes, true)) {
                // Load your plugin's main template instead of 404
                status_header(200);
                wp_safe_redirect(home_url('/')); exit;
            }
        }

        if (is_user_logged_in() || is_page('app-login')) return;

         wp_safe_redirect(home_url('/app-login')); exit;
    }

}

