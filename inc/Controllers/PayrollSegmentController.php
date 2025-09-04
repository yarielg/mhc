<?php
namespace Mhc\Inc\Controllers;

use Mhc\Inc\Models\PayrollSegment;
use WP_Error;

class PayrollSegmentController
{

    /**
     * Registro de endpoints REST y AJAX
     */
    public static function register() {

        add_action('wp_ajax_mhc_segment_list',    [__CLASS__, 'ajax_list']);
        add_action('wp_ajax_mhc_segment_get',     [__CLASS__, 'ajax_get']);
        add_action('wp_ajax_mhc_segment_create',  [__CLASS__, 'ajax_create']);
        add_action('wp_ajax_mhc_segment_update',  [__CLASS__, 'ajax_update']);
        add_action('wp_ajax_mhc_segment_delete',  [__CLASS__, 'ajax_delete']);

    }

    /**
     * Validación de acceso AJAX
     */
    private static function check() {
        if (!function_exists('mhc_check_ajax_access')) {
            require_once dirname(__DIR__, 2) . '/util/helpers.php';
        }
        mhc_check_ajax_access();
    }

    /**
     * AJAX: Listar segmentos
     */
    public static function ajax_list() {
        self::check();
        $payrollId = isset($_POST['payroll_id']) ? absint($_POST['payroll_id']) : 0;
        $result = \Mhc\Inc\Models\PayrollSegment::findAll(['payroll_id' => $payrollId]);
        wp_send_json_success($result);
    }

    /**
     * AJAX: Obtener segmento por ID
     */
    public static function ajax_get() {
        self::check();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $result = \Mhc\Inc\Models\PayrollSegment::findById($id);
        wp_send_json($result);
    }

    //get segtments by payroll id
    public static function ajax_get_by_payroll() {
        self::check();
        $payrollId = isset($_POST['payroll_id']) ? absint($_POST['payroll_id']) : 0;
        $result = \Mhc\Inc\Models\PayrollSegment::findAll(['payroll_id' => $payrollId]);
        wp_send_json($result);
    }

    /**
     * AJAX: Crear segmento
     */
    public static function ajax_create() {
        self::check();
        $data = $_POST;
        $result = \Mhc\Inc\Models\PayrollSegment::create($data);
        wp_send_json($result);
    }

    /**
     * AJAX: Actualizar segmento
     */
    public static function ajax_update() {
        self::check();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $data = $_POST;
        $result = \Mhc\Inc\Models\PayrollSegment::update($id, $data);
        wp_send_json($result);
    }

    /**
     * AJAX: Eliminar segmento
     */
    public static function ajax_delete() {
        self::check();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $result = \Mhc\Inc\Models\PayrollSegment::delete($id);
        wp_send_json($result);
    }
}