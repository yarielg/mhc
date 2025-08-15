<?php
namespace Mhc\Inc\Controllers;

use Mhc\Inc\Models\Payroll;
use Mhc\Inc\Models\PatientPayroll;

if (!defined('ABSPATH')) exit;

class PayrollController
{
    const NONCE_ACTION = 'mhc_ajax';
    const CAPABILITY   = 'manage_options'; // ajusta si usas otra cap

    /** Llama esto en tu bootstrap: \Mhc\Inc\Controllers\PayrollController::register(); */
    public static function register() {
        // Payroll CRUD básico
        add_action('wp_ajax_mhc_payroll_list',        [__CLASS__, 'ajax_list']);
        add_action('wp_ajax_mhc_payroll_get',         [__CLASS__, 'ajax_get']);
        add_action('wp_ajax_mhc_payroll_create',      [__CLASS__, 'ajax_create']);
        add_action('wp_ajax_mhc_payroll_update',      [__CLASS__, 'ajax_update']);
        add_action('wp_ajax_mhc_payroll_delete',      [__CLASS__, 'ajax_delete']);
        add_action('wp_ajax_mhc_payroll_finalize',    [__CLASS__, 'ajax_finalize']);
        add_action('wp_ajax_mhc_payroll_reopen',      [__CLASS__, 'ajax_reopen']);

        // Pacientes del payroll (seed + listar + marcar procesado)
        add_action('wp_ajax_mhc_payroll_seed_patients',      [__CLASS__, 'ajax_seed_patients']); // opcional re-seed
        add_action('wp_ajax_mhc_payroll_patients',           [__CLASS__, 'ajax_list_patients']); // con filtro is_processed
        add_action('wp_ajax_mhc_patient_payroll_set_processed', [__CLASS__, 'ajax_set_processed']);
    }

    /* ========================= Helpers ========================= */

