<?php
namespace Mhc\Inc\Models;

use WP_Error;

class HoursEntry
{
    /** Tablas */
    private static function table(): string {
        global $wpdb; return $wpdb->prefix . 'mhc_hours_entries';
    }
    private static function tableSegments(): string {
        global $wpdb; return $wpdb->prefix . 'mhc_payroll_segments';
    }
    private static function tableWPR(): string {
        global $wpdb; return $wpdb->prefix . 'mhc_worker_patient_roles';
    }
    private static function tableWorkers(): string {
        global $wpdb; return $wpdb->prefix . 'mhc_workers';
    }
    private static function tablePatients(): string {
        global $wpdb; return $wpdb->prefix . 'mhc_patients';
    }
    private static function tableRoles(): string {
        global $wpdb; return $wpdb->prefix . 'mhc_roles';
    }

    private static function now(): string { return current_time('mysql'); }

    /** Normaliza tipos y recalcula total por seguridad */
    private static function normalizeRow($row) {
        if (!$row) return null;
    $row->id                       = (int)$row->id;
    $row->segment_id               = (int)$row->segment_id;
    $row->worker_patient_role_id   = (int)$row->worker_patient_role_id;
    $row->hours                    = (float)$row->hours;
    $row->used_rate                = (float)$row->used_rate;
    $row->total                    = (float)$row->total;
        // Si viene del JOIN, normaliza extras
        foreach (['worker_id','patient_id','role_id'] as $k) {
            if (isset($row->$k)) $row->$k = (int)$row->$k;
        }
        foreach (['worker_name','patient_name','role_code','role_name'] as $k) {
            if (isset($row->$k)) $row->$k = (string)$row->$k;
        }
        // sanity: mantener total consistente
        $calc = round($row->hours * $row->used_rate, 2);
        if (abs($calc - $row->total) >= 0.01) $row->total = $calc;
        return $row;
    }

    /* =================== CRUD =================== */

    public static function findById($id) {
        global $wpdb;
        $t = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", absint($id)));
        return self::normalizeRow($row);
    }

    /**
     * findAll con filtros:
     * - payroll_id, worker_patient_role_id
     * - worker_id, patient_id, role_id (por JOIN a mhc_worker_patient_roles)
     * - orderby: id|payroll_id|worker_patient_role_id|hours|used_rate|total|created_at|updated_at
     * - order: ASC|DESC
     * - limit, offset
     */
    public static function findAll($args = []) {
        global $wpdb;
        $t = self::table();
        $wpr = self::tableWPR();
        $seg = self::tableSegments();

        $where = [];
        $params = [];

        if (!empty($args['segment_id'])) {
            $where[] = "he.segment_id=%d";
            $params[] = absint($args['segment_id']);
        }
        if (!empty($args['payroll_id'])) {
            $where[] = "seg.payroll_id=%d";
            $params[] = absint($args['payroll_id']);
        }
        if (!empty($args['worker_patient_role_id'])) {
            $where[] = "he.worker_patient_role_id=%d";
            $params[] = absint($args['worker_patient_role_id']);
        }
        if (!empty($args['worker_id'])) {
            $where[] = "wpr.worker_id=%d";
            $params[] = absint($args['worker_id']);
        }
        if (!empty($args['patient_id'])) {
            $where[] = "wpr.patient_id=%d";
            $params[] = absint($args['patient_id']);
        }
        if (!empty($args['role_id'])) {
            $where[] = "wpr.role_id=%d";
            $params[] = absint($args['role_id']);
        }

        $sql = "
            SELECT he.*
            FROM {$t} he
            JOIN {$wpr} wpr ON wpr.id = he.worker_patient_role_id
            JOIN {$seg} seg ON seg.id = he.segment_id
        ";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);

        $allowedOrderBy = ['id','segment_id','worker_patient_role_id','hours','used_rate','total','created_at','updated_at'];
        $orderby = (isset($args['orderby']) && in_array($args['orderby'], $allowedOrderBy, true)) ? $args['orderby'] : 'id';
        $order   = (isset($args['order']) && strtoupper($args['order']) === 'ASC') ? 'ASC' : 'DESC';
        $sql .= " ORDER BY he.{$orderby} {$order}";

        if (isset($args['limit'])) {
            $limit = max(1, (int)$args['limit']);
            $sql .= " LIMIT {$limit}";
            if (isset($args['offset'])) {
                $offset = max(0, (int)$args['offset']);
                $sql .= " OFFSET {$offset}";
            }
        }

        if ($params) $sql = $wpdb->prepare($sql, ...$params);
        $rows = $wpdb->get_results($sql);
        return array_map([__CLASS__, 'normalizeRow'], $rows ?: []);
    }

