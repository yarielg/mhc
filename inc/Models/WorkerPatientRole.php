<?php
namespace Mhc\Inc\Models;

class WorkerPatientRole
{
    private static function tWPR(): string { global $wpdb; return $wpdb->prefix.'mhc_worker_patient_roles'; }
    private static function tWR(): string  { global $wpdb; return $wpdb->prefix.'mhc_worker_roles'; }
    private static function tW(): string   { global $wpdb; return $wpdb->prefix.'mhc_workers'; }
    private static function tP(): string   { global $wpdb; return $wpdb->prefix.'mhc_patients'; }
    private static function tR(): string   { global $wpdb; return $wpdb->prefix.'mhc_roles'; }
    private static function tPR(): string  { global $wpdb; return $wpdb->prefix.'mhc_payrolls'; }
    private static function now(): string { return current_time('mysql'); }

    public static function findById(int $id) {
        global $wpdb; $t=self::tWPR();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $id), ARRAY_A);
    }

    /** General rate vigente (worker, role) en una fecha */
    public static function resolveGeneralRate(int $workerId, int $roleId, string $refDate): ?float {
        global $wpdb; $t=self::tWR();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT general_rate FROM {$t}
             WHERE worker_id=%d AND role_id=%d
               AND start_date <= %s
               AND (end_date IS NULL OR end_date >= %s)
             ORDER BY start_date DESC
             LIMIT 1",
            $workerId, $roleId, $refDate, $refDate
        ));
        return $row ? (float)$row->general_rate : null;
    }

    /** Tarifa efectiva: primero WPR.rate, si no -> general_rate vigente en start_date del payroll */
    public static function resolveEffectiveRate(array $wprRow, array $payrollRow): ?float {
        if (!empty($wprRow['rate'])) return (float)$wprRow['rate'];
        $date = $payrollRow['start_date'];
        return self::resolveGeneralRate((int)$wprRow['worker_id'], (int)$wprRow['role_id'], $date) ?? 0.0;
    }

    /** Lista de asignaciones relevantes para un (patient, payroll):
     *  - por defecto: end_date IS NULL
     *  - temporales: que se solapen con [payroll.start_date, payroll.end_date]
     *  Incluye nombres y tarifa efectiva.
     */
    public static function listForPatientInPayroll(int $patientId, int $payrollId): array {
        global $wpdb;
        $t = self::tWPR(); $tw=self::tW(); $tr=self::tR(); $tp=self::tP(); $tpr=self::tPR();

        $payroll = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tpr} WHERE id=%d", $payrollId), ARRAY_A);
        if (!$payroll) return [];

        $s = $payroll['start_date']; $e = $payroll['end_date'];

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT wpr.*, w.first_name AS w_fn, w.last_name AS w_ln, r.code AS role_code, r.name AS role_name
               FROM {$t} wpr
               JOIN {$tw} w ON w.id = wpr.worker_id
               JOIN {$tr} r ON r.id = wpr.role_id
              WHERE wpr.patient_id=%d
                AND (
                    wpr.end_date IS NULL
                    OR (wpr.start_date <= %s AND (wpr.end_date IS NULL OR wpr.end_date >= %s))
                )
              ORDER BY w_ln ASC, w_fn ASC, r.name ASC",
            $patientId, $e, $s
        ), ARRAY_A) ?: [];

        // completa tarifa efectiva
        foreach ($rows as &$row) {
            $row['worker_name'] = trim(($row['w_fn'] ?? '').' '.($row['w_ln'] ?? ''));
            $row['effective_rate'] = self::resolveEffectiveRate($row, $payroll);
        }
        return $rows;
    }

    /** Crea una asignación temporal SOLO para este payroll (start=end del payroll rango) */
    public static function createTemporaryForPayroll(int $workerId, int $patientId, int $roleId, int $payrollId, ?float $rate = null) {
        global $wpdb;
        $t = self::tWPR(); $tpr=self::tPR();
        $p = $wpdb->get_row($wpdb->prepare("SELECT start_date,end_date FROM {$tpr} WHERE id=%d", $payrollId), ARRAY_A);
        if (!$p) return new \WP_Error('not_found','Payroll no encontrado');

        $payload = [
            'worker_id'  => $workerId,
            'patient_id' => $patientId,
            'role_id'    => $roleId,
            'rate'       => isset($rate) ? round((float)$rate, 2) : null,
            'start_date' => $p['start_date'],
            'end_date'   => $p['end_date'],
            'created_at' => self::now(),
        ];
        $fmt = ['%d','%d','%d','%f','%s','%s','%s'];
        if ($payload['rate'] === null) { // WPDB no maneja NULL con %f -> hagamos 2 pasos
            unset($payload['rate']);
            $fmt = ['%d','%d','%d','%s','%s','%s'];
        }

        $ok = $wpdb->insert($t, $payload, $fmt);
        if ($ok === false) return new \WP_Error('db_insert_failed','No se pudo crear asignación temporal.');
        return (int)$wpdb->insert_id;
    }
}
