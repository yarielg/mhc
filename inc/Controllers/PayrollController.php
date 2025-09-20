<?php

namespace Mhc\Inc\Controllers;

use Mhc\Inc\Models\Payroll;
use Mhc\Inc\Models\PatientPayroll;
use Mhc\Inc\Models\HoursEntry;
use Mhc\Inc\Models\WorkerPatientRole;
use Mhc\Inc\Models\ExtraPayment;
use WP_Error;

if (!defined('ABSPATH')) exit;

class PayrollController
{

    public static function register()
    {
        // Payroll CRUD básico
        add_action('wp_ajax_mhc_payroll_list',        [__CLASS__, 'ajax_list']);
        add_action('wp_ajax_mhc_payroll_get',         [__CLASS__, 'ajax_get']);
        add_action('wp_ajax_mhc_payroll_check_overlap', [__CLASS__, 'ajax_check_overlap']); //Overlap check --- IGNORE ---
        add_action('wp_ajax_mhc_payroll_create',      [__CLASS__, 'ajax_create']);
        add_action('wp_ajax_mhc_payroll_update',      [__CLASS__, 'ajax_update']);
        add_action('wp_ajax_mhc_payroll_delete',      [__CLASS__, 'ajax_delete']);
        add_action('wp_ajax_mhc_payroll_finalize',    [__CLASS__, 'ajax_finalize']);
        add_action('wp_ajax_mhc_payroll_reopen',      [__CLASS__, 'ajax_reopen']);

        // Pacientes del payroll (seed + listar + marcar procesado)
        add_action('wp_ajax_mhc_payroll_seed_patients',      [__CLASS__, 'ajax_seed_patients']); // opcional re-seed
        add_action('wp_ajax_mhc_payroll_patients',           [__CLASS__, 'ajax_list_patients']); // con filtro is_processed
        add_action('wp_ajax_mhc_patient_payroll_set_processed', [__CLASS__, 'ajax_set_processed']);

        // Asignaciones (workers) por paciente en el payroll
        add_action('wp_ajax_mhc_payroll_patient_workers', [__CLASS__, 'ajax_patient_workers']);
        add_action('wp_ajax_mhc_payroll_patient_workers_add', [__CLASS__, 'ajax_patient_workers_add']);

        // Horas por paciente en el payroll
        add_action('wp_ajax_mhc_payroll_hours_list',   [__CLASS__, 'ajax_hours_list']);
        add_action('wp_ajax_mhc_payroll_hours_upsert', [__CLASS__, 'ajax_hours_upsert']);
        add_action('wp_ajax_mhc_payroll_hours_delete', [__CLASS__, 'ajax_hours_delete']);

        add_action('wp_ajax_mhc_payroll_workers',          [__CLASS__, 'ajax_workers']);          // resumen por trabajador
        add_action('wp_ajax_mhc_payroll_worker_detail',    [__CLASS__, 'ajax_worker_detail']);    // detalle de la colilla por trabajador

        // catálogo de special rates (para el selector de extras en el front)
        add_action('wp_ajax_mhc_special_rates_list',       [__CLASS__, 'ajax_special_rates_list']);

        // extras por trabajador en el payroll
        add_action('wp_ajax_mhc_payroll_extras_list',      [__CLASS__, 'ajax_extras_list']);
        add_action('wp_ajax_mhc_payroll_extras_create',    [__CLASS__, 'ajax_extras_create']);
        add_action('wp_ajax_mhc_payroll_extras_update',    [__CLASS__, 'ajax_extras_update']);
        add_action('wp_ajax_mhc_payroll_extras_delete',    [__CLASS__, 'ajax_extras_delete']);


        // PDF generation
        add_action('wp_ajax_mhc_payroll_send_all_slips', [__CLASS__, 'ajax_send_all_slips']);
        add_action('wp_ajax_mhc_payroll_send_worker_slip', [__CLASS__, 'ajax_send_worker_slip']);
    }

    /* ========================= Helpers ========================= */

    private static function check()
    {
        if (!function_exists('mhc_check_ajax_access')) {
            require_once dirname(__DIR__, 2) . '/util/helpers.php';
        }
        mhc_check_ajax_access();
    }

