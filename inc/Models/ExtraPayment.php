<?php
namespace Mhc\Inc\Models;

use WP_Error;

class ExtraPayment
{
    /** Nombre de la tabla */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'mhc_extra_payments';
    }

    /** Tabla de special rates */
    private static function tableSR(): string {
        global $wpdb;
        return $wpdb->prefix . 'mhc_special_rates';
    }

    private static function tableW(): string {
        global $wpdb;
        return $wpdb->prefix . 'mhc_workers';
    }

    private static function tableP(): string {
        global $wpdb;
        return $wpdb->prefix . 'mhc_patients';
    }

    private static function now(): string {
        return current_time('mysql');
    }

    /** Normaliza tipos */
    private static function normalizeRow($row) {
        if (!$row) return null;
        $row->id                  = (int) $row->id;
        $row->payroll_id          = (int) $row->payroll_id;
        $row->worker_id           = (int) $row->worker_id;
        $row->supervised_worker_id = isset($row->supervised_worker_id) ? (int)$row->supervised_worker_id : null;
        $row->patient_id          = isset($row->patient_id) ? (int) $row->patient_id : null;
        $row->special_rate_id     = (int) $row->special_rate_id;
        $row->amount              = (float) $row->amount;
        $row->notes               = (string) ($row->notes ?? '');
        // Si el SELECT viene con code/label/unit_rate desde el JOIN, normalízalos:
        if (isset($row->code))      $row->code      = (string)$row->code;
        if (isset($row->label))     $row->label     = (string)$row->label;
        if (isset($row->unit_rate)) $row->unit_rate = (float)$row->unit_rate;
        return $row;
    }

    /* ==================== CRUD ==================== */

    public static function findById($id) {
        global $wpdb;
        $t  = self::table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t} WHERE id=%d", absint($id))
        );
        return self::normalizeRow($row);
    }

    /**
     * findAll con filtros opcionales:
     *  - payroll_id, worker_id, supervised_worker_id, patient_id, special_rate_id
     *  - search (en notes)
     *  - orderby: id|payroll_id|worker_id|patient_id|special_rate_id|amount|created_at|updated_at
     *  - order: ASC|DESC
     *  - limit, offset
     */
    public static function findAll($args = []) {
        global $wpdb;
        $t = self::table();
        $where  = [];
        $params = [];

        if (!empty($args['payroll_id'])) {
            $where[] = "payroll_id=%d";
            $params[] = absint($args['payroll_id']);
        }
        if (!empty($args['worker_id'])) {
            $where[] = "worker_id=%d";
            $params[] = absint($args['worker_id']);
        }
        if (array_key_exists('supervised_worker_id', $args)) {
            if ($args['supervised_worker_id'] === null) {
                $where[] = "supervised_worker_id IS NULL";
            } else {
                $where[] = "supervised_worker_id=%d";
                $params[] = absint($args['supervised_worker_id']);
            }
        }
        if (array_key_exists('patient_id', $args)) {
            if ($args['patient_id'] === null) {
                $where[] = "patient_id IS NULL";
            } else {
                $where[] = "patient_id=%d";
                $params[] = absint($args['patient_id']);
            }
        }
        if (!empty($args['special_rate_id'])) {
            $where[] = "special_rate_id=%d";
            $params[] = absint($args['special_rate_id']);
        }
        if (!empty($args['search'])) {
            $where[] = "notes LIKE %s";
            $params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $sql = "SELECT * FROM {$t}";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);

        $allowedOrderBy = ['id','payroll_id','worker_id','patient_id','special_rate_id','amount','created_at','updated_at'];
        $orderby = (isset($args['orderby']) && in_array($args['orderby'], $allowedOrderBy, true)) ? $args['orderby'] : 'id';
        $order   = (isset($args['order'])   && strtoupper($args['order']) === 'ASC') ? 'ASC' : 'DESC';
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

    /**
     * create: payroll_id, worker_id, special_rate_id, amount son obligatorios
     * patient_id y supervised_worker_id son opcionales (NULL)
     */
    public static function create($data) {
        global $wpdb;
        $t = self::table();

        $payload = [
            'payroll_id'          => isset($data['payroll_id']) ? absint($data['payroll_id']) : 0,
            'worker_id'           => isset($data['worker_id']) ? absint($data['worker_id']) : 0,
            'supervised_worker_id'=> array_key_exists('supervised_worker_id', $data) && $data['supervised_worker_id'] !== null
                                        ? absint($data['supervised_worker_id']) : null,
            'patient_id'          => array_key_exists('patient_id', $data) && $data['patient_id'] !== null
                                        ? absint($data['patient_id']) : null,
            'special_rate_id'     => isset($data['special_rate_id']) ? absint($data['special_rate_id']) : 0,
            'amount'              => isset($data['amount']) ? (float)$data['amount'] : 0.0,
            'notes'               => isset($data['notes']) ? sanitize_text_field((string)$data['notes']) : '',
            'created_at'          => self::now(),
            'updated_at'          => self::now(),
        ];

        if ($payload['payroll_id'] <= 0 || $payload['worker_id'] <= 0 || $payload['special_rate_id'] <= 0) {
            return new WP_Error('invalid_data', 'Faltan campos obligatorios: payroll_id, worker_id, special_rate_id.');
        }

        // Construye dinámicamente columnas y formatos (manejo de NULL correcto)
        $columns = ['payroll_id','worker_id','special_rate_id','amount','notes','created_at','updated_at'];
        $values  = [$payload['payroll_id'],$payload['worker_id'],$payload['special_rate_id'],$payload['amount'],$payload['notes'],$payload['created_at'],$payload['updated_at']];
        $format  = ['%d','%d','%d','%f','%s','%s','%s'];

        if ($payload['supervised_worker_id'] !== null) {
            $columns[] = 'supervised_worker_id';
            $values[]  = $payload['supervised_worker_id'];
            $format[]  = '%d';
        }
        if ($payload['patient_id'] !== null) {
            $columns[] = 'patient_id';
            $values[]  = $payload['patient_id'];
            $format[]  = '%d';
        }

        $ok = $wpdb->insert($t, array_combine($columns,$values), $format);
        if ($ok === false) return new WP_Error('db_insert_failed', 'No se pudo crear el extra.');

        return (int)$wpdb->insert_id;
    }

    /**
     * update: permite actualizar cualquier campo editable
     */
    public static function update($id, $data) {
        global $wpdb;
        $t  = self::table();
        $id = absint($id);

        $allowed = ['payroll_id','worker_id','supervised_worker_id','patient_id','special_rate_id','amount','notes'];
        $set   = [];
        $fmt   = [];

        foreach ($allowed as $k) {
            if (!array_key_exists($k, $data)) continue;

            $v = $data[$k];
            switch ($k) {
                case 'payroll_id':
                case 'worker_id':
                case 'special_rate_id':
                    $set[$k] = absint($v);
                    $fmt[] = '%d';
                    break;
                case 'supervised_worker_id':
                case 'patient_id':
                    if ($v === null || $v === '') {
                        // Lo forzaremos a NULL con un UPDATE directo posterior
                        $set[$k] = null;
                        $fmt[]   = '%s';
                    } else {
                        $set[$k] = absint($v);
                        $fmt[]   = '%d';
                    }
                    break;
                case 'amount':
                    $set[$k] = (float)$v;
                    $fmt[]   = '%f';
                    break;
                case 'notes':
                    $set[$k] = sanitize_text_field((string)$v);
                    $fmt[]   = '%s';
                    break;
            }
        }

        if (empty($set)) return true;

        $set['updated_at'] = self::now();
        $fmt[] = '%s';

        $ok = $wpdb->update($t, $set, ['id'=>$id], $fmt, ['%d']);
        if ($ok === false) return new WP_Error('db_update_failed', 'No se pudo actualizar el extra.');

        // Forzar NULL donde corresponda (wpdb->update no maneja bien null con formato)
        if (array_key_exists('supervised_worker_id', $data) && ($data['supervised_worker_id'] === null || $data['supervised_worker_id'] === '')) {
            $wpdb->query($wpdb->prepare("UPDATE {$t} SET supervised_worker_id = NULL WHERE id=%d", $id));
        }
        if (array_key_exists('patient_id', $data) && ($data['patient_id'] === null || $data['patient_id'] === '')) {
            $wpdb->query($wpdb->prepare("UPDATE {$t} SET patient_id = NULL WHERE id=%d", $id));
        }

        return true;
    }

    public static function delete($id) {
        global $wpdb;
        $t = self::table();
        $ok = $wpdb->delete($t, ['id'=>absint($id)], ['%d']);
        if ($ok === false) return new WP_Error('db_delete_failed', 'No se pudo eliminar el extra.');
        return (bool)$ok;
    }

    /* ==================== HELPERS / REPORTES ==================== */

    /** Totales por trabajador en un payroll (para colilla) */
    public static function totalsByWorkerForPayroll(int $payrollId): array {
        global $wpdb;
        $t = self::table();
        $sql = $wpdb->prepare(
            "SELECT worker_id,
                    SUM(amount) AS total_amount,
                    COUNT(*)    AS items
             FROM {$t}
             WHERE payroll_id=%d
             GROUP BY worker_id",
            $payrollId
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        foreach ($rows ?: [] as &$r) {
            $r['worker_id']    = (int)$r['worker_id'];
            $r['total_amount'] = (float)$r['total_amount'];
            $r['items']        = (int)$r['items'];
        }
        return $rows ?: [];
    }

    /**
     * Totales por código (JOIN a special_rates) para resumen admin
     * Devuelve: code, label, unit_rate (de catálogo), total_amount, items
     */
    public static function totalsByCodeForPayroll(int $payrollId): array {
        global $wpdb;
        $t  = self::table();
        $sr = self::tableSR();

        $sql = $wpdb->prepare(
            "SELECT sr.code, sr.label, sr.unit_rate,
                    SUM(ep.amount) AS total_amount,
                    COUNT(*)       AS items
             FROM {$t} ep
             JOIN {$sr} sr ON sr.id = ep.special_rate_id
             WHERE ep.payroll_id=%d
             GROUP BY sr.code, sr.label, sr.unit_rate
             ORDER BY sr.code ASC",
            $payrollId
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        foreach ($rows ?: [] as &$r) {
            $r['code']         = (string)$r['code'];
            $r['label']        = (string)$r['label'];
            $r['unit_rate']    = (float)$r['unit_rate'];
            $r['total_amount'] = (float)$r['total_amount'];
            $r['items']        = (int)$r['items'];
        }
        return $rows ?: [];
    }

    /**
     * Listado detallado para un payroll con datos del special rate (code/label/unit_rate).
     * Filtros opcionales: worker_id, patient_id (NULL permitido), supervised_worker_id (NULL permitido)
     */
    public static function listDetailedForPayroll(int $payrollId, array $filters = []): array {
        global $wpdb;
        $t  = self::table();
        $sr = self::tableSR();
        $w = self::tableW();
        $p = self::tableP();
        $where  = ["ep.payroll_id=%d"];
        $params = [$payrollId];

        if (!empty($filters['worker_id'])) {
            $where[] = "ep.worker_id=%d";
            $params[] = absint($filters['worker_id']);
        }
        if (array_key_exists('patient_id', $filters)) {
            if ($filters['patient_id'] === null) {
                $where[] = "ep.patient_id IS NULL";
            } else {
                $where[] = "ep.patient_id=%d";
                $params[] = absint($filters['patient_id']);
            }
        }
        if (array_key_exists('supervised_worker_id', $filters)) {
            if ($filters['supervised_worker_id'] === null) {
                $where[] = "ep.supervised_worker_id IS NULL";
            } else {
                $where[] = "ep.supervised_worker_id=%d";
                $params[] = absint($filters['supervised_worker_id']);
            }
        }

        $sql = "
            SELECT ep.*,
                   sr.code, sr.label, sr.unit_rate, CONCAT_WS(' ',w.first_name, w.last_name) as worker_name, CONCAT_WS(' ',p.first_name, p.last_name) as patient_name
            FROM {$t} ep
            JOIN {$sr} sr ON sr.id = ep.special_rate_id
            JOIN {$w} w ON ep.worker_id = w.id
             JOIN {$p} p ON ep.patient_id = p.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY ep.id DESC
        ";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        return array_map([__CLASS__, 'normalizeRow'], $rows ?: []);
    }
}
