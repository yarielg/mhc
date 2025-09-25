<?php
namespace Mhc\Inc\Models;

class Patient {


    public static function findById($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
        $wpr = "{$pfx}mhc_worker_patient_roles";
        $id = intval($id);
        if ($id <= 0) return null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
        if (!$row) return null;
        $row['assignments'] = $wpdb->get_results(
            $wpdb->prepare("SELECT worker_id, role_id, rate FROM $wpr WHERE patient_id=%d", $id),
            ARRAY_A
        );
        //error_log(print_r($row, true));
        return $row;
    }

    public static function findAll($search = '', $page = 1, $per_page = 10, $worker_id = 0, $is_active = null) {
        global $wpdb;
        $pfx   = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
        $wpr   = "{$pfx}mhc_worker_patient_roles";

        $offset = max(0, ($page - 1) * $per_page);

        $where  = [];
        $params = [];

        // Search (first/last/full name, record number)
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = "(p.first_name LIKE %s OR p.last_name LIKE %s OR CONCAT(p.first_name,' ',p.last_name) LIKE %s OR p.record_number LIKE %s)";
            $params[] = $like; // first_name
            $params[] = $like; // last_name
            $params[] = $like; // full name
            $params[] = $like; // record_number
        }

        // Filtro por estado activo/inactivo
        if ($is_active !== null && $is_active !== '') {
            $where[] = "p.is_active = %d";
            $params[] = (int)$is_active;
        }

        // Filter: only patients with an active relationship to this worker
        if ((int)$worker_id > 0) {
            $where[] = "EXISTS (
            SELECT 1
            FROM {$wpr} w
            WHERE w.patient_id = p.id
              AND w.worker_id = %d
              AND w.end_date IS NULL
        )";
            $params[] = (int)$worker_id;
        }

        $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Total count
        $total_sql = "SELECT COUNT(*) FROM {$table} p {$where_sql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($total_sql, $params));

        // Rows (page)
        $rows_sql = "SELECT p.* FROM {$table} p {$where_sql} ORDER BY p.id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results(
            $wpdb->prepare($rows_sql, array_merge($params, [(int)$per_page, (int)$offset])),
            ARRAY_A
        );

        // Attach current assignments (optional; keeps your original behavior)
        foreach ($rows as &$row) {
            $row['assignments'] = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT worker_id, role_id, rate
                 FROM {$wpr}
                 WHERE patient_id = %d AND end_date IS NULL",
                    $row['id']
                ),
                ARRAY_A
            );
        }
        unset($row);

        return [
            'items' => $rows,
            'total' => $total,
        ];
    }


    public static function create($data) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
        $fields = [];
        $fmts = [];
        foreach (["first_name","last_name","record_number","is_active","start_date","end_date"] as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
                $fmts[] = $field === "is_active" ? '%d' : '%s';
            }
        }
        $fields['created_at'] = current_time('mysql');
        $fmts[] = '%s';
        $ok = $wpdb->insert($table, $fields, $fmts);
        if (!$ok) return false;
        $id = $wpdb->insert_id;
        // Guardar asignaciones si existen
        if (!empty($data['assignments']) && is_array($data['assignments'])) {
            self::assignWorkers($id, $data['assignments']);
        }
        return self::findById($id);
    }

    public static function update($id, $data) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
        $wpr = "{$pfx}mhc_worker_patient_roles";
        $fields = [];
        $fmts = [];
        foreach (["first_name","last_name","record_number","is_active","start_date","end_date"] as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
                $fmts[] = $field === "is_active" ? '%d' : '%s';
            }
        }
        if (!$fields) return false;
        $ok = $wpdb->update($table, $fields, ['id' => intval($id)], $fmts, ['%d']);
        if ($ok === false) return false;
        // Actualizar asignaciones si existen
        if (isset($data['assignments']) && is_array($data['assignments'])) {
            // Eliminar asignaciones previas
            $wpdb->delete($wpr, ['patient_id' => intval($id)], ['%d']);
            self::assignWorkers($id, $data['assignments']);
        }
        return self::findById($id);
    }

    public static function delete($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
        $ok = $wpdb->delete($table, ['id' => intval($id)], ['%d']);
        return $ok !== false;
    }

    public static function assignWorkers($patient_id, array $assignments) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $wpr = "{$pfx}mhc_worker_patient_roles";
        $workers = "{$pfx}mhc_workers";
        $roles   = "{$pfx}mhc_roles";

        $today = current_time('Y-m-d');

        foreach ($assignments as $a) {
            $worker_id = isset($a['worker_id']) ? intval($a['worker_id']) : 0;
            $role_id   = isset($a['role_id'])   ? intval($a['role_id'])   : 0;
            $rate      = isset($a['rate']) && $a['rate'] !== '' ? floatval($a['rate']) : null;

            if ($worker_id <= 0 || $role_id <= 0) continue;

            // lightweight existence checks
            $exists_worker = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $workers WHERE id=%d", $worker_id));
            $exists_role   = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $roles WHERE id=%d", $role_id));
            if (!$exists_worker || !$exists_role) continue;

            $wpdb->insert($wpr, [
                'worker_id'  => $worker_id,
                'patient_id' => $patient_id,
                'role_id'    => $role_id,
                'rate'       => $rate,           // nullable; if null, later payroll logic can fall back to general_rate
                'start_date' => $today,
                'created_at' => current_time('mysql'),
            ], [
                '%d','%d','%d', $rate === null ? 'NULL' : '%f', '%s','%s'
            ]);
            if ($wpdb->last_error) return false;
        }
        return true;
    }
}
