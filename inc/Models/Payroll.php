<?php
namespace Mhc\Inc\Models;

use WP_Error;

class Payroll
{
    /** Tabla */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'mhc_payrolls';
    }
    private static function now(): string { return current_time('mysql'); }

    /** Normaliza tipos */
    private static function normalizeRow($row) {
        if (!$row) return null;
        $row->id         = (int)$row->id;
        $row->start_date = (string)$row->start_date;
        $row->end_date   = (string)$row->end_date;
        $row->status     = (string)$row->status;
        $row->notes      = isset($row->notes) ? (string)$row->notes : '';
        return $row;
    }

    /* =================== CRUD =================== */

    public static function findById($id) {
        global $wpdb;
        $t = self::table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", absint($id))
        );
        return self::normalizeRow($row);
    }

    /**
     * findAll con filtros:
     *  - status: 'draft' | 'finalized' | 'locked' (o el que uses)
     *  - date_overlaps: ['start'=>'YYYY-MM-DD','end'=>'YYYY-MM-DD'] (periodos que se solapan)
     *  - start_date_from, start_date_to
     *  - search (en notes)
     *  - orderby: id|start_date|end_date|status|created_at|updated_at
     *  - order: ASC|DESC
     *  - limit, offset
     */
    public static function findAll($args = []) {
        global $wpdb;
        $t = self::table();
        $where  = [];
        $params = [];

        if (!empty($args['status']) && $args['status'] !== 'all') {
            $where[] = "status=%s";
            $params[] = (string)$args['status'];
        }

        // Rango que se solapa con otro rango (útil para evitar duplicidades de periodos)
        if (!empty($args['date_overlaps']['start']) && !empty($args['date_overlaps']['end'])) {
            $s = $args['date_overlaps']['start'];
            $e = $args['date_overlaps']['end'];
            // (start_date <= e) AND (end_date >= s)
            $where[] = "(start_date <= %s AND end_date >= %s)";
            $params[] = $e; $params[] = $s;
        } else {
            if (!empty($args['start_date_from'])) {
                $where[] = "start_date >= %s";
                $params[] = (string)$args['start_date_from'];
            }
            if (!empty($args['start_date_to'])) {
                $where[] = "start_date <= %s";
                $params[] = (string)$args['start_date_to'];
            }
        }

        if (!empty($args['search'])) {
            $where[] = "notes LIKE %s";
            $params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $sql = "SELECT * FROM {$t}";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);

        $allowedOrderBy = ['id','start_date','end_date','status','created_at','updated_at'];
        $orderby = (isset($args['orderby']) && in_array($args['orderby'], $allowedOrderBy, true)) ? $args['orderby'] : 'id';
        $order   = (isset($args['order']) && strtoupper($args['order']) === 'ASC') ? 'ASC' : 'DESC';
        $sql .= " ORDER BY {$orderby} {$order}";

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

    public static function create($data) {
        global $wpdb;
        $t = self::table();

        $start = isset($data['start_date']) ? (string)$data['start_date'] : '';
        $end   = isset($data['end_date'])   ? (string)$data['end_date']   : '';
        if (!$start || !$end) return new WP_Error('invalid_dates', 'start_date y end_date son obligatorios.');
        if ($end < $start)    return new WP_Error('invalid_range', 'end_date no puede ser menor que start_date.');

        $payload = [
            'start_date' => $start,
            'end_date'   => $end,
            'status'     => !empty($data['status']) ? sanitize_text_field((string)$data['status']) : 'draft',
            'notes'      => isset($data['notes']) ? sanitize_text_field((string)$data['notes']) : '',
            'created_at' => self::now(),
            'updated_at' => self::now(),
        ];

        $ok = $wpdb->insert($t, $payload, ['%s','%s','%s','%s','%s','%s']);
        if ($ok === false) return new WP_Error('db_insert_failed', 'No se pudo crear el payroll.');

        return (int)$wpdb->insert_id;
    }

    public static function update($id, $data) {
        global $wpdb;
        $t = self::table();
        $id = absint($id);

        $current = self::findById($id);
        if (!$current) return new WP_Error('not_found', 'Payroll no encontrado.');

        // No permitimos cambiar fechas si está finalizado (puedes relajar esto)
        $finalized = strtolower($current->status) === 'finalized';

        $set = [];
        $fmt = [];

        if (!$finalized) {
            if (array_key_exists('start_date', $data) && $data['start_date']) { $set['start_date'] = (string)$data['start_date']; $fmt[] = '%s'; }
            if (array_key_exists('end_date', $data)   && $data['end_date'])   { $set['end_date']   = (string)$data['end_date'];   $fmt[] = '%s'; }
        }
        if (array_key_exists('status', $data) && $data['status']) {
            $set['status'] = sanitize_text_field((string)$data['status']); $fmt[] = '%s';
        }
        if (array_key_exists('notes', $data)) {
            $set['notes'] = sanitize_text_field((string)$data['notes']); $fmt[] = '%s';
        }

        if (empty($set)) return true;

        // Validación de rango si se cambian fechas:
        if (!$finalized && isset($set['start_date']) && isset($set['end_date']) && $set['end_date'] < $set['start_date']) {
            return new WP_Error('invalid_range', 'end_date no puede ser menor que start_date.');
        }

        $set['updated_at'] = self::now(); $fmt[] = '%s';

        $ok = $wpdb->update($t, $set, ['id'=>$id], $fmt, ['%d']);
        if ($ok === false) return new WP_Error('db_update_failed', 'No se pudo actualizar el payroll.');
        return true;
    }

    public static function delete($id) {
        global $wpdb;
        $t = self::table();
        $id = absint($id);

        $current = self::findById($id);
        if (!$current) return new WP_Error('not_found', 'Payroll no encontrado.');
        if (strtolower($current->status) === 'finalized') {
            return new WP_Error('locked', 'No se puede eliminar un payroll finalizado.');
        }

        $ok = $wpdb->delete($t, ['id'=>$id], ['%d']);
        if ($ok === false) return new WP_Error('db_delete_failed', 'No se pudo eliminar el payroll.');
        return (bool)$ok;
    }

    /* =================== MÉTODOS CLAVE DE NEGOCIO =================== */

    /** Finaliza el payroll (sella totales; puedes agregar más validaciones si quieres) */
    public static function finalize($id) {
        $id = absint($id);
        $p = self::findById($id);
        if (!$p) return new WP_Error('not_found', 'Payroll no encontrado.');
        if (strtolower($p->status) === 'finalized') return true;

        // (Opcional) valida límites, que no haya pacientes “pendientes”, etc.

        return self::update($id, ['status' => 'finalized']);
    }

    /** Reabre un payroll finalizado (si tu negocio lo permite) */
    public static function reopen($id) {
        $id = absint($id);
        $p = self::findById($id);
        if (!$p) return new WP_Error('not_found', 'Payroll no encontrado.');
        if (strtolower($p->status) !== 'finalized') return true;

        // Puedes chequear capabilities antes.
        return self::update($id, ['status' => 'draft']);
    }

    /**
     * Stats para header de la vista:
     * - processed, pending, total (patient_payrolls)
     */
    public static function stats($id): array {
        $id = absint($id);
        return PatientPayroll::stats($id) ?? ['processed'=>0,'pending'=>0,'total'=>0];
    }

    /**
     * Totales de dinero:
     * - hours: total_hours, total_amount
     * - extras: total_amount
     * - grand_total
     */
    public static function totals($id): array {
        $id = absint($id);

        // Totales de horas
        $hoursByWorker = HoursEntry::totalsByWorkerForPayroll($id); // suma por trabajador
        $hoursTotalAmount = 0.0; $hoursTotalHours = 0.0;
        foreach ($hoursByWorker as $w) {
            $hoursTotalAmount += (float)$w['total_amount'];
            $hoursTotalHours  += (float)$w['total_hours'];
        }

        // Totales de extras
        $extrasByWorker = ExtraPayment::totalsByWorkerForPayroll($id);
        $extrasTotalAmount = 0.0;
        foreach ($extrasByWorker as $w) {
            $extrasTotalAmount += (float)$w['total_amount'];
        }

        return [
            'hours'       => ['total_hours'=>$hoursTotalHours, 'total_amount'=>$hoursTotalAmount],
            'extras'      => ['total_amount'=>$extrasTotalAmount],
            'grand_total' => $hoursTotalAmount + $extrasTotalAmount,
        ];
    }

    /**
     * Payload completo para la pantalla de detalle:
     * - payroll
     * - stats (procesados/pendientes/total)
     * - totals (hours, extras, grand_total)
     * - breakdowns opcionales: por rol, por paciente, por código de extra
     */
    public static function detail($id): array|WP_Error {
        $p = self::findById($id);
        if (!$p) return new WP_Error('not_found', 'Payroll no encontrado.');

        $stats  = self::stats($id);
        $totals = self::totals($id);

        // Breakdowns útiles para tabs/paneles
        $byRole    = HoursEntry::totalsByRoleForPayroll($id);
        $byPatient = HoursEntry::totalsByPatientForPayroll($id);
        $byCode    = ExtraPayment::totalsByCodeForPayroll($id);

        return [
            'payroll' => $p,
            'stats'   => $stats,
            'totals'  => $totals,
            'breakdowns' => [
                'hours_by_role'    => $byRole,
                'hours_by_patient' => $byPatient,
                'extras_by_code'   => $byCode,
            ],
        ];
    }

    /**
     * Verifica si el periodo (start/end) se solapa con alguno existente.
     * Útil antes de crear/editar para evitar duplicidad de ventanas de pago.
     */
    public static function hasOverlap(string $start, string $end, ?int $excludeId = null): bool {
        global $wpdb;
        $t = self::table();

        $sql = "SELECT COUNT(*) FROM {$t} WHERE (start_date <= %s AND end_date >= %s)";
        $params = [$end, $start];

        if ($excludeId) {
            $sql .= " AND id <> %d";
            $params[] = absint($excludeId);
        }

        $count = (int)$wpdb->get_var($wpdb->prepare($sql, ...$params));
        return $count > 0;
    }
}
