
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
 * Construye y envía un email HTML usando el template y adjuntos opcionales.
 * @param string $to Email destino
 * @param string $greeting Saludo personalizado
 * @param string $title Título del email
 * @param string $content Contenido principal
 * @param array $attachments Archivos adjuntos (PDF, etc)
 * @param string|null $logo_path Ruta al logo para CID (opcional)
 * @return bool Resultado de wp_mail
 */
function mhc_send_email($to, $greeting, $title, $content, $attachments = [], $logo_path = null) {
    $year = date('Y');
    $body = mhc_build_email($greeting, $title, $content, $year);
    $subject = $title;
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    // Adjuntar logo como CID si se provee
    if ($logo_path && file_exists($logo_path)) {
        $attachments[] = [
            'path' => $logo_path,
            'name' => basename($logo_path),
            'type' => 'image/png',
            'encoding' => 'base64',
            'disposition' => 'inline',
            'cid' => 'company-logo'
        ];
    }
    return wp_mail($to, $subject, $body, $headers, $attachments);
}


function mhc_build_email($greeting, $title, $content, $year = null) {
    $template_path = dirname(__DIR__) . '/Templates/emails/email-template.html';
    if (!file_exists($template_path)) return '';
    $template = file_get_contents($template_path);
    if (!$year) $year = date('Y');
    return str_replace(
        ['{{greeting}}', '{{title}}', '{{content}}', '{{year}}'],
        [$greeting, $title, $content, $year],
        $template
    );
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


