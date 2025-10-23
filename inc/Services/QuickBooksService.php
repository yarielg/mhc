<?php

namespace Mhc\Inc\Services;

defined('ABSPATH') || exit;

class QuickBooksService
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $baseUrl;
    private $accessToken;
    private $refreshToken;
    private $realmId;

    public function __construct()
    {
        $this->clientId = get_option('mhc_qb_client_id');
        $this->clientSecret = get_option('mhc_qb_client_secret');
        $this->redirectUri = home_url('/qb/callback');
        // Accept either a short value ('sandbox'|'production') or a full base URL for backward compatibility
        $raw_base = get_option('mhc_qb_base_url', 'sandbox');
        if ($raw_base === 'sandbox') {
            $this->baseUrl = 'https://sandbox-quickbooks.api.intuit.com';
        } elseif ($raw_base === 'production') {
            $this->baseUrl = 'https://quickbooks.api.intuit.com';
        } else {
            // If user previously saved a full URL, use it (trim trailing slash)
            $this->baseUrl = rtrim($raw_base, '/');
        }
        $this->accessToken = get_option('mhc_qb_access_token');
        $this->refreshToken = get_option('mhc_qb_refresh_token');
        $this->realmId = get_option('mhc_qb_realm_id');
    }

    public function getAuthUrl()
    {
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'scope' => 'com.intuit.quickbooks.accounting',
            'redirect_uri' => $this->redirectUri,
            'state' => wp_create_nonce('mhc_qb_oauth')
        ];

        return 'https://appcenter.intuit.com/connect/oauth2?' . http_build_query($params);
    }

    public function exchangeCodeForTokens($code)
    {
        $url = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ];

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => http_build_query($body),
        ]);

        if (is_wp_error($response)) return $response;

        $data = json_decode(wp_remote_retrieve_body($response), true);

        update_option('mhc_qb_access_token', $data['access_token']);
        update_option('mhc_qb_refresh_token', $data['refresh_token']);

        return $data;
    }

    public function refreshTokens()
    {
        $url = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];
        $body = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refreshToken,
        ];

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => http_build_query($body),
        ]);

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($data['access_token'])) {
            update_option('mhc_qb_access_token', $data['access_token']);
            update_option('mhc_qb_refresh_token', $data['refresh_token']);
        }

        return $data;
    }

    public function request($method, $endpoint, $body = null, $retry = true)
    {
        // Verifica que haya tokens y realm ID
        if (empty($this->accessToken) || empty($this->realmId)) {
            return new \WP_Error('mhc_qb_not_connected', 'QuickBooks not connected or tokens missing.');
        }

        $url = trailingslashit($this->baseUrl) . "v3/company/{$this->realmId}/" . ltrim($endpoint, '/');

        error_log('Requested path: ' . $url);
        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json'
            ],
            'timeout' => 30,
        ];

        if (!empty($body)) {
            $args['body'] = is_array($body) ? wp_json_encode($body) : $body;
        }

        $response = wp_remote_request($url, $args);

        // Si el token expiró (401) y no hemos reintentado, lo refrescamos
        if ($retry && wp_remote_retrieve_response_code($response) === 401) {
            $new_token_data = $this->refreshTokens();
            if (!empty($new_token_data['access_token'])) {
                $this->accessToken = $new_token_data['access_token'];
                // Intentamos de nuevo la misma petición
                return $this->request($method, $endpoint, $body, false);
            }
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $body_response = wp_remote_retrieve_body($response);
        $data = json_decode($body_response, true);

        if (empty($data)) {
            return new \WP_Error('mhc_qb_invalid_response', 'Invalid or empty response from QuickBooks: ' . $body_response);
        }

        return $data;
    }
}
