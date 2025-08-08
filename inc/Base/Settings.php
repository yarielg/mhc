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
        if (is_user_logged_in() || is_page('app-login')) return;

         wp_safe_redirect(home_url('/app-login')); exit;
    }

}