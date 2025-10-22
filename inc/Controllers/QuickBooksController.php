<?php
namespace Mhc\Inc\Controllers;

defined('ABSPATH') || exit;

class QuickBooksController
{
    public function register()
    {
        // Callback del OAuth2
        add_action('init', [$this, 'mhc_register_callback_route']);        
    }

    public function mhc_register_callback_route()
    {
        // Verificamos si estamos en la URL /qb/callback
        $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');       
        //check if the path contains 'qb/callback'
        if (strpos($path, 'qb/callback') === false) return;
                
        // Parámetros enviados por QuickBooks
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        $realm_id = isset($_GET['realmId']) ? sanitize_text_field($_GET['realmId']) : '';
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';

        // Validación mínima
        if (empty($code) || empty($realm_id)) {
            wp_die(__('Invalid QuickBooks authorization response.', 'mhc'));
        }

        // Obtenemos credenciales guardadas en ajustes
        $client_id = get_option('mhc_qb_client_id');
        $client_secret = get_option('mhc_qb_client_secret');
        $redirect_uri = home_url('/qb/callback');

        // Endpoint oficial para intercambio de tokens
        $url = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ];
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect_uri,
        ];

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => http_build_query($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            wp_die('QuickBooks connection failed: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['access_token'])) {
            wp_die('QuickBooks did not return valid tokens. Response: ' . wp_remote_retrieve_body($response));
        }

        // Guardamos tokens y realm ID
        update_option('mhc_qb_access_token', $data['access_token']);
        update_option('mhc_qb_refresh_token', $data['refresh_token']);
        update_option('mhc_qb_realm_id', $realm_id);

        // Mensaje de confirmación
        wp_redirect(admin_url('admin.php?page=mhc_quickbooks_settings&connected=1'));
        exit;
    }
}
