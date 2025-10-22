<?php
namespace Mhc\Inc\Models;

class Worker {

    /** Quick existence check for validation */
    public static function exists($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $id = intval($id);
        if ($id <= 0) return false;
        $found = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE id=%d", $id));
        return $found > 0;
    }

    /** Remote search for supervisors (active workers by name) */
    public static function search($term = '', $excludeId = 0, $limit = 10) {
        global $wpdb;
        $pfx   = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";

        $where = "WHERE 1=1";
        $params = [];

        if ($term !== '') {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $where .= " AND (first_name LIKE %s OR last_name LIKE %s OR CONCAT(first_name,' ',last_name) LIKE %s)";
            array_push($params, $like, $like, $like);
        }

        $where .= " AND is_active = 1";

        if ($excludeId > 0) {
            $where .= " AND id <> %d";
            $params[] = (int)$excludeId;
        }

        $sql = "SELECT id, first_name, last_name FROM $table $where ORDER BY last_name ASC, first_name ASC LIMIT %d";
        $params[] = max(1, intval($limit));

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        foreach ($rows as &$r) {
            $r['full_name'] = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        }
        return $rows;
    }

    public static function findById($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";
        $r     = "{$pfx}mhc_roles";
        $id = intval($id);
        if ($id <= 0) return null;

        // Include supervisor names
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT w.*, s.first_name AS supervisor_first_name, s.last_name AS supervisor_last_name
                 FROM $table AS w
                 LEFT JOIN $table AS s ON s.id = w.supervisor_id
                 WHERE w.id = %d",
                $id
            ), ARRAY_A
        ); // email ya incluido por SELECT w.*
        if (!$row) return null;

        if (!empty($row['supervisor_first_name']) || !empty($row['supervisor_last_name'])) {
            $row['supervisor_full_name'] = trim(($row['supervisor_first_name'] ?? '') . ' ' . ($row['supervisor_last_name'] ?? ''));
        } else {
            $row['supervisor_full_name'] = null;
        }

        // Attach roles
        $roles = $wpdb->get_results($wpdb->prepare(
            "SELECT wr.*, r.code AS role_code, r.name AS role_name, r.is_active AS role_is_active
             FROM $wr AS wr
             LEFT JOIN $r AS r ON r.id = wr.role_id
             WHERE wr.worker_id = %d
             ORDER BY wr.start_date DESC, wr.id DESC",
            $id
        ), ARRAY_A);

        $row['worker_roles'] = $roles;
        $row['roles_count'] = count($roles);
        return $row;
    }

    public static function findAll($search = '', $page = 1, $per_page = 10, $role_id = null, $is_active = null) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";
        $r     = "{$pfx}mhc_roles";
        $offset = ($page - 1) * $per_page;


        $where = "WHERE 1=1";
        $params = [];
        $join_roles = '';
        // Filtro por rol SIEMPRE primero en params si existe
        if (!empty($role_id)) {
            $join_roles = " INNER JOIN $wr AS wrf ON wrf.worker_id = w.id AND wrf.role_id = %d ";
            $params[] = (int)$role_id;
        }
        // Luego filtros de búsqueda
        if ($search !== '') {
            $where .= " AND (w.first_name LIKE %s OR w.last_name LIKE %s OR CONCAT(w.first_name,' ',w.last_name) LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            array_push($params, $like, $like, $like);
        }
        // Filtro por estado
        if ($is_active !== null && $is_active !== '') {
            $where .= " AND w.is_active = %d";
            $params[] = (int)$is_active;
        }

        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT w.id) FROM $table AS w $join_roles $where", $params));

        //return value to debug
        /* wp_send_json_success(["total"=>$total]);
        exit; */

        // Include supervisor name columns
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT w.*, s.first_name AS supervisor_first_name, s.last_name AS supervisor_last_name
                 FROM $table AS w
                 $join_roles
                 LEFT JOIN $table AS s ON s.id = w.supervisor_id
                 $where
                 ORDER BY w.id DESC
                 LIMIT %d OFFSET %d",
                array_merge($params, [$per_page, $offset])
            ), ARRAY_A
        ); // email ya incluido por SELECT w.*

        // Attach roles to each worker
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $roles_by_worker = [];
        if ($ids) {
            $in = implode(',', array_map('intval', $ids));
            $roles = $wpdb->get_results(
                "SELECT wr.*, r.code AS role_code, r.name AS role_name, r.is_active AS role_is_active
                 FROM $wr AS wr
                 LEFT JOIN $r AS r ON r.id = wr.role_id
                 WHERE wr.worker_id IN ($in)
                 ORDER BY wr.worker_id, wr.start_date DESC, wr.id DESC",
                ARRAY_A
            );
            foreach ($roles as $row) {
                $wid = (int)$row['worker_id'];
                if (!isset($roles_by_worker[$wid])) $roles_by_worker[$wid] = [];
                $roles_by_worker[$wid][] = $row;
            }
        }

        foreach ($rows as &$rr) {
            $wid = (int)$rr['id'];
            $rr['worker_roles'] = $roles_by_worker[$wid] ?? [];
            $rr['roles_count']  = count($rr['worker_roles']);
            if (!empty($rr['supervisor_first_name']) || !empty($rr['supervisor_last_name'])) {
                $rr['supervisor_full_name'] = trim(($rr['supervisor_first_name'] ?? '') . ' ' . ($rr['supervisor_last_name'] ?? ''));
            } else {
                $rr['supervisor_full_name'] = null;
            }
        }

        return [
            'items' => $rows,
            'total' => $total,
        ];
    }

    public static function create($data) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";

        $fields = [];
        $fmts   = [];
        foreach (["first_name","last_name","email","company","qb_vendor_id","is_active","start_date","end_date","supervisor_id"] as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
                $fmts[] = in_array($field,["is_active","supervisor_id"],true)?'%d':'%s';
            }
        }
        $fields['created_at'] = current_time('mysql');
        $fmts[] = '%s';

        $wpdb->query('START TRANSACTION');
        $ok = $wpdb->insert($table, $fields, $fmts);
        if (!$ok) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        $worker_id = (int)$wpdb->insert_id;

        // Handle role assignments
        $roles_payload = [];
        if (!empty($data['worker_roles'])) {
            $roles_payload = json_decode($data['worker_roles'], true);
            if (!is_array($roles_payload)) $roles_payload = [];
        }

        foreach ($roles_payload as $r) {
            $role_id      = isset($r['role_id']) ? (int)$r['role_id'] : 0;
            $general_rate = isset($r['general_rate']) && $r['general_rate'] !== '' ? (float)$r['general_rate'] : null;
            $start_date   = isset($r['start_date']) && $r['start_date'] !== '' ? sanitize_text_field($r['start_date']) : date('Y-m-d');
            $end_date     = isset($r['end_date']) && $r['end_date'] !== '' ? sanitize_text_field($r['end_date']) : null;
            if ($role_id <= 0) continue;
            if ($end_date !== null && $end_date < $start_date) continue;
            $wpdb->insert($wr, [
                'worker_id'    => $worker_id,
                'role_id'      => $role_id,
                'general_rate' => $general_rate,
                'start_date'   => $start_date,
                'end_date'     => $end_date,
                'created_at'   => current_time('mysql'),
            ], ['%d','%d','%f','%s','%s','%s']);
        }

        $item = self::findById($worker_id);
        $wpdb->query('COMMIT');
        return $item;
    }

    public static function update($id, $data) {
        global $wpdb;
        $pfx   = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";
        $id    = intval($id);

        $fields = [];
        $fmts   = [];

        // fields except supervisor_id first
        foreach (["first_name","last_name","email","company","qb_vendor_id","is_active","start_date","end_date"] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[$field] = $data[$field];
                $fmts[] = ($field === "is_active") ? '%d' : '%s';
            }
        }
        // Siempre actualiza updated_at si hay cambios
        if ($fields) {
            $fields['updated_at'] = current_time('mysql');
            $fmts[] = '%s';
        }

        $wpdb->query('START TRANSACTION');

        // Update the basic fields (if any)
        if ($fields) {
            $ok = $wpdb->update($table, $fields, ['id' => $id], $fmts, ['%d']);
            if ($ok === false) { $wpdb->query('ROLLBACK'); return false; }
        }

        // supervisor_id requires special handling for NULL
        if (array_key_exists('supervisor_id', $data)) {
            $sup = $data['supervisor_id'];
            if ($sup === '' || $sup === null) {
                // truly remove supervisor
                $ok = $wpdb->query(
                    $wpdb->prepare("UPDATE $table SET supervisor_id = NULL, updated_at = %s WHERE id = %d",
                        current_time('mysql'), $id
                    )
                );
            } else {
                // set to a specific supervisor
                $ok = $wpdb->update($table,
                    ['supervisor_id' => (int)$sup, 'updated_at' => current_time('mysql')],
                    ['id' => $id],
                    ['%d','%s'], ['%d']
                );
            }
            if ($ok === false) { $wpdb->query('ROLLBACK'); return false; }
        }

        // Replace role assignments (unchanged)
        $wpdb->delete($wr, ['worker_id' => $id], ['%d']);
        $roles_payload = [];
        if (!empty($data['worker_roles'])) {
            $roles_payload = json_decode($data['worker_roles'], true);
            if (!is_array($roles_payload)) $roles_payload = [];
        }
        foreach ($roles_payload as $r) {
            $role_id      = isset($r['role_id']) ? (int)$r['role_id'] : 0;
            $general_rate = (isset($r['general_rate']) && $r['general_rate'] !== '') ? (float)$r['general_rate'] : null;
            $start_date   = (!empty($r['start_date'])) ? sanitize_text_field($r['start_date']) : date('Y-m-d');
            $end_date     = (!empty($r['end_date'])) ? sanitize_text_field($r['end_date']) : null;
            if ($role_id <= 0) continue;
            if ($end_date !== null && $end_date < $start_date) continue;

            $wpdb->insert($wr, [
                'worker_id'    => $id,
                'role_id'      => $role_id,
                'general_rate' => $general_rate,
                'start_date'   => $start_date,
                'end_date'     => $end_date,
                'created_at'   => current_time('mysql'),
            ], ['%d','%d','%f','%s','%s','%s']);
        }

        $item = self::findById($id);
        $wpdb->query('COMMIT');
        return $item;
    }

    public static function delete($id) {
        global $wpdb;
        $pfx   = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";
        $id    = intval($id);

        $wpdb->query('START TRANSACTION');

        // orphan any direct reports (set to NULL properly)
        $ok = $wpdb->query(
            $wpdb->prepare("UPDATE $table SET supervisor_id = NULL WHERE supervisor_id = %d", $id)
        );
        if ($ok === false) { $wpdb->query('ROLLBACK'); return false; }

        $wpdb->delete($wr, ['worker_id' => $id], ['%d']);
        $ok = $wpdb->delete($table, ['id' => $id], ['%d']);
        if (!$ok) { $wpdb->query('ROLLBACK'); return false; }

        $wpdb->query('COMMIT');
        return true;
    }
}
