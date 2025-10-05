<?php

namespace Mhc\Inc\Models;

class WorkerPatientRole
{
    private static function tWPR(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mhc_worker_patient_roles';
    }
    private static function tWR(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mhc_worker_roles';
    }
    private static function tW(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mhc_workers';
    }
    private static function tP(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mhc_patients';
    }
    private static function tR(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mhc_roles';
    }
    private static function tPR(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mhc_payrolls';
    }
    private static function now(): string
    {
        return current_time('mysql');
    }

    public static function findById(int $id)
    {
        global $wpdb;
        $t = self::tWPR();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", $id), ARRAY_A);
    }

    /** General rate vigente (worker, role) en una fecha */
    public static function resolveGeneralRate(int $workerId, int $roleId, string $refDate): ?float
    {
        global $wpdb;
        $t = self::tWR();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT general_rate FROM {$t}
             WHERE worker_id=%d AND role_id=%d
               AND start_date <= %s
               AND (end_date IS NULL OR end_date >= %s)
             ORDER BY start_date DESC
             LIMIT 1",
            $workerId,
            $roleId,
            $refDate,
            $refDate
        ));
        return $row ? (float)$row->general_rate : null;
    }

    /** Tarifa efectiva: primero WPR.rate, si no -> general_rate vigente en start_date del payroll */
    public static function resolveEffectiveRate(array $wprRow, array $payrollRow): ?float
    {
        if (!empty($wprRow['rate'])) return (float)$wprRow['rate'];
        $date = $payrollRow['start_date'];
        return self::resolveGeneralRate((int)$wprRow['worker_id'], (int)$wprRow['role_id'], $date) ?? 0.0;
    }

    /** Lista de asignaciones relevantes para un (patient, payroll):
     *  - por defecto: end_date IS NULL
     *  - temporales: que se solapen con [payroll.start_date, payroll.end_date]
     *  Incluye nombres y tarifa efectiva.
     */
    public static function listForPatientInPayroll(int $patientId, int $payrollId): array
    {
        global $wpdb;

        $t   = self::tWPR();
        $tw  = self::tW();
        $tr  = self::tR();
        $tpr = self::tPR();

        // new tables
        $tseg = $wpdb->prefix . 'mhc_payroll_segments';
        $th   = $wpdb->prefix . 'mhc_hours_entries';

        // 1) Payroll header
        $payroll = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tpr} WHERE id=%d", $payrollId), ARRAY_A);
        if (!$payroll) return [];

        $s = $payroll['start_date'];
        $e = $payroll['end_date'];

        // 2) Segments for this payroll (ordered)
        $segments = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, segment_start, segment_end 
               FROM {$tseg}
              WHERE payroll_id = %d
              ORDER BY segment_start ASC, id ASC",
                $payrollId
            ),
            ARRAY_A
        ) ?: [];

        $segIds = array_map('intval', array_column($segments, 'id'));
        //3.5) get all wpr_ids from hours for this segments and this patient
        // (to include them even if outside the period)
        $hoursRows = [];
        if (!empty($segIds)) {
            $segPh = implode(',', array_fill(0, count($segIds), '%d'));
            $sql = "SELECT DISTINCT he.worker_patient_role_id
                  FROM {$th} AS he
                 JOIN {$t}  AS wpr ON wpr.id = he.worker_patient_role_id
                 WHERE he.segment_id IN ($segPh)
                   AND wpr.patient_id = %d";
            $params = array_merge($segIds, [$patientId]);
            $hoursRows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];
        }
        $wprIdsWithHours = array_unique(array_map('intval', array_column($hoursRows, 'worker_patient_role_id')));

        // 3.5) Include WPRs with hours in this payroll even if outside period
        $extraWprCondition = '';
        if (!empty($wprIdsWithHours)) {
            $extraWprCondition = ' OR wpr.id IN (' . implode(',', array_map('intval', $wprIdsWithHours)) . ') ';
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT wpr.*,
            MAX(w.first_name) AS w_fn, MAX(w.last_name) AS w_ln,
            MAX(r.code) AS role_code, MAX(r.name) AS role_name
            FROM {$t}   AS wpr
            JOIN {$tw}  AS w   ON w.id  = wpr.worker_id
            JOIN {$tr}  AS r   ON r.id  = wpr.role_id
            WHERE (wpr.patient_id=%d
                AND (
                    wpr.end_date IS NULL
                OR (wpr.start_date <= %s AND (wpr.end_date IS NULL OR wpr.end_date >= %s))
                ) AND wpr.deleted_at IS NULL
            ) $extraWprCondition
            GROUP BY wpr.id
            ORDER BY wpr.id ASC, w_ln ASC, w_fn ASC, role_name ASC",
                        $patientId,
                        $e,
                        $s
                    ),
            ARRAY_A
        ) ?: [];


        if (empty($rows)) return [];

        // 4) Compute effective names/rates
        foreach ($rows as &$row) {
            $row['worker_name']    = trim(($row['w_fn'] ?? '') . ' ' . ($row['w_ln'] ?? ''));
            $row['effective_rate'] = self::resolveEffectiveRate($row, $payroll);
        }
        unset($row);

        // 5) Map hours entries by (wpr_id, segment_id) for this payroll
        $wprIds = array_map('intval', array_column($rows, 'id'));


        $byKey = []; // "$wprId:$segId" => hours row
        if (!empty($wprIds) && !empty($segIds)) {
            $wprPh = implode(',', array_fill(0, count($wprIds), '%d'));
            $segPh = implode(',', array_fill(0, count($segIds), '%d'));

            // Only pull entries that belong to segments of this payroll
            $sql = "SELECT he.id, he.worker_patient_role_id, he.segment_id, he.hours, he.used_rate, he.total
                  FROM {$th} AS he
                 WHERE he.worker_patient_role_id IN ($wprPh)
                   AND he.segment_id IN ($segPh)";

            $params = array_merge($wprIds, $segIds);
            $hoursRows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: [];

            foreach ($hoursRows as $hr) {
                $k = ((int)$hr['worker_patient_role_id']) . ':' . ((int)$hr['segment_id']);
                $byKey[$k] = $hr;
            }
        }

        // 6) Attach per-segment values to each WPR row
        foreach ($rows as &$row) {
            $wprId = (int)$row['id'];
            $row['segments'] = [];

            foreach ($segments as $seg) {
                $segId = (int)$seg['id'];
                $k     = $wprId . ':' . $segId;
                $hr    = $byKey[$k] ?? null;

                $row['effective_rate'] = (isset($hr['used_rate']) && (float)$hr['used_rate'] > 0) ? (float)$hr['used_rate'] : $row['effective_rate'];

                $row['segments'][] = [
                    'segment_id'    => $segId,
                    'segment_start' => $seg['segment_start'],
                    'segment_end'   => $seg['segment_end'],
                    'hours'         => isset($hr['hours']) ? (float)$hr['hours'] : 0.0,
                    'entry_id'      => isset($hr['id']) ? (int)$hr['id'] : null,
                ];
            }
        }
        unset($row);

        return $rows;
    }

    /** Crea una asignación temporal SOLO para este payroll (start=end del payroll rango) */
    public static function createTemporaryForPayroll(int $workerId, int $patientId, int $roleId, int $payrollId, ?float $rate = null)
    {
        global $wpdb;
        $t = self::tWPR();
        $tpr = self::tPR();
        $p = $wpdb->get_row($wpdb->prepare("SELECT start_date,end_date FROM {$tpr} WHERE id=%d", $payrollId), ARRAY_A);
        if (!$p) return new \WP_Error('not_found', 'Payroll no encontrado');

        $payload = [
            'worker_id'  => $workerId,
            'patient_id' => $patientId,
            'role_id'    => $roleId,
            'rate'       => isset($rate) ? round((float)$rate, 2) : null,
            'start_date' => $p['start_date'],
            'end_date'   => $p['end_date'],
            'created_at' => self::now(),
        ];
        $fmt = ['%d', '%d', '%d', '%f', '%s', '%s', '%s'];
        if ($payload['rate'] === null) { // WPDB no maneja NULL con %f -> hagamos 2 pasos
            unset($payload['rate']);
            $fmt = ['%d', '%d', '%d', '%s', '%s', '%s'];
        }

        $ok = $wpdb->insert($t, $payload, $fmt);
        if ($ok === false) return new \WP_Error('db_insert_failed', 'No se pudo crear asignación temporal.');
        return (int)$wpdb->insert_id;
    }
}
