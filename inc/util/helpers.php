<?php

/**
 * Nombre de la clínica que aparece en PDFs, emails y la cabecera de la app.
 * El default reproduce el valor que estaba hardcodeado, para que un sitio que
 * todavía no ha configurado nada siga generando exactamente la misma salida.
 */
function mhc_company_name() {
    $name = trim((string) get_option('mhc_company_name', ''));
    return $name !== '' ? $name : 'Agency of Mental Health Services';
}

/**
 * Ruta absoluta en disco del logo, para mPDF (no acepta URL remota de forma fiable).
 *
 * @param string $fallback Archivo de assets/img usado si no hay logo configurado.
 * @return string Ruta absoluta, o cadena vacía si no existe ningún archivo utilizable.
 */
function mhc_company_logo_path($fallback = 'mentalhelt.jpg') {
    $attachment_id = (int) get_option('mhc_company_logo_id', 0);
    if ($attachment_id > 0) {
        $path = get_attached_file($attachment_id);
        if ($path && file_exists($path)) {
            return $path;
        }
    }
    $bundled = MHC_PLUGIN_PATH . 'assets/img/' . $fallback;
    return file_exists($bundled) ? $bundled : '';
}

/**
 * URL pública del logo, para emails HTML y la UI Vue.
 *
 * @param string $fallback Archivo de assets/img usado si no hay logo configurado.
 * @return string
 */
function mhc_company_logo_url($fallback = 'mentalhelt.png') {
    $attachment_id = (int) get_option('mhc_company_logo_id', 0);
    if ($attachment_id > 0) {
        $url = wp_get_attachment_url($attachment_id);
        if ($url) {
            return $url;
        }
    }
    return MHC_PLUGIN_URL . 'assets/img/' . $fallback;
}

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
        ['{{greeting}}', '{{title}}', '{{content}}', '{{year}}', '{{logo_url}}', '{{company_name}}'],
        [$greeting, $title, $content, $year, mhc_company_logo_url(), mhc_company_name()],
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


