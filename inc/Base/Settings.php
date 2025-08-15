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

    }

    public function redirect_users(){

        if (is_404()) {
            $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

            // List all your Vue routes here (without leading slash)
            $vue_routes = [
                'workers',
                'patients',
                'payrolls',
                '/payrolls/new'
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

