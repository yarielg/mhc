<?php

/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Base;

class Enqueue{

    public function register(){

        //add_action( 'admin_enqueue_scripts', array( $this , 'enqueue_admin' ) ); //action to include script to the backend, in order to include in the frontend is just wp_enqueue_scripts instead admin_enqueue_scripts

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend'));



    }

   function enqueue_admin() {
      /* wp_enqueue_script('admin_js', CBF_PLUGIN_URL . '/assets/js/admin.js' ,array('jquery'),'1.0', true);
       wp_enqueue_script( 'admin_js');
       wp_localize_script( 'admin_js', 'parameters',['ajax_url'=> admin_url('admin-ajax.php')]);*/
    }


    function enqueue_frontend(){

        //wp_enqueue_script('main-js', CBF_PLUGIN_URL . '/assets/js/main.js' ,array('jquery'),'1.0', true);

        // wp_enqueue_style('vue-custom-icon', 'https://cdn.jsdelivr.net/npm/@mdi/font@4.x/css/materialdesignicons.min.css');
        // wp_enqueue_style('main_css', CBF_PLUGIN_URL . '/assets/css/main.css');

        wp_enqueue_script('vue-custom-js', MHC_PLUGIN_URL . 'assets/dist/app.js' ,array('jquery'),'1.0', true);

        wp_localize_script( 'vue-custom-js', 'parameters', [
            'site_url' => site_url(),
            'ajax_url'=> admin_url('admin-ajax.php'),
            'plugin_path' => MHC_PLUGIN_URL,
            'img_url' => MHC_PLUGIN_URL . 'assets/img/',
            'nonce'   => wp_create_nonce('mhc_ajax'),
        ]);

        wp_enqueue_style( 'main_css', MHC_PLUGIN_URL . '/assets/dist/app.css'  );
    }

}