    private static function check_access_and_nonce() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        $nonce = $_REQUEST['_wpnonce'] ?? ($_REQUEST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }
    }

    private static function json_input(): array {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $data = json_decode($raw, true);
            if (is_array($data)) return $data;
        }
        return $_REQUEST;
    }

    private static function sanitize_date($v) {
        $v = (string)$v;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    /* ========================= Payroll ========================= */

    // GET: lista de payrolls (respeta tu modelo: start_date_from/to, status, search, orderby, order, limit/offset)
    public static function ajax_list() {
        self::check_access_and_nonce();
        $args = [];
        foreach (['status','search','orderby','order'] as $k) {
            if (isset($_GET[$k])) $args[$k] = sanitize_text_field($_GET[$k]);
        }
        if (!empty($_GET['start_date_from'])) $args['start_date_from'] = self::sanitize_date($_GET['start_date_from']);
        if (!empty($_GET['start_date_to']))   $args['start_date_to']   = self::sanitize_date($_GET['start_date_to']);
        if (isset($_GET['limit']))  $args['limit']  = max(1, (int)$_GET['limit']);
        if (isset($_GET['offset'])) $args['offset'] = max(0, (int)$_GET['offset']);

        $rows = Payroll::findAll($args);
        wp_send_json_success(['items'=>$rows]);
    }

    // GET: un payroll (sólo el registro)
    public static function ajax_get() {
        self::check_access_and_nonce();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) wp_send_json_error(['message'=>'Missing id'], 400);
        $row = Payroll::findById($id);
        if (!$row) wp_send_json_error(['message'=>'Not found'], 404);
        wp_send_json_success($row);
    }

    // POST: start_date, end_date, (status, notes)
    // Crea payroll + SEED de pacientes activos (is_processed=0) + devuelve lista inicial y contadores
    public static function ajax_create() {
        self::check_access_and_nonce();
        $data = self::json_input();

        $payload = [
            'start_date' => isset($data['start_date']) ? self::sanitize_date($data['start_date']) : '',
            'end_date'   => isset($data['end_date'])   ? self::sanitize_date($data['end_date'])   : '',
            'status'     => isset($data['status']) ? sanitize_text_field($data['status']) : 'draft',
            'notes'      => isset($data['notes'])  ? sanitize_text_field($data['notes'])  : '',
        ];
        if (!$payload['start_date'] || !$payload['end_date']) {
            wp_send_json_error(['message'=>'start_date y end_date son requeridos'], 400);
        }
        // (Opcional) Evitar solape de periodos
        if (method_exists(Payroll::class, 'hasOverlap') && Payroll::hasOverlap($payload['start_date'], $payload['end_date'])) {
            wp_send_json_error(['message'=>'El rango de fechas se solapa con otro payroll'], 409);
        }

        $id = Payroll::create($payload);
        if ($id instanceof \WP_Error) {
            wp_send_json_error(['message'=>$id->get_error_message()], 400);
        }
        if (!$id) wp_send_json_error(['message'=>'No se pudo crear el payroll'], 500);

        // SEED aquí (tal como pediste)
        $seeded = PatientPayroll::seedForPayroll((int)$id);

        // Lista inicial (todos) + contadores
        $patients = PatientPayroll::findByPayroll((int)$id, ['is_processed'=>'all']);
        $counts   = PatientPayroll::countsByStatus((int)$id);

        // (Opcional) detalle completo del payroll (stats/totals) si ya tienes esos métodos
        $detail = method_exists(Payroll::class, 'detail') ? Payroll::detail((int)$id) : null;

        wp_send_json_success([
            'id'       => (int)$id,
            'seeded'   => (int)$seeded,
            'patients' => $patients,
            'counts'   => $counts,
            'detail'   => $detail,
        ]);
    }

    // PATCH/POST: id, (start_date, end_date, status, notes)
    public static function ajax_update() {
        self::check_access_and_nonce();
        $data = self::json_input();
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id <= 0) wp_send_json_error(['message'=>'Missing id'], 400);

        $upd = [];
        if (isset($data['start_date'])) $upd['start_date'] = self::sanitize_date($data['start_date']);
        if (isset($data['end_date']))   $upd['end_date']   = self::sanitize_date($data['end_date']);
        if (isset($data['status']))     $upd['status']     = sanitize_text_field($data['status']);
        if (isset($data['notes']))      $upd['notes']      = sanitize_text_field($data['notes']);

        $ok = Payroll::update($id, $upd);
        if ($ok instanceof \WP_Error) wp_send_json_error(['message'=>$ok->get_error_message()], 400);
        if (!$ok) wp_send_json_error(['message'=>'Update failed'], 500);
        wp_send_json_success(['id'=>$id,'updated'=>true]);
    }

    // POST: id
    public static function ajax_delete() {
        self::check_access_and_nonce();
        $id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
        if ($id <= 0) wp_send_json_error(['message'=>'Missing id'], 400);

        $ok = Payroll::delete($id);
        if ($ok instanceof \WP_Error) wp_send_json_error(['message'=>$ok->get_error_message()], 400);
        if (!$ok) wp_send_json_error(['message'=>'Delete failed'], 500);
        wp_send_json_success(['id'=>$id,'deleted'=>true]);
    }

    // POST: id
    public static function ajax_finalize() {
        self::check_access_and_nonce();
        $id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
        if ($id <= 0) wp_send_json_error(['message'=>'Missing id'], 400);

        $ok = Payroll::finalize($id);
        if ($ok instanceof \WP_Error) wp_send_json_error(['message'=>$ok->get_error_message()], 400);
        wp_send_json_success(['id'=>$id,'finalized'=>true]);
    }

    // POST: id
    public static function ajax_reopen() {
        self::check_access_and_nonce();
        $id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
        if ($id <= 0) wp_send_json_error(['message'=>'Missing id'], 400);

        $ok = Payroll::reopen($id);
        if ($ok instanceof \WP_Error) wp_send_json_error(['message'=>$ok->get_error_message()], 400);
        wp_send_json_success(['id'=>$id,'reopened'=>true]);
    }

    /* ==================== PatientPayroll (seed/list/toggle) ==================== */

    // POST: payroll_id  → re-seed (por si hay pacientes activos nuevos)
    public static function ajax_seed_patients() {
        self::check_access_and_nonce();
        $payroll_id = isset($_REQUEST['payroll_id']) ? (int)$_REQUEST['payroll_id'] : 0;
        if ($payroll_id <= 0) wp_send_json_error(['message'=>'Missing payroll_id'], 400);

        $added = PatientPayroll::seedForPayroll($payroll_id);

        $patients = PatientPayroll::findByPayroll($payroll_id, ['is_processed'=>'all']);
        $counts   = PatientPayroll::countsByStatus($payroll_id);

        wp_send_json_success([
            'added'    => (int)$added,
            'patients' => $patients,
            'counts'   => $counts,
        ]);
    }

    // GET/POST: payroll_id, is_processed = all|0|1  → lista pacientes del payroll con filtro
    public static function ajax_list_patients() {
        self::check_access_and_nonce();

        $payroll_id   = isset($_REQUEST['payroll_id']) ? (int)$_REQUEST['payroll_id'] : 0;
        $is_processed = $_REQUEST['is_processed'] ?? 'all'; // 'all' | '0' | '1'
        if ($payroll_id <= 0) wp_send_json_error(['message'=>'Missing payroll_id'], 400);

        $patients = PatientPayroll::findByPayroll($payroll_id, ['is_processed'=>$is_processed]);
        $counts   = PatientPayroll::countsByStatus($payroll_id);

        wp_send_json_success([
            'payroll_id' => $payroll_id,
            'filter'     => $is_processed,
            'patients'   => $patients,
            'counts'     => $counts,
        ]);
    }

    // POST: payroll_id, patient_id, is_processed (0|1) → toggle por paciente
    public static function ajax_set_processed() {
        self::check_access_and_nonce();

        $payroll_id  = isset($_POST['payroll_id']) ? (int)$_POST['payroll_id'] : 0;
        $patient_id  = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
        $is_processed= isset($_POST['is_processed']) ? (int)$_POST['is_processed'] : 0;

        if ($payroll_id <= 0 || $patient_id <= 0) {
            wp_send_json_error(['message'=>'payroll_id y patient_id son requeridos'], 400);
        }

        $ok = PatientPayroll::setProcessed($payroll_id, $patient_id, $is_processed);
        if (!$ok) wp_send_json_error(['message'=>'No se pudo actualizar is_processed'], 500);

        // respuesta útil para refrescar UI
        $counts = PatientPayroll::countsByStatus($payroll_id);
        wp_send_json_success([
            'updated' => ['payroll_id'=>$payroll_id, 'patient_id'=>$patient_id, 'is_processed'=>$is_processed],
            'counts'  => $counts
        ]);
    }
}
