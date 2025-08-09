<?php
namespace Mhc\Inc\Models;

class Worker {
    
    
    public static function findById($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";
        $r     = "{$pfx}mhc_roles";
        $id = intval($id);
        if ($id <= 0) return null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
        if (!$row) return null;
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

    public static function findAll($search = '', $page = 1, $per_page = 10) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";
        $r     = "{$pfx}mhc_roles";
        $offset = ($page - 1) * $per_page;
        $where = "WHERE 1=1";
        $params = [];
        if ($search !== '') {
            $where .= " AND (first_name LIKE %s OR last_name LIKE %s OR CONCAT(first_name,' ',last_name) LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table $where", $params));
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge($params, [$per_page, $offset])),
            ARRAY_A
        );
        // Attach roles to each worker
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $roles_by_worker = [];
        if ($ids) {
            $in = implode(',', $ids);
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
        foreach ($rows as &$r) {
            $wid = (int)$r['id'];
            $r['worker_roles'] = $roles_by_worker[$wid] ?? [];
            $r['roles_count']  = count($r['worker_roles']);
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
        $fmts = [];
        foreach (["first_name","last_name","is_active","start_date","end_date","supervisor_id"] as $field) {
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
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";
        $fields = [];
        $fmts = [];
        foreach (["first_name","last_name","is_active","start_date","end_date","supervisor_id"] as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
                $fmts[] = in_array($field,["is_active","supervisor_id"],true)?'%d':'%s';
            }
        }
        if (!$fields) return false;
        $wpdb->query('START TRANSACTION');
        $ok = $wpdb->update($table, $fields, ['id' => intval($id)], $fmts, ['%d']);
        if ($ok === false) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        // Replace role assignments
        $wpdb->delete($wr, ['worker_id' => $id], ['%d']);
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
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";
        $wpdb->query('START TRANSACTION');
        $wpdb->delete($wr, ['worker_id' => intval($id)], ['%d']);
        $ok = $wpdb->delete($table, ['id' => intval($id)], ['%d']);
        if (!$ok) {
            $wpdb->query('ROLLBACK');
            return false;
        }
        $wpdb->query('COMMIT');
        return true;
    }
}
