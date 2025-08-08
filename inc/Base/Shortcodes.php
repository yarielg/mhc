<?php
/**
 * Shortcode class contain all the logic to create all the shortcodes used on the site.
 *
 */

namespace Mhc\Inc\Base;


class Shortcodes
{
    public function register(){
        /**
         * Shortcodes
         */
        add_shortcode( 'mhc_app', array($this, 'dashboard') );
        add_shortcode( 'mhc_app_login', array($this, 'login') );
    }


    public function dashboard(){
        return "<div id='vwp-plugin'></div>";
    }

    public function login($atts){
        if (is_user_logged_in()) {
            wp_safe_redirect(home_url('/')); exit;
        }
        $args = [
            'echo'           => false,
            'redirect'       => home_url('/'), // after login go to homepage (shortcode)
            'remember'       => true,
            'form_id'        => 'mhc-loginform',
            'label_username' => __('Email or Username'),
            'label_password' => __('Password'),
            'label_log_in'   => __('Log In'),
        ];
        $form = wp_login_form($args);
        $links = sprintf(
            '<p style="margin-top:1rem;">
          <a href="%1$s">Lost your password?</a>
        </p>',
            esc_url(wp_lostpassword_url())
        );
        return '<div class="mhc-login">'.$form.$links.'</div>';
    }


}