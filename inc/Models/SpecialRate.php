<?php
namespace Mhc\Inc\Models;

class SpecialRate {
    public static function findById($id) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_special_rates";
        $id = intval($id);
        if ($id <= 0) return null;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
        return $row ?: null;
    }

    public static function findAll($args = []) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_special_rates";
        $where = "WHERE 1=1";
        $params = [];
        if (isset($args['search']) && $args['search'] !== '') {
            $where .= " AND (code LIKE %s OR label LIKE %s)";
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $like; $params[] = $like;
        }
        $sql = "SELECT * FROM $table $where ORDER BY id DESC";
        $total = null;
        if (isset($args['limit']) && isset($args['offset'])) {
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table $where", $params));
            $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", intval($args['limit']), intval($args['offset']));
        }
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if ($total !== null) {
            return [ 'items' => $rows, 'total' => $total ];
        }
        return $rows;
    }

    public static function create($data) {
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_special_rates";
        $fields = [];
        $fmts = [];
        foreach (["code","label","cpt_code","unit_rate","is_active"] as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
                $fmts[] = $field === "is_active" ? '%d' : ($field === "unit_rate" ? '%f' : '%s');
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
        $table = "{$pfx}mhc_special_rates";
        $fields = [];
        $fmts = [];
        foreach (["code","label","cpt_code","unit_rate","is_active"] as $field) {
            if (isset($data[$field])) {
                $fields[$field] = $data[$field];
                $fmts[] = $field === "is_active" ? '%d' : ($field === "unit_rate" ? '%f' : '%s');
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
        $table = "{$pfx}mhc_special_rates";
        $ok = $wpdb->delete($table, ['id' => intval($id)], ['%d']);
        return $ok !== false;
    }
}
