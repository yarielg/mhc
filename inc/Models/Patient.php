<?php
namespace Mhc\Inc\Models;

class Patient {


    public static function findById($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
        $id = intval($id);
        if ($id <= 0) return null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
        return $row ?: null;
    }

    public static function findAll($search = '', $page = 1, $per_page = 10) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
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
        foreach (["first_name","last_name","is_active","start_date","end_date"] as $field) {
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
        return self::findById($id);
    }

    public static function update($id, $data) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
        $fields = [];
        $fmts = [];
        foreach (["first_name","last_name","is_active","start_date","end_date"] as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
                $fmts[] = $field === "is_active" ? '%d' : '%s';
            }
        }
        if (!$fields) return false;
        $ok = $wpdb->update($table, $fields, ['id' => intval($id)], $fmts, ['%d']);
        if ($ok === false) return false;
        return self::findById($id);
    }

    public static function delete($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";
        $ok = $wpdb->delete($table, ['id' => intval($id)], ['%d']);
        return $ok !== false;
    }
}
