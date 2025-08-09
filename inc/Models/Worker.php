<?php
namespace Mhc\Inc\Models;

class Worker {
    
    
    public static function findById($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $id = intval($id);
        if ($id <= 0) return null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
        return $row ?: null;
    }

    public static function findAll($args = []) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $where = "WHERE 1=1";
        $params = [];
        if (isset($args['search']) && $args['search'] !== '') {
            $where .= " AND (first_name LIKE %s OR last_name LIKE %s OR CONCAT(first_name,' ',last_name) LIKE %s)";
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        $sql = "SELECT * FROM $table $where ORDER BY id DESC";
        if (isset($args['limit']) && isset($args['offset'])) {
            $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", intval($args['limit']), intval($args['offset']));
        }
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return $rows;
    }

    public static function create($data) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $fields = [];
        $fmts = [];
        $values = [];
        foreach (["first_name","last_name","is_active","start_date","end_date","supervisor_id"] as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
                $fmts[] = in_array($field,["is_active","supervisor_id"],true)?'%d':'%s';
            }
        }
        $fields['created_at'] = current_time('mysql');
        $fmts[] = '%s';
        $ok = $wpdb->insert($table, $fields, $fmts);
        if (!$ok) return false;
        return $wpdb->insert_id;
    }

    public static function update($id, $data) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $fields = [];
        $fmts = [];
        foreach (["first_name","last_name","is_active","start_date","end_date","supervisor_id"] as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
                $fmts[] = in_array($field,["is_active","supervisor_id"],true)?'%d':'%s';
            }
        }
        if (!$fields) return false;
        $ok = $wpdb->update($table, $fields, ['id' => intval($id)], $fmts, ['%d']);
        return $ok !== false;
    }

    public static function delete($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $ok = $wpdb->delete($table, ['id' => intval($id)], ['%d']);
        return $ok !== false;
    }
}