    /**
     * create: inserta una línea de horas.
     * Siempre recalcula total = hours * used_rate en el servidor.
     */
    public static function create($data) {
        global $wpdb;
        $t = self::table();

        $payload = [
            'segment_id'             => isset($data['segment_id']) ? absint($data['segment_id']) : 0,
            'worker_patient_role_id' => isset($data['worker_patient_role_id']) ? absint($data['worker_patient_role_id']) : 0,
            'hours'                  => isset($data['hours']) ? (float)$data['hours'] : 0.0,
            'used_rate'              => isset($data['used_rate']) ? (float)$data['used_rate'] : 0.0,
            'created_at'             => self::now(),
            'updated_at'             => self::now(),
        ];
        if ($payload['segment_id'] <= 0 || $payload['worker_patient_role_id'] <= 0) {
            return new WP_Error('invalid_data', 'Faltan segment_id o worker_patient_role_id.');
        }
        // total server-side
        $payload['total'] = round($payload['hours'] * $payload['used_rate'], 2);

        $ok = $wpdb->insert($t, $payload, ['%d','%d','%f','%f','%s','%s','%f']);
        if ($ok === false) return new WP_Error('db_insert_failed', 'No se pudo crear la entrada de horas.');

        return (int)$wpdb->insert_id;
    }

    /**
     * update: permite cambiar horas/rate (recalcula total)
     */
    public static function update($id, $data) {
        global $wpdb;
        $t = self::table();
        $id = absint($id);

        $set = [];
        $fmt = [];

        if (array_key_exists('segment_id', $data)) { $set['segment_id'] = absint($data['segment_id']); $fmt[] = '%d'; }
        if (array_key_exists('worker_patient_role_id', $data)) { $set['worker_patient_role_id'] = absint($data['worker_patient_role_id']); $fmt[] = '%d'; }
        if (array_key_exists('hours', $data)) { $set['hours'] = (float)$data['hours']; $fmt[] = '%f'; }
        if (array_key_exists('used_rate', $data)) { $set['used_rate'] = (float)$data['used_rate']; $fmt[] = '%f'; }

        // Si cambian horas o rate, recalcula total
        if (isset($set['hours']) || isset($set['used_rate'])) {
            // Si no vienen ambos, tomar los actuales para calcular total correcto
            $row = self::findById($id);
            if ($row) {
                $hours = isset($set['hours']) ? (float)$set['hours'] : (float)$row->hours;
                $rate  = isset($set['used_rate']) ? (float)$set['used_rate'] : (float)$row->used_rate;
                $set['total'] = round($hours * $rate, 2);
                $fmt[] = '%f';
            }
        }

        if (empty($set)) return true;

        $set['updated_at'] = self::now(); $fmt[] = '%s';

        $ok = $wpdb->update($t, $set, ['id'=>$id], $fmt, ['%d']);
        if ($ok === false) return new WP_Error('db_update_failed', 'No se pudo actualizar la entrada de horas.');
        return true;
    }

    public static function delete($id) {
        global $wpdb;
        $t = self::table();
        $ok = $wpdb->delete($t, ['id'=>absint($id)], ['%d']);
        if ($ok === false) return new WP_Error('db_delete_failed', 'No se pudo eliminar la entrada de horas.');
        return (bool)$ok;
    }

    /* =============== Helpers útiles para Payroll =============== */

