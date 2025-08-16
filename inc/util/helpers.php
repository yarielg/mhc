

<?php

function mhc_template( $file, $args ){
    // ensure the file exists
    if ( !file_exists( $file ) ) {
        return '';
    }

    // Make values in the associative array easier to access by extracting them
    if ( is_array( $args ) ){
        extract( $args );
    }

    // buffer the output (including the file is "output")
    ob_start();
    include $file;
    return ob_get_clean();
}



/**
 * Verifica acceso y nonce para AJAX en controllers
 */
function mhc_check_ajax_access($capability = null, $nonce_action = null) {
    $cap = $capability ?: (defined('MHC_DEFAULT_CAPABILITY') ? MHC_DEFAULT_CAPABILITY : 'manage_options');
    $nonce_act = $nonce_action ?: (defined('MHC_DEFAULT_NONCE_ACTION') ? MHC_DEFAULT_NONCE_ACTION : 'mhc_ajax');
    if (!\current_user_can($cap)) {
        \wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    $nonce = $_REQUEST['_wpnonce'] ?? ($_REQUEST['nonce'] ?? '');
    if (!\wp_verify_nonce($nonce, $nonce_act)) {
        \wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
}


