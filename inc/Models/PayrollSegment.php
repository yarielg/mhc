<?php
namespace Mhc\Inc\Models;

use WP_Error;

class PayrollSegment
{
    /** Tablas */
    private static function table(): string {
        global $wpdb; return $wpdb->prefix . 'mhc_payroll_segments';
    }

    private static function now(): string { return current_time('mysql'); }

    /** Normaliza tipos */
    private static function normalizeRow($row) {
        if (!$row) return null;
        $row->id          = (int)$row->id;
        $row->payroll_id  = (int)$row->payroll_id;
        $row->segment_start = (string)$row->segment_start;
        $row->segment_end   = (string)$row->segment_end;
        $row->notes      = isset($row->notes) ? (string)$row->notes : '';
        $row->created_at = (string)$row->created_at;
        return $row;
    }

    /* =================== CRUD =================== */

    public static function findById($id) {
        global $wpdb;
        $t = self::table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", absint($id)));
        return self::normalizeRow($row);
    }

    public static function findAll($args = []) {
        global $wpdb;
        $t = self::table();
        $where = [];
        $params = [];

        if (!empty($args['payroll_id'])) {
            $where[] = "payroll_id=%d";
            $params[] = absint($args['payroll_id']);
        }
        if (!empty($args['segment_start'])) {
            $where[] = "segment_start >= %s";
            $params[] = $args['segment_start'];
        }
        if (!empty($args['segment_end'])) {
            $where[] = "segment_end <= %s";
            $params[] = $args['segment_end'];
        }

        $sql = "SELECT * FROM {$t}";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY segment_start ASC";

        if ($params) $sql = $wpdb->prepare($sql, ...$params);
        $rows = $wpdb->get_results($sql);
        return array_map([__CLASS__, 'normalizeRow'], $rows ?: []);
    }

    public static function create($data) {
        global $wpdb;
        $t = self::table();
        $payload = [
            'payroll_id'    => isset($data['payroll_id']) ? absint($data['payroll_id']) : 0,
            'segment_start' => isset($data['segment_start']) ? $data['segment_start'] : '',
            'segment_end'   => isset($data['segment_end']) ? $data['segment_end'] : '',
            'notes'         => isset($data['notes']) ? $data['notes'] : '',
            'created_at'    => self::now(),
        ];
        if ($payload['payroll_id'] <= 0 || !$payload['segment_start'] || !$payload['segment_end']) {
            return new WP_Error('invalid_data', 'Faltan datos obligatorios.');
        }
        $ok = $wpdb->insert($t, $payload, ['%d','%s','%s','%s','%s']);
        if ($ok === false) return new WP_Error('db_insert_failed', 'No se pudo crear el segmento.');
        return (int)$wpdb->insert_id;
    }

    public static function update($id, $data) {
        global $wpdb;
        $t = self::table();
        $id = absint($id);
        $set = [];
        $fmt = [];
        if (array_key_exists('payroll_id', $data)) { $set['payroll_id'] = absint($data['payroll_id']); $fmt[] = '%d'; }
        if (array_key_exists('segment_start', $data)) { $set['segment_start'] = $data['segment_start']; $fmt[] = '%s'; }
        if (array_key_exists('segment_end', $data)) { $set['segment_end'] = $data['segment_end']; $fmt[] = '%s'; }
        if (array_key_exists('notes', $data)) { $set['notes'] = $data['notes']; $fmt[] = '%s'; }
        if (empty($set)) return true;
        $ok = $wpdb->update($t, $set, ['id'=>$id], $fmt, ['%d']);
        if ($ok === false) return new WP_Error('db_update_failed', 'No se pudo actualizar el segmento.');
        return true;
    }

    public static function delete($id) {
        global $wpdb;
        $t = self::table();
        $ok = $wpdb->delete($t, ['id'=>absint($id)], ['%d']);
        if ($ok === false) return new WP_Error('db_delete_failed', 'No se pudo eliminar el segmento.');
        return (bool)$ok;
    }

    /* =============== Helpers =============== */

    /** Busca segmentos por rango de fechas */
    public static function findByDateRange($payrollId, $start, $end) {
        global $wpdb;
        $t = self::table();
        $sql = "SELECT * FROM {$t} WHERE payroll_id=%d AND segment_start >= %s AND segment_end <= %s ORDER BY segment_start ASC";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $payrollId, $start, $end));
        return array_map([__CLASS__, 'normalizeRow'], $rows ?: []);
    }

    /** Busca el segmento que contiene una fecha */
    public static function findSegmentForDate($payrollId, $date) {
        global $wpdb;
        $t = self::table();
        $sql = "SELECT * FROM {$t} WHERE payroll_id=%d AND segment_start <= %s AND segment_end >= %s LIMIT 1";
        $row = $wpdb->get_row($wpdb->prepare($sql, $payrollId, $date, $date));
        return self::normalizeRow($row);
    }

    /** Inserción masiva de segmentos */
    public static function bulkCreate($payrollId, array $segments) {
        $inserted = 0; $errors = [];
        foreach ($segments as $i => $data) {
            $data['payroll_id'] = $payrollId;
            $res = self::create($data);
            if ($res instanceof WP_Error) $errors[$i] = $res->get_error_message(); else $inserted++;
        }
        return ['inserted'=>$inserted, 'errors'=>$errors];
    }
}
