<?php
namespace Mhc\Inc\Models;

class Patient {


    public static function findById($id, $ended = true) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
        $wpr = "{$pfx}mhc_worker_patient_roles";
        $ins = "{$pfx}mhc_insurers";
        $id = intval($id);
        if ($id <= 0) return null;
        // include insurer name if present
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT p.*, ins.name AS insurer_name FROM {$table} p LEFT JOIN {$ins} ins ON p.insurer_id = ins.id WHERE p.id=%d",
            $id
        ), ARRAY_A);
        if (!$row) return null;
        // Attach all only assignments without end_date if $ended is false
        // If $ended is true, attach all assignments including those with end_date
        if ($ended) {
            $row['assignments'] = $wpdb->get_results(
                $wpdb->prepare("SELECT worker_id, role_id, rate, id AS wpr_id FROM $wpr WHERE patient_id=%d", $id),
                ARRAY_A
            );            
        }else {
            $row['assignments'] = $wpdb->get_results(
                $wpdb->prepare("SELECT worker_id, role_id, rate, id AS wpr_id FROM $wpr WHERE patient_id=%d AND end_date IS NULL", $id),
                ARRAY_A
            );            
        }        
        //error_log(print_r($row, true));
        return $row;
    }

    public static function findAll($search = '', $page = 1, $per_page = 10, $worker_id = 0, $is_active = null) {
        global $wpdb;
        $pfx   = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
    $wpr   = "{$pfx}mhc_worker_patient_roles";
    $ins   = "{$pfx}mhc_insurers";

        $offset = max(0, ($page - 1) * $per_page);

        $where  = [];
        $params = [];

        // Search (first/last/full name, record number)
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            // include insurer name in the searchable fields
            $where[] = "(p.first_name LIKE %s OR p.last_name LIKE %s OR CONCAT(p.first_name,' ',p.last_name) LIKE %s OR p.record_number LIKE %s OR p.insurer_number LIKE %s OR ins.name LIKE %s)";
            $params[] = $like; // first_name
            $params[] = $like; // last_name
            $params[] = $like; // full name
            $params[] = $like; // record_number
            $params[] = $like; // insurer_number
            $params[] = $like; // insurer name
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

        // join insurers so we can return insurer name and search by it
        $join_sql = " LEFT JOIN {$ins} ins ON p.insurer_id = ins.id";
        $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Total count (include join to keep WHERE references valid)
        $total_sql = "SELECT COUNT(*) FROM {$table} p {$join_sql} {$where_sql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($total_sql, $params));

        // Rows (page) - include insurer name
        $rows_sql = "SELECT p.*, ins.name AS insurer_name FROM {$table} p {$join_sql} {$where_sql} ORDER BY p.id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results(
            $wpdb->prepare($rows_sql, array_merge($params, [(int)$per_page, (int)$offset])),
            ARRAY_A
        );

        // Attach current assignments (optional; keeps your original behavior)
        foreach ($rows as &$row) {
            $row['assignments'] = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT worker_id, role_id, rate, id AS wpr_id
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

    //soft delete assignments by id
    public static function deleteAssignment($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $wpr = "{$pfx}mhc_worker_patient_roles";
        $id = intval($id);
        if ($id <= 0) return false;
        $ok = $wpdb->update($wpr, ['deleted_at' => current_time('mysql')], ['id' => $id], ['%s'], ['%d']);
        return $ok !== false;
    }

    //set end_date for assignments by id
    public static function endAssignment($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $wpr = "{$pfx}mhc_worker_patient_roles";
        $last_payroll_end_date = $wpdb->get_var($wpdb->prepare(
            "SELECT end_date FROM {$wpdb->prefix}mhc_payrolls ORDER BY end_date DESC LIMIT 1"
        ));
        $id = intval($id);
        if ($id <= 0) return false;
        $ok = $wpdb->update($wpr, ['end_date' => $last_payroll_end_date ?? current_time('mysql')], ['id' => $id], ['%s'], ['%d']);
        return $ok !== false;
    }

    public static function create($data) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
        $fields = [];
        $fmts = [];
        foreach (["first_name","last_name","insurer_id","insurer_number","record_number","is_active","start_date","end_date"] as $field) {
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
        foreach (["first_name","last_name","insurer_id","insurer_number","record_number","is_active","start_date","end_date"] as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
                $fmts[] = $field === "is_active" ? '%d' : '%s';
            }
        }
        if (!$fields) return false;
        $ok = $wpdb->update($table, $fields, ['id' => intval($id)], $fmts, ['%d']);
        if ($ok === false) return false;
        // Actualizar asignaciones si existen

        //get last payroll end date to put as end date for old assignments
        $last_payroll_end_date = $wpdb->get_var($wpdb->prepare(
            "SELECT end_date FROM {$wpdb->prefix}mhc_payrolls ORDER BY end_date DESC LIMIT 1"
        ));
        $last_payroll_start_date = $wpdb->get_var($wpdb->prepare(
            "SELECT start_date FROM {$wpdb->prefix}mhc_payrolls ORDER BY end_date DESC LIMIT 1"
        ));

        if (isset($data['assignments']) && is_array($data['assignments'])) {
            $hours_entry = $wpdb->prefix . 'mhc_hours_entries';
            foreach ($data['assignments'] as $a) {
                $worker_id = isset($a['worker_id']) ? intval($a['worker_id']) : 0;
                $role_id   = isset($a['role_id'])   ? intval($a['role_id'])   : 0;
                $rate      = isset($a['rate']) && $a['rate'] !== '' ? floatval($a['rate']) : null;
                $wpr_id    = isset($a['wpr_id']) ? intval($a['wpr_id']) : 0;
                if ($worker_id <= 0 || $role_id <= 0) continue;
                // Buscar si ya existe la asignación
                if ($wpr_id > 0) {
                    $exists = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $wpr WHERE id=%d AND deleted_at IS NULL LIMIT 1",
                        $wpr_id
                    ), ARRAY_A);
                }else {
                    $exists = false;
                } 
                if ($exists) {
                    $has_hours = false;
                    if ($wpr_id > 0) {
                        //check if any update has been made to role id or rate if not, do nothing
                        if ($exists['role_id'] != $role_id || $exists['rate'] != $rate) {
                            $has_hours = (bool)$wpdb->get_var($wpdb->prepare(
                                "SELECT COUNT(*) FROM $hours_entry WHERE worker_patient_role_id = %d",
                                $wpr_id
                            ));
                        }else {
                            continue; // no changes, skip to next
                        }
                    }
                    if ($has_hours) {
                        // Cerrar el actual (end_date)
                        $wpdb->update($wpr, [
                            'end_date' => $last_payroll_end_date ?? current_time('Y-m-d')
                        ], [
                            'id' => $wpr_id
                        ], [
                            '%s'
                        ], [
                            '%d'
                        ]);
                        // Insertar nueva asignación
                        $wpdb->insert($wpr, [
                            'worker_id'  => $worker_id,
                            'patient_id' => intval($id),
                            'role_id'    => $role_id,
                            'rate'       => $rate,
                            'start_date' => $last_payroll_start_date ?? current_time('Y-m-d'),
                            'created_at' => current_time('mysql'),
                        ], [
                            '%d','%d','%d', $rate === null ? 'NULL' : '%f', '%s','%s'
                        ]);
                    } else {
                        // Actualizar normalmente
                        $wpdb->update($wpr, [
                            'role_id' => $role_id,
                            'rate'    => $rate
                        ], [
                            'id' => $exists['id']
                        ], [
                            '%d', $rate === null ? 'NULL' : '%f'
                        ], [
                            '%d'
                        ]);
                    }
                } else {
                    // Insertar nueva asignación
                    $wpdb->insert($wpr, [
                        'worker_id'  => $worker_id,
                        'patient_id' => intval($id),
                        'role_id'    => $role_id,
                        'rate'       => $rate,
                        'start_date' => current_time('Y-m-d'),
                        'created_at' => current_time('mysql'),
                    ], [
                        '%d','%d','%d', $rate === null ? 'NULL' : '%f', '%s','%s'
                    ]);
                }
            }
        }
        return self::findById($id, false);
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

        //start date for new assignments is start date from last payroll or today if no payrolls yet
        $last_payroll_start_date = $wpdb->get_var($wpdb->prepare(
            "SELECT start_date FROM {$wpdb->prefix}mhc_payrolls ORDER BY end_date DESC LIMIT 1"
        ));        

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
                'start_date' => $last_payroll_start_date ?? current_time('Y-m-d'),
                'created_at' => current_time('mysql'),
            ], [
                '%d','%d','%d', $rate === null ? 'NULL' : '%f', '%s','%s'
            ]);
            if ($wpdb->last_error) return false;
        }
        return true;
    }
}