    private static function json_input(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $data = json_decode($raw, true);
            if (is_array($data)) return $data;
        }
        return $_REQUEST;
    }

    private static function sanitize_date($v)
    {
        $v = (string)$v;
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    }

    /* ========================= Payroll ========================= */

    // GET: lista de payrolls (respeta tu modelo: start_date_from/to, status, search, orderby, order, limit/offset)
    public static function ajax_list()
    {
        self::check();
        $args = [];
        foreach (['status', 'search', 'orderby', 'order'] as $k) {
            if (isset($_GET[$k])) $args[$k] = sanitize_text_field($_GET[$k]);
        }
        if (!empty($_GET['start_date_from'])) $args['start_date_from'] = self::sanitize_date($_GET['start_date_from']);
        if (!empty($_GET['start_date_to']))   $args['start_date_to']   = self::sanitize_date($_GET['start_date_to']);
        if (isset($_GET['limit']))  $args['limit']  = max(1, (int)$_GET['limit']);
        if (isset($_GET['offset'])) $args['offset'] = max(0, (int)$_GET['offset']);

        $rows = Payroll::findAll($args);
        wp_send_json_success(['items' => $rows]);
    }

    // GET: un payroll (sólo el registro)
    public static function ajax_get()
    {
        self::check();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) wp_send_json_error(['message' => 'Missing id'], 400);
        $row = Payroll::findById($id);
        if (!$row) wp_send_json_error(['message' => 'Not found'], 404);
        wp_send_json_success($row);
    }

    // POST: start_date, end_date
    //Overlap check --- IGNORE ---
    public static function ajax_check_overlap()
    {
        self::check();
        $data = self::json_input();

        $start_date = isset($data['start_date']) ? self::sanitize_date($data['start_date']) : '';
        $end_date   = isset($data['end_date'])   ? self::sanitize_date($data['end_date'])   : '';
        if (!$start_date || !$end_date) {
            wp_send_json_error(['message' => 'start_date and end_date required'], 400);
        }

        if (method_exists(Payroll::class, 'hasOverlap')) {
            $overlap = Payroll::hasOverlap($start_date, $end_date);
            wp_send_json_success(['overlap' => $overlap]);
        } else {
            wp_send_json_error(['message' => 'Overlap check not implemented'], 501);
        }
    }

    // POST: start_date, end_date, (status, notes)
    // Crea payroll + SEED de pacientes activos (is_processed=0) + devuelve lista inicial y contadores
    public static function ajax_create()
    {
        self::check();
        $data = self::json_input();

        $payload = [
            'start_date' => isset($data['start_date']) ? self::sanitize_date($data['start_date']) : '',
            'end_date'   => isset($data['end_date'])   ? self::sanitize_date($data['end_date'])   : '',
            'status'     => isset($data['status']) ? sanitize_text_field($data['status']) : 'draft',
            'notes'      => isset($data['notes'])  ? sanitize_text_field($data['notes'])  : '',
        ];
        if (!$payload['start_date'] || !$payload['end_date']) {
            wp_send_json_error(['message' => 'start_date and end_date required'], 400);
        }
        
        // (Opcional) avoid overlap if you have that method
       /*  if (method_exists(Payroll::class, 'hasOverlap') && Payroll::hasOverlap($payload['start_date'], $payload['end_date'])) {
            wp_send_json_error(['message' => 'El rango de fechas se solapa con otro payroll'], 409);
        } */

        //Avoid duplicates start_date and end_date
        if (Payroll::existsWithDates($payload['start_date'], $payload['end_date'])) {
            wp_send_json_error(['message' => 'A payroll with the same start_date and end_date already exists'], 409);
        }

        // === VALIDACIÓN DE DÍA DE INICIO DE SEMANA ===
        $week_start = get_option('mhc_week_start_day', 'monday');
        $week_days = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
        $week_start_num = isset($week_days[$week_start]) ? $week_days[$week_start] : 1;

        $start_date = $payload['start_date'];
        $end_date = $payload['end_date'];
        $start_ts = strtotime($start_date);
        $end_ts = strtotime($end_date);
        if ($start_ts === false || $end_ts === false) {
            wp_send_json_error(['message' => 'Invalid start or end date'], 400);
        }
        // Validate start_date matches week start
        if ((int)date('w', $start_ts) !== $week_start_num) {
            $day_name = ucfirst($week_start);
            wp_send_json_error(['message' => "Payroll start_date must be a $day_name"], 400);
        }

        // Validate start_date and end_date are week muiltiples (i.e., 7, 14, 21, 28 days apart)
        $diff_days = ($end_ts - $start_ts) / 86400 + 1; // inclusive
        if ($diff_days <= 0 || $diff_days % 7 !== 0) {
            wp_send_json_error(['message' => 'Payroll period must be in full week increments (7, 14, 21, 28 days)'], 400);
        }
        // === FIN VALIDACIÓN DÍA INICIO SEMANA ===

        $id = Payroll::create($payload);
        if ($id instanceof \WP_Error) {
            wp_send_json_error(['message' => $id->get_error_message()], 400);
        }
        if (!$id) wp_send_json_error(['message' => 'Could not create payroll'], 500);

        // === GENERACIÓN DE SEGMENTOS ===
        $segments = [];
        $cur_start = $start_ts;
        $seg_num = 1;

        while ($cur_start <= $end_ts) {
            // Calcular el fin de la semana (cur_start + 6 días)
            $cur_end = strtotime('+6 days', $cur_start);
            if ($cur_end > $end_ts) {
                $cur_end = $end_ts;
            }

            // Último día del mes en curso
            $month_end = strtotime(date('Y-m-t', $cur_start));

            // Caso: la semana cruza el fin de mes
            if ($month_end >= $cur_start && $month_end < $cur_end) {
                // Segmento 1: desde inicio hasta fin de mes
                $segments[] = [
                    'segment_start' => date('Y-m-d', $cur_start),
                    'segment_end'   => date('Y-m-d', $month_end),
                    'notes'         => "Week $seg_num: " . date('Y-m-d', $cur_start) . " to " . date('Y-m-d', $month_end) . " (End of month)"
                ];
                $seg_num++;

                // Segmento 2: desde el inicio del mes siguiente hasta el fin de semana
                $next_start = strtotime('+1 day', $month_end);
                $segments[] = [
                    'segment_start' => date('Y-m-d', $next_start),
                    'segment_end'   => date('Y-m-d', $cur_end),
                    'notes'         => "Week $seg_num: " . date('Y-m-d', $next_start) . " to " . date('Y-m-d', $cur_end)
                ];
                $seg_num++;

                $cur_start = strtotime('+1 day', $cur_end);
                continue;
            }

            // Caso normal: semana completa
            $segments[] = [
                'segment_start' => date('Y-m-d', $cur_start),
                'segment_end'   => date('Y-m-d', $cur_end),
                'notes'         => "Week $seg_num: " . date('Y-m-d', $cur_start) . " to " . date('Y-m-d', $cur_end)
            ];

            $seg_num++;
            $cur_start = strtotime('+1 day', $cur_end);
        }
        \Mhc\Inc\Models\PayrollSegment::bulkCreate((int)$id, $segments);

        // SEED aquí (tal como pediste)
        $seeded = PatientPayroll::seedForPayroll((int)$id);

        // Lista inicial (todos) + contadores
        $patients = PatientPayroll::findByPayroll((int)$id, ['is_processed' => 'all']);
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
    public static function ajax_update()
    {
        self::check();
        $data = self::json_input();
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        if ($id <= 0) wp_send_json_error(['message' => 'Missing id'], 400);

        $upd = [];
        if (isset($data['start_date'])) $upd['start_date'] = self::sanitize_date($data['start_date']);
        if (isset($data['end_date']))   $upd['end_date']   = self::sanitize_date($data['end_date']);
        if (isset($data['status']))     $upd['status']     = sanitize_text_field($data['status']);
        if (isset($data['notes']))      $upd['notes']      = sanitize_text_field($data['notes']);

        $ok = Payroll::update($id, $upd);
        if ($ok instanceof \WP_Error) wp_send_json_error(['message' => $ok->get_error_message()], 400);
        if (!$ok) wp_send_json_error(['message' => 'Update failed'], 500);
        wp_send_json_success(['id' => $id, 'updated' => true]);
    }

    // POST: id
    public static function ajax_delete()
    {
        self::check();
        $id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
        if ($id <= 0) wp_send_json_error(['message' => 'Missing id'], 400);

        $ok = Payroll::delete($id);
        if ($ok instanceof \WP_Error) wp_send_json_error(['message' => $ok->get_error_message()], 400);
        if (!$ok) wp_send_json_error(['message' => 'Delete failed'], 500);
        wp_send_json_success(['id' => $id, 'deleted' => true]);
    }

    // POST: id
    public static function ajax_finalize()
    {
        self::check();
        $id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
        if ($id <= 0) wp_send_json_error(['message' => 'Missing id'], 400);

        $ok = Payroll::finalize($id);
        if ($ok instanceof \WP_Error) wp_send_json_error(['message' => $ok->get_error_message()], 400);
        wp_send_json_success(['id' => $id, 'finalized' => true]);
    }

    // POST: id
    public static function ajax_reopen()
    {
        self::check();
        $id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
        if ($id <= 0) wp_send_json_error(['message' => 'Missing id'], 400);

        $ok = Payroll::reopen($id);
        if ($ok instanceof \WP_Error) wp_send_json_error(['message' => $ok->get_error_message()], 400);
        wp_send_json_success(['id' => $id, 'reopened' => true]);
    }

    /* ==================== PatientPayroll (seed/list/toggle) ==================== */

    // POST: payroll_id  → re-seed (por si hay pacientes activos nuevos)
    public static function ajax_seed_patients()
    {
        self::check();
        $payroll_id = isset($_REQUEST['payroll_id']) ? (int)$_REQUEST['payroll_id'] : 0;
        if ($payroll_id <= 0) wp_send_json_error(['message' => 'Missing payroll_id'], 400);

        $added = PatientPayroll::seedForPayroll($payroll_id);

        $patients = PatientPayroll::findByPayroll($payroll_id, ['is_processed' => 'all']);
        $counts   = PatientPayroll::countsByStatus($payroll_id);

        wp_send_json_success([
            'added'    => (int)$added,
            'patients' => $patients,
            'counts'   => $counts,
        ]);
    }

    // GET/POST: payroll_id, is_processed = all|0|1  → lista pacientes del payroll con filtro
    public static function ajax_list_patients()
    {
        self::check();

        $payroll_id   = isset($_REQUEST['payroll_id']) ? (int)$_REQUEST['payroll_id'] : 0;
        $is_processed = $_REQUEST['is_processed'] ?? 'all'; // 'all' | '0' | '1'
        $search = $_REQUEST['search'] ?? '';
        $worker_id = isset($_REQUEST['worker_id'] )  ? (int)$_REQUEST['worker_id'] : 0;
        if ($payroll_id <= 0) wp_send_json_error(['message' => 'Missing payroll_id'], 400);
        $patients = PatientPayroll::findByPayroll($payroll_id, ['is_processed' => $is_processed, 'search' => $search, 'worker_id' => $worker_id]);;
        $counts   = PatientPayroll::countsByStatus($payroll_id);

        wp_send_json_success([
            'payroll_id' => $payroll_id,
            'filter'     => $is_processed,
            'patients'   => $patients,
            'counts'     => $counts,
        ]);
    }

    // POST: payroll_id, patient_id, is_processed (0|1) → toggle por paciente
    public static function ajax_set_processed()
    {
        self::check();

        $payroll_id  = isset($_POST['payroll_id']) ? (int)$_POST['payroll_id'] : 0;
        $patient_id  = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
        $is_processed = isset($_POST['is_processed']) ? (int)$_POST['is_processed'] : 0;

        if ($payroll_id <= 0 || $patient_id <= 0) {
            wp_send_json_error(['message' => 'payroll_id and patient_id are required'], 400);
        }

        $ok = PatientPayroll::setProcessed($payroll_id, $patient_id, $is_processed);
        if (!$ok) wp_send_json_error(['message' => 'Could not update is_processed'], 500);

        // respuesta útil para refrescar UI
        $counts = PatientPayroll::countsByStatus($payroll_id);
        wp_send_json_success([
            'updated' => ['payroll_id' => $payroll_id, 'patient_id' => $patient_id, 'is_processed' => $is_processed],
            'counts'  => $counts
        ]);
    }

    // GET: payroll_id, patient_id
    public static function ajax_patient_workers()
    {
        self::check();
        $payroll_id = (int)($_REQUEST['payroll_id'] ?? 0);
        $patient_id = (int)($_REQUEST['patient_id'] ?? 0);
        if ($payroll_id <= 0 || $patient_id <= 0) wp_send_json_error(['message' => 'payroll_id and patient_id are required'], 400);

        $items = WorkerPatientRole::listForPatientInPayroll($patient_id, $payroll_id);
        wp_send_json_success(['items' => $items]);
    }

    // POST: payroll_id, patient_id, worker_id, role_id, rate? (opcional)
    public static function ajax_patient_workers_add()
    {
        self::check();
        $data = self::json_input();
        $payroll_id = (int)($data['payroll_id'] ?? 0);
        $patient_id = (int)($data['patient_id'] ?? 0);
        $worker_id  = (int)($data['worker_id'] ?? 0);
        $role_id    = (int)($data['role_id'] ?? 0);
        $rate       = isset($data['rate']) ? (float)$data['rate'] : null;

        if ($payroll_id <= 0 || $patient_id <= 0 || $worker_id <= 0 || $role_id <= 0)
            wp_send_json_error(['message' => 'Required Fields: payroll_id, patient_id, worker_id, role_id'], 400);

        $id = WorkerPatientRole::createTemporaryForPayroll($worker_id, $patient_id, $role_id, $payroll_id, $rate);
        if ($id instanceof \WP_Error) wp_send_json_error(['message' => $id->get_error_message()], 400);

        // devolver la lista actualizada (con effective_rate)
        $items = WorkerPatientRole::listForPatientInPayroll($patient_id, $payroll_id);
        wp_send_json_success(['created_id' => $id, 'items' => $items]);
    }

    /* ---------- HORAS (por paciente en el payroll) ---------- */

    // GET: payroll_id, patient_id
    public static function ajax_hours_list()
    {
        self::check();
        $payroll_id = (int)($_REQUEST['payroll_id'] ?? 0);
        $patient_id = (int)($_REQUEST['patient_id'] ?? 0);
        if ($payroll_id <= 0 || $patient_id <= 0) wp_send_json_error(['message' => 'payroll_id and patient_id are required'], 400);

        $rows = HoursEntry::listDetailedForPayroll($payroll_id, ['patient_id' => $patient_id]);
        // Totales por paciente (para header de la tarjeta)
        $totalsByPatient = HoursEntry::totalsByPatientForPayroll($payroll_id);
        $tp = array_values(array_filter($totalsByPatient, fn($r) => (int)$r['patient_id'] === $patient_id));
        $patientTotals = $tp[0] ?? ['total_hours' => 0, 'total_amount' => 0];
        wp_send_json_success(['items' => $rows, 'totals' => $patientTotals]);
    }

    // POST: payroll_id, worker_patient_role_id, hours, used_rate? (si no viene, calculamos)
    public static function ajax_hours_upsert()
    {
        self::check();
        $data = self::json_input();
        $payroll_id = (int)($data['payroll_id'] ?? 0);
        $segment_id = (int)($data['segment_id'] ?? 0);
        $wpr_id     = (int)($data['worker_patient_role_id'] ?? 0);
        $hours      = isset($data['hours']) ? (float)$data['hours'] : 0.0;
        $used_rate  = isset($data['used_rate']) ? (float)$data['used_rate'] : null;
        if ($payroll_id <= 0 || $wpr_id <= 0) wp_send_json_error(['message' => 'payroll_id and worker_patient_role_id are required'], 400);

        // Resolve used_rate si no viene
        if ($used_rate === null) {
            $wpr = WorkerPatientRole::findById($wpr_id);
            if (!$wpr) wp_send_json_error(['message' => 'Asignación (WPR) not found'], 404);
            $payroll = Payroll::findById($payroll_id);
            if (!$payroll) wp_send_json_error(['message' => 'Payroll not found'], 404);
            $used_rate = WorkerPatientRole::resolveEffectiveRate($wpr, (array)$payroll);
        }

        // Guarda/actualiza horas (idempotente por (payroll_id, wpr_id))
        $res = HoursEntry::setHours($segment_id, $wpr_id, $hours, $used_rate, null);
        if ($res instanceof \WP_Error) wp_send_json_error(['message' => $res->get_error_message()], 400);

        // Responder con la lista y totales actualizados para ese paciente
        $wprInfo = HoursEntry::getWprInfo($wpr_id); // trae patient_id
        $rows = HoursEntry::listDetailedForPayroll($payroll_id, ['patient_id' => $wprInfo['patient_id']]);
        $totalsByPatient = HoursEntry::totalsByPatientForPayroll($payroll_id);
        $tp = array_values(array_filter($totalsByPatient, fn($r) => (int)$r['patient_id'] === $wprInfo['patient_id']));
        $patientTotals = $tp[0] ?? ['total_hours' => 0, 'total_amount' => 0];

        wp_send_json_success([
            'saved'  => ['payroll_id' => $payroll_id, 'worker_patient_role_id' => $wpr_id, 'hours' => $hours, 'used_rate' => $used_rate],
            'items'  => $rows,
            'totals' => $patientTotals
        ]);
    }

    // POST: id (hours_entry id)
    public static function ajax_hours_delete()
    {
        self::check();
        $id = (int)($_REQUEST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Missing id'], 400);

        $row = HoursEntry::findById($id);
        if (!$row) wp_send_json_error(['message' => 'Not found'], 404);
        $wprInfo = HoursEntry::getWprInfo((int)$row->worker_patient_role_id);

        $ok = HoursEntry::delete($id);
        if ($ok instanceof \WP_Error || !$ok) wp_send_json_error(['message' => 'Could not delete'], 500);

        // Responder con lista y totales del paciente
        $rows = HoursEntry::listDetailedForPayroll((int)$row->payroll_id, ['patient_id' => $wprInfo['patient_id']]);
        $totalsByPatient = HoursEntry::totalsByPatientForPayroll((int)$row->payroll_id);
        $tp = array_values(array_filter($totalsByPatient, fn($r) => (int)$r['patient_id'] === $wprInfo['patient_id']));
        $patientTotals = $tp[0] ?? ['total_hours' => 0, 'total_amount' => 0];

        wp_send_json_success([
            'deleted' => (int)$id,
            'items'  => $rows,
            'totals' => $patientTotals
        ]);
    }

    public static function ajax_workers()
    {
        self::check();
        $payroll_id = (int)($_POST['payroll_id'] ?? 0);
        $search     = sanitize_text_field($_POST['search'] ?? ''); // ✅ capture correctly
        if ($payroll_id <= 0) wp_send_json_error(['message' => 'payroll_id required'], 400);

        // Totales por trabajador (horas)
        $hours = HoursEntry::totalsByWorkerForPayroll($payroll_id);
        // $hours[] = ['worker_id','worker_name','total_hours','total_amount']

        // Totales por trabajador (extras)
        $extras = ExtraPayment::totalsByWorkerForPayroll($payroll_id);
        // $extras[] = ['worker_id','total_amount','items']

        // Indexar por worker_id y unificar
        $map = [];
        foreach ($hours as $h) {
            $wid = (int)$h['worker_id'];
            $map[$wid] = [
                'worker_id'     => $wid,
                'worker_name'   => $h['worker_name'] ?? '',
                'company'       => $h['worker_company'] ?? '',
                'hours_hours'   => (float)$h['total_hours'],
                'hours_amount'  => (float)$h['total_amount'],
                'extras_amount' => 0.0,
                'extras_items'  => 0,
            ];
        }
        foreach ($extras as $e) {
            $wid = (int)$e['worker_id'];
            if (!isset($map[$wid])) {
                $map[$wid] = [
                    'worker_id'     => $wid,
                    'worker_name'   => '',
                    'hours_hours'   => 0.0,
                    'hours_amount'  => 0.0,
                    'extras_amount' => 0.0,
                    'extras_items'  => 0,
                ];
            }
            $map[$wid]['extras_amount'] = (float)$e['total_amount'];
            $map[$wid]['extras_items']  = (int)$e['items'];
        }

        // Rellenar nombres faltantes y company (trabajadores que solo tienen extras)
        $missing = array_map('intval', array_keys(array_filter($map, fn($r) => $r['worker_name'] === '')));
        if (!empty($missing)) {
            global $wpdb;
            $t = $wpdb->prefix . 'mhc_workers';
            // ✅ fix: remove stray AND in WHERE
            $ids = implode(',', array_map('intval', $missing));
            $rows = $wpdb->get_results("SELECT id, CONCAT(first_name,' ',last_name) AS name, company FROM {$t} WHERE id IN ($ids)", ARRAY_A);
            $names = [];
            foreach ($rows ?: [] as $r) $names[(int)$r['id']] = (string)$r['name'];
            foreach ($missing as $wid) {
                if (isset($names[$wid])) {
                    $map[$wid]['worker_name'] = $names[$wid];
                    $map[$wid]['company'] = $r['company'] ?? '';
                }
            }
        }

        // Formar salida base con grand_total
        $items = array_values(array_map(function ($r) {
            $r['grand_total'] = round((float)$r['hours_amount'] + (float)$r['extras_amount'], 2);
            return $r;
        }, $map));

        // 🔎 Aplicar búsqueda por nombre (case-insensitive) antes de ordenar y sumar
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $items = array_values(array_filter($items, function ($row) use ($needle) {
                return mb_stripos($row['worker_name'] ?? '', $needle) !== false;
            }));
        }

        // Ordenar por nombre
        usort($items, fn($a, $b) => strcasecmp($a['worker_name'], $b['worker_name']));

        // Totales globales del payroll (de la lista ya filtrada)
        $sum_hours_amount  = array_sum(array_column($items, 'hours_amount'));
        $sum_extras_amount = array_sum(array_column($items, 'extras_amount'));
        $sum_grand_total   = $sum_hours_amount + $sum_extras_amount;

        wp_send_json_success([
            'items' => $items,
            'totals' => [
                'hours_amount'  => round($sum_hours_amount, 2),
                'extras_amount' => round($sum_extras_amount, 2),
                'grand_total'   => round($sum_grand_total, 2),
            ],
        ]);
    }

    public static function ajax_worker_detail()
    {
        self::check();
        $payroll_id = (int)($_POST['payroll_id'] ?? 0);
        $worker_id  = (int)($_POST['worker_id'] ?? 0);
        if ($payroll_id <= 0 || $worker_id <= 0) wp_send_json_error(['message' => 'payroll_id and worker_id are required'], 400);

        // Horas detalladas de ese worker en el payroll (por paciente/rol)
        $hours = HoursEntry::listDetailedForPayroll($payroll_id, ['worker_id' => $worker_id]);
        // Extras detallados de ese worker en el payroll
        $extras = ExtraPayment::listDetailedForPayroll($payroll_id, ['worker_id' => $worker_id]);

        // Totales
        $th = 0.0;
        $ta = 0.0;
        $te = 0.0;
        foreach ($hours as $h) {
            $th += (float)$h->hours;
            $ta += (float)$h->total;
        }
        foreach ($extras as $e) {
            $te += (float)$e->amount;
        }

        // nombre del worker and company
        $worker_name = '';
        $company = '';
        if (!empty($hours)) {
            $worker_name = $hours[0]->worker_name ?? '';
            $company = $hours[0]->worker_company ?? '';
        }
        if ($worker_name === '') {
            global $wpdb;
            $t = $wpdb->prefix . 'mhc_workers';
            $worker_name = (string)$wpdb->get_var($wpdb->prepare("SELECT CONCAT(first_name,' ',last_name) FROM {$t} WHERE id=%d", $worker_id));
            $company = (string)$wpdb->get_var($wpdb->prepare("SELECT company FROM {$t} WHERE id=%d", $worker_id));
        }

        wp_send_json_success([
            'worker' => [
                'worker_id'   => $worker_id,
                'worker_name' => $worker_name,
                'company'     => $company,
            ],
            'hours'  => $hours,   // cada item: patient_name, role_code, hours, used_rate, total...
            'extras' => $extras,  // cada item: code, label, unit_rate, amount, notes...
            'totals' => [
                'total_hours'   => round($th, 2),
                'hours_amount'  => round($ta, 2),
                'extras_amount' => round($te, 2),
                'grand_total'   => round($ta + $te, 2),
            ]
        ]);
    }

    public static function ajax_special_rates_list()
    {
        self::check();
        global $wpdb;
        $t = $wpdb->prefix . 'mhc_special_rates';
        $q = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';

        $sql = "SELECT id, code, label, cpt_code, unit_rate FROM {$t} WHERE is_active=1";
        $params = [];
        if ($q !== '') {
            $sql .= " AND (code LIKE %s OR label LIKE %s)";
            $like = '%' . $wpdb->esc_like($q) . '%';
            $params = [$like, $like];
        }
        $sql .= " ORDER BY label ASC";

        $items = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);

        // normaliza tipos
        foreach ($items as &$i) $i['unit_rate'] = (float)$i['unit_rate'];
        wp_send_json_success(['items' => $items]);
    }

    public static function ajax_extras_list()
    {
        self::check();
        $payroll_id = (int)($_POST['payroll_id'] ?? 0);
        $worker_id  = (int)($_POST['worker_id'] ?? 0); // opcional: si no llega, lista todos
        if ($payroll_id <= 0) wp_send_json_error(['message' => 'payroll_id required'], 400);

        $filters = ['payroll_id' => $payroll_id];
        if ($worker_id > 0) $filters['worker_id'] = $worker_id;

        $rows = ExtraPayment::listDetailedForPayroll($payroll_id, $filters);
        wp_send_json_success(['items' => $rows]);
    }

    public static function ajax_extras_create()
    {
        self::check();

        $payroll_id  = (int)($_POST['payroll_id'] ?? 0);
        $worker_id   = (int)($_POST['worker_id'] ?? 0);
        $rate_id     = (int)($_POST['special_rate_id'] ?? 0);
        $amount      = isset($_POST['amount']) ? round((float)$_POST['amount'], 2) : null;
        $patient_id  = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : null; // opcional
        $supervised  = isset($_POST['supervised_worker_id']) ? (int)$_POST['supervised_worker_id'] : null; // opcional
        $notes       = isset($_POST['notes']) ? sanitize_text_field(wp_unslash($_POST['notes'])) : '';

        if ($payroll_id <= 0 || $worker_id <= 0 || $rate_id <= 0 || $amount === null)
            wp_send_json_error(['message' => 'payroll_id, worker_id, special_rate_id y amount are required'], 400);

        // Obtener el código del special rate
        $special_rate = \Mhc\Inc\Models\SpecialRate::findById($rate_id);
        if ($special_rate && isset($special_rate['code']) && $special_rate['code'] === 'pending_negative' && $amount > 0) {
            $amount = -1 * $amount;
        }

        $id = ExtraPayment::create([
            'payroll_id'          => $payroll_id,
            'worker_id'           => $worker_id,
            'special_rate_id'     => $rate_id,
            'amount'              => $amount,
            'patient_id'          => $patient_id,
            'supervised_worker_id' => $supervised,
            'notes'               => $notes,
        ]);
        if ($id instanceof \WP_Error) wp_send_json_error(['message' => $id->get_error_message()], 400);

        // devolver lista actualizada del worker
        $rows = ExtraPayment::listDetailedForPayroll($payroll_id, ['worker_id' => $worker_id]);
        wp_send_json_success(['id' => (int)$id, 'items' => $rows]);
    }

    public static function ajax_extras_update()
    {
        self::check();
        $id          = (int)($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'id required'], 400);


        $upd = [];
        foreach (['payroll_id', 'worker_id', 'patient_id', 'supervised_worker_id', 'special_rate_id'] as $k) {
            if (isset($_POST[$k])) $upd[$k] = (int)$_POST[$k];
        }
        if (isset($_POST['amount'])) $upd['amount'] = round((float)$_POST['amount'], 2);
        if (isset($_POST['notes']))  $upd['notes']  = sanitize_text_field(wp_unslash($_POST['notes']));

        // Si el special_rate_id está en el update, úsalo; si no, busca el actual
        $rate_id = isset($upd['special_rate_id']) ? $upd['special_rate_id'] : null;
        if ($rate_id === null) {
            // Buscar el rate_id actual en la BD
            global $wpdb;
            $t = $wpdb->prefix . 'mhc_extra_payments';
            $rate_id = (int)$wpdb->get_var($wpdb->prepare("SELECT special_rate_id FROM {$t} WHERE id=%d", $id));
        }
        // Obtener el código del special rate
        $special_rate = \Mhc\Inc\Models\SpecialRate::findById($rate_id);
        if ($special_rate && isset($special_rate['code']) && $special_rate['code'] === 'pending_negative' && isset($upd['amount']) && $upd['amount'] > 0) {
            $upd['amount'] = -1 * $upd['amount'];
        }

        if (empty($upd)) wp_send_json_error(['message' => 'Nothing to update'], 400);

        $ok = ExtraPayment::update($id, $upd);
        if ($ok instanceof \WP_Error || !$ok) wp_send_json_error(['message' => 'Could not update'], 500);

        // devolver el registro actualizado
        global $wpdb;
        $t = $wpdb->prefix . 'mhc_extra_payments';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $id));
        wp_send_json_success(['updated' => true, 'item' => $row]);
    }

    public static function ajax_extras_delete()
    {
        self::check();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'id requerido'], 400);

        $ok = ExtraPayment::delete($id);
        if ($ok instanceof \WP_Error || !$ok) wp_send_json_error(['message' => 'Could not delete'], 500);
        wp_send_json_success(['deleted' => (int)$id]);
    }

    /**
     * AJAX endpoint: Send all worker slip PDFs for a payroll to each worker's email.
     * POST: payroll_id
     */
    public static function ajax_send_all_slips()
    {
        self::check();
        $payroll_id = (int)($_POST['payroll_id'] ?? 0);
        if ($payroll_id <= 0) wp_send_json_error(['message' => 'payroll_id required'], 400);

        global $wpdb;
        $t = $wpdb->prefix . 'mhc_workers';
        $workers = $wpdb->get_results($wpdb->prepare("SELECT w.id, w.email, CONCAT(w.first_name,' ',w.last_name) AS name FROM {$t} w WHERE w.is_active=1 AND w.email <> ''"), ARRAY_A);
        if (!$workers) wp_send_json_error(['message' => 'No workers found'], 404);

        $sent = [];
        foreach ($workers as $worker) {
            $worker_id = (int)$worker['id'];
            $email = $worker['email'];
            $name = $worker['name'];
            // PDF generation is centralized in PdfController
            $pdfPath = \Mhc\Inc\Controllers\PdfController::generateWorkerSlipPdf([
                'payroll_id' => $payroll_id,
                'worker_id' => $worker_id,
                'worker_name' => $name
            ]);
            if (!file_exists($pdfPath)) continue;

            $logo_path = dirname(__DIR__, 3) . '/assets/img/mentalhelt.png';
            $result = mhc_send_email(
                $email,
                'Hello ' . esc_html($name),
                'Your Payroll Slip',
                'Attached is your payroll slip PDF.',
                [$pdfPath],
                $logo_path
            );
            if ($result) $sent[] = $worker_id;
            unlink($pdfPath);
        }
        wp_send_json_success(['sent' => $sent, 'total' => count($sent)]);
    }

    /**
     * AJAX endpoint: Send a specific worker's slip PDF to their email.
     * POST: payroll_id, worker_id
     */
    public static function ajax_send_worker_slip()
    {
        self::check();
        $payroll_id = (int)($_POST['payroll_id'] ?? 0);
        $worker_id  = (int)($_POST['worker_id'] ?? 0);
        if ($payroll_id <= 0 || $worker_id <= 0) wp_send_json_error(['message' => 'payroll_id and worker_id required'], 400);

        global $wpdb;
        $t = $wpdb->prefix . 'mhc_workers';
        $worker = $wpdb->get_row($wpdb->prepare("SELECT email, CONCAT(first_name,' ',last_name) AS name FROM {$t} WHERE id=%d AND is_active=1 AND email <> ''", $worker_id), ARRAY_A);
        if (!$worker) wp_send_json_error(['message' => 'Worker not found or no email'], 404);
        $email = $worker['email'];
        $name = $worker['name'];
        // PDF generation is centralized in PdfController
        $pdfPath = \Mhc\Inc\Controllers\PdfController::generateWorkerSlipPdf([
            'payroll_id' => $payroll_id,
            'worker_id' => $worker_id,
            'worker_name' => $name
        ]);
        if (!file_exists($pdfPath)) wp_send_json_error(['message' => 'PDF generation failed'], 500);

        $logo_path = dirname(__DIR__, 3) . '/assets/img/mentalhelt.png';
        $result = mhc_send_email(
            $email,
            'Hello ' . esc_html($name),
            'Your Payroll Slip',
            'Attached is your payroll slip PDF.',
            [$pdfPath],
            $logo_path
        );
        unlink($pdfPath);
        if ($result) {
            wp_send_json_success(['sent' => true, 'worker_id' => $worker_id]);
        } else {
            wp_send_json_error(['message' => 'Email sending failed'], 500);
        }
    }
}