    /**
     * Upsert por (segment_id, worker_patient_role_id).
     * Si existe, actualiza horas/rate; si no, inserta.
     * Retorna true|WP_Error. Opcionalmente valida máximo de horas por paciente/segmento.
     */
    public static function setHours(int $segmentId, int $wprId, float $hours, float $usedRate, ?float $maxHoursPerPatient = null) {
        global $wpdb;
        $t   = self::table();
        $now = self::now();

        // (opcional) validar máximo por paciente en ese segmento (30h típicamente)
        if ($maxHoursPerPatient !== null) {
            $info = self::getWprInfo($wprId);
            if ($info) {
                $current = self::hoursForPatientInSegment($segmentId, $info['patient_id']);
                $futureTotal = $current - self::hoursForWprInSegment($segmentId, $wprId) + $hours;
                if ($futureTotal > $maxHoursPerPatient + 1e-6) {
                    return new WP_Error('hours_limit', 'Se excede el máximo de horas para el paciente en este segmento.');
                }
            }
        }

        $exists = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$t} WHERE segment_id=%d AND worker_patient_role_id=%d",
            $segmentId, $wprId
        ));

        $total = round($hours * $usedRate, 2);

        if ($exists) {
            $ok = $wpdb->update($t, [
                'hours'     => $hours,
                'used_rate' => $usedRate,
                'total'     => $total,
                'updated_at'=> $now
            ], ['id'=>$exists], ['%f','%f','%f','%s'], ['%d']);
            if ($ok === false) return new WP_Error('db_update_failed', 'No se pudo actualizar horas.');
            return true;
        } else {
            $ok = $wpdb->insert($t, [
                'segment_id'             => $segmentId,
                'worker_patient_role_id' => $wprId,
                'hours'                  => $hours,
                'used_rate'              => $usedRate,
                'total'                  => $total,
                'created_at'             => $now,
                'updated_at'             => $now,
            ], ['%d','%d','%f','%f','%f','%s','%s']);
            if ($ok === false) return new WP_Error('db_insert_failed', 'No se pudo insertar horas.');
            return true;
        }
    }

    /** Info básica del WPR (worker_id, patient_id, role_id) */
    public static function getWprInfo(int $wprId): ?array {
        global $wpdb;
        $wpr = self::tableWPR();
        $row = $wpdb->get_row($wpdb->prepare("SELECT worker_id, patient_id, role_id FROM {$wpr} WHERE id=%d", $wprId), ARRAY_A);
        if (!$row) return null;
        return ['worker_id'=>(int)$row['worker_id'], 'patient_id'=>(int)$row['patient_id'], 'role_id'=>(int)$row['role_id']];
    }

    /** Horas acumuladas de un WPR en un segmento */
    public static function hoursForWprInSegment(int $segmentId, int $wprId): float {
        global $wpdb;
        $t = self::table();
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(hours),0) FROM {$t} WHERE segment_id=%d AND worker_patient_role_id=%d",
            $segmentId, $wprId
        ));
        return (float)$val;
    }
     /** Horas acumuladas de un WPR en un Payroll */
    public static function hoursForWprInPayroll(int $payrollId, int $wprId): float {
        global $wpdb;
        $t = self::table();
        $seg = self::tableSegments();
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(he.hours),0)
             FROM {$t} he
             JOIN {$seg} seg ON seg.id=he.segment_id
             WHERE seg.payroll_id=%d AND he.worker_patient_role_id=%d",
            $payrollId, $wprId
        ));
        return (float)$val;
    }

    /** Horas acumuladas por paciente en un segmento (suma todos sus WPR) */
    public static function hoursForPatientInSegment(int $segmentId, int $patientId): float {
        global $wpdb;
        $t = self::table();
        $wpr = self::tableWPR();
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(he.hours),0)
             FROM {$t} he
             JOIN {$wpr} wpr ON wpr.id=he.worker_patient_role_id
             WHERE he.segment_id=%d AND wpr.patient_id=%d",
            $segmentId, $patientId
        ));
        return (float)$val;
    }

    /** Horas acumuladas por paciente en un payroll (suma todos sus WPR) */
    public static function hoursForPatientInPayroll(int $payrollId, int $patientId): float {
        global $wpdb;
        $t = self::table();
        $wpr = self::tableWPR();
        $seg = self::tableSegments();
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(he.hours),0)
             FROM {$t} he
             JOIN {$wpr} wpr ON wpr.id=he.worker_patient_role_id
             JOIN {$seg} seg ON seg.id=he.segment_id
             WHERE seg.payroll_id=%d AND wpr.patient_id=%d",
            $payrollId, $patientId
        ));
        return (float)$val;
    }

    /**
     * Listado detallado (JOIN) para una grilla:
     * Devuelve worker/patient/role + nombres y códigos.
     * Ahora filtra por segmento o por payroll (JOIN a segmentos).
     */
    public static function listDetailedForSegment(int $segmentId, array $filters = []): array {
        global $wpdb;
        $t   = self::table();
        $wpr = self::tableWPR();
        $wk  = self::tableWorkers();
        $pt  = self::tablePatients();
        $rl  = self::tableRoles();

        $where  = ["he.segment_id=%d"];
        $params = [$segmentId];

        if (!empty($filters['worker_id'])) { $where[] = "wpr.worker_id=%d"; $params[] = absint($filters['worker_id']); }
        if (!empty($filters['patient_id'])) { $where[] = "wpr.patient_id=%d"; $params[] = absint($filters['patient_id']); }
        if (!empty($filters['role_id'])) { $where[] = "wpr.role_id=%d"; $params[] = absint($filters['role_id']); }

        $sql = "
            SELECT he.*,
                   wpr.worker_id, wpr.patient_id, wpr.role_id,
                   CONCAT(w.first_name,' ',w.last_name) AS worker_name,
                   CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                   r.code AS role_code, r.name AS role_name
            FROM {$t} he
            JOIN {$wpr} wpr ON wpr.id = he.worker_patient_role_id
            JOIN {$wk} w ON w.id = wpr.worker_id
            JOIN {$pt} p ON p.id = wpr.patient_id
            JOIN {$rl} r ON r.id = wpr.role_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY he.id DESC
        ";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        return array_map([__CLASS__, 'normalizeRow'], $rows ?: []);
    }
    
    /**
     * Listado detallado (JOIN) para una grilla:
     * Devuelve worker/patient/role + nombres y códigos.
     */
    public static function listDetailedForPayroll(int $payrollId, array $filters = []): array {
        global $wpdb;
        $t   = self::table();
        $wpr = self::tableWPR();
        $wk  = self::tableWorkers();
        $pt  = self::tablePatients();
        $rl  = self::tableRoles();
        $seg = self::tableSegments();

        // Ahora filtra por payroll_id en la tabla de segmentos
        $where  = ["seg.payroll_id=%d"];
        $params = [$payrollId];

        if (!empty($filters['worker_id'])) { $where[] = "wpr.worker_id=%d"; $params[] = absint($filters['worker_id']); }
        if (!empty($filters['patient_id'])) { $where[] = "wpr.patient_id=%d"; $params[] = absint($filters['patient_id']); }
        if (!empty($filters['role_id'])) { $where[] = "wpr.role_id=%d"; $params[] = absint($filters['role_id']); }

        $sql = "
         SELECT he.*,
             wpr.worker_id, wpr.patient_id, wpr.role_id,
             CONCAT(w.first_name,' ',w.last_name) AS worker_name, w.company AS worker_company,
             CONCAT(p.first_name,' ',p.last_name) AS patient_name,
             r.code AS role_code, r.name AS role_name,
             seg.id AS segment_id, seg.segment_start AS segment_start, seg.segment_end AS segment_end
         FROM {$t} he
         JOIN {$wpr} wpr ON wpr.id = he.worker_patient_role_id
         JOIN {$wk} w ON w.id = wpr.worker_id
         JOIN {$pt} p ON p.id = wpr.patient_id
         JOIN {$rl} r ON r.id = wpr.role_id
         JOIN {$seg} seg ON seg.id = he.segment_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY seg.segment_start ASC, seg.segment_end ASC, he.id DESC
     ";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        return array_map([__CLASS__, 'normalizeRow'], $rows ?: []);
    }

    /** Totales por trabajador en un segmento */
    public static function totalsByWorkerForSegment(int $segmentId): array {
        global $wpdb;
        $t   = self::table();
        $wpr = self::tableWPR();
        $wk  = self::tableWorkers();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT wpr.worker_id,
                    CONCAT(w.first_name,' ',w.last_name) AS worker_name,
                    COALESCE(SUM(he.hours),0) AS total_hours,
                    COALESCE(SUM(he.total),0) AS total_amount
             FROM {$t} he
             JOIN {$wpr} wpr ON wpr.id = he.worker_patient_role_id
             JOIN {$wk} w   ON w.id = wpr.worker_id
             WHERE he.segment_id=%d
             GROUP BY wpr.worker_id, worker_name
             ORDER BY worker_name ASC",
            $segmentId
        ), ARRAY_A);

        foreach ($rows ?: [] as &$r) {
            $r['worker_id']    = (int)$r['worker_id'];
            $r['worker_name']  = (string)$r['worker_name'];
            $r['total_hours']  = (float)$r['total_hours'];
            $r['total_amount'] = (float)$r['total_amount'];
        }
        return $rows ?: [];
    }
    
    /** Totales por trabajador en un payroll */
    public static function totalsByWorkerForPayroll(int $payrollId): array {
        global $wpdb;
        $t   = self::table();
        $wpr = self::tableWPR();
        $wk  = self::tableWorkers();
        $seg = self::tableSegments();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT wpr.worker_id,
                    CONCAT(w.first_name,' ',w.last_name) AS worker_name,
                    COALESCE(SUM(he.hours),0) AS total_hours,
                    COALESCE(SUM(he.total),0) AS total_amount
             FROM {$t} he
             JOIN {$wpr} wpr ON wpr.id = he.worker_patient_role_id
             JOIN {$wk} w   ON w.id = wpr.worker_id
             JOIN {$seg} seg ON seg.id = he.segment_id
             WHERE seg.payroll_id=%d
             GROUP BY wpr.worker_id, worker_name
             ORDER BY worker_name ASC",
            $payrollId
        ), ARRAY_A);

        foreach ($rows ?: [] as &$r) {
            $r['worker_id']    = (int)$r['worker_id'];
            $r['worker_name']  = (string)$r['worker_name'];
            $r['total_hours']  = (float)$r['total_hours'];
            $r['total_amount'] = (float)$r['total_amount'];
        }
        return $rows ?: [];
    }

    /** Totales por paciente en un segmento */
    public static function totalsByPatientForSegment(int $segmentId): array {
        global $wpdb;
        $t   = self::table();
        $wpr = self::tableWPR();
        $pt  = self::tablePatients();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT wpr.patient_id,
                    CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                    COALESCE(SUM(he.hours),0) AS total_hours,
                    COALESCE(SUM(he.total),0) AS total_amount
             FROM {$t} he
             JOIN {$wpr} wpr ON wpr.id = he.worker_patient_role_id
             JOIN {$pt} p   ON p.id = wpr.patient_id
             WHERE he.segment_id=%d
             GROUP BY wpr.patient_id, patient_name
             ORDER BY patient_name ASC",
            $segmentId
        ), ARRAY_A);

        foreach ($rows ?: [] as &$r) {
            $r['patient_id']   = (int)$r['patient_id'];
            $r['patient_name'] = (string)$r['patient_name'];
            $r['total_hours']  = (float)$r['total_hours'];
            $r['total_amount'] = (float)$r['total_amount'];
        }
        return $rows ?: [];
    }

    /** Totales por paciente en un payroll */
    public static function totalsByPatientForPayroll(int $payrollId): array {
        global $wpdb;
        $t   = self::table();
        $wpr = self::tableWPR();
        $pt  = self::tablePatients();
        $seg = self::tableSegments();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT wpr.patient_id,
                    CONCAT(p.first_name,' ',p.last_name) AS patient_name,
                    COALESCE(SUM(he.hours),0) AS total_hours,
                    COALESCE(SUM(he.total),0) AS total_amount
             FROM {$t} he
             JOIN {$wpr} wpr ON wpr.id = he.worker_patient_role_id
             JOIN {$pt} p   ON p.id = wpr.patient_id
             JOIN {$seg} seg ON seg.id = he.segment_id
             WHERE seg.payroll_id=%d
             GROUP BY wpr.patient_id, patient_name
             ORDER BY patient_name ASC",
            $payrollId
        ), ARRAY_A);

        foreach ($rows ?: [] as &$r) {
            $r['patient_id']   = (int)$r['patient_id'];
            $r['patient_name'] = (string)$r['patient_name'];
            $r['total_hours']  = (float)$r['total_hours'];
            $r['total_amount'] = (float)$r['total_amount'];
        }
        return $rows ?: [];
    }

    /** Totales por rol en un segmento */
    public static function totalsByRoleForSegment(int $segmentId): array {
        global $wpdb;
        $t   = self::table();
        $wpr = self::tableWPR();
        $rl  = self::tableRoles();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT wpr.role_id, r.code AS role_code, r.name AS role_name,
                    COALESCE(SUM(he.hours),0) AS total_hours,
                    COALESCE(SUM(he.total),0) AS total_amount
             FROM {$t} he
             JOIN {$wpr} wpr ON wpr.id = he.worker_patient_role_id
             JOIN {$rl} r   ON r.id = wpr.role_id
             WHERE he.segment_id=%d
             GROUP BY wpr.role_id, r.code, r.name
             ORDER BY r.name ASC",
            $segmentId
        ), ARRAY_A);

        foreach ($rows ?: [] as &$r) {
            $r['role_id']      = (int)$r['role_id'];
            $r['role_code']    = (string)$r['role_code'];
            $r['role_name']    = (string)$r['role_name'];
            $r['total_hours']  = (float)$r['total_hours'];
            $r['total_amount'] = (float)$r['total_amount'];
        }
        return $rows ?: [];
    }

    /** Totales por rol en un payroll */
    public static function totalsByRoleForPayroll(int $payrollId): array {
        global $wpdb;
        $t   = self::table();
        $wpr = self::tableWPR();
        $rl  = self::tableRoles();
        $seg = self::tableSegments();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT wpr.role_id, r.code AS role_code, r.name AS role_name,
                    COALESCE(SUM(he.hours),0) AS total_hours,
                    COALESCE(SUM(he.total),0) AS total_amount
             FROM {$t} he
             JOIN {$wpr} wpr ON wpr.id = he.worker_patient_role_id
             JOIN {$rl} r   ON r.id = wpr.role_id
             JOIN {$seg} seg ON seg.id = he.segment_id
             WHERE seg.payroll_id=%d
             GROUP BY wpr.role_id, r.code, r.name
             ORDER BY r.name ASC",
            $payrollId
        ), ARRAY_A);

        foreach ($rows ?: [] as &$r) {
            $r['role_id']      = (int)$r['role_id'];
            $r['role_code']    = (string)$r['role_code'];
            $r['role_name']    = (string)$r['role_name'];
            $r['total_hours']  = (float)$r['total_hours'];
            $r['total_amount'] = (float)$r['total_amount'];
        }
        return $rows ?: [];
    }

    /* =============== Utilidades masivas =============== */

    /** Inserción masiva (array de filas); retorna [inserted=>n, errors=>[...]] */
    public static function bulkCreate(array $rows): array {
        $inserted = 0; $errors = [];
        foreach ($rows as $i => $data) {
            $res = self::create($data);
            if ($res instanceof WP_Error) $errors[$i] = $res->get_error_message(); else $inserted++;
        }
        return ['inserted'=>$inserted, 'errors'=>$errors];
    }
}
