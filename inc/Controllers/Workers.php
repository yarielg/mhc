<?php
/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Controllers;

use WP_Error;
use wpdb;

class Workers {

    public function register() {
        add_action('wp_ajax_mhc_workers_list',   [$this, 'list']);
        add_action('wp_ajax_mhc_workers_create', [$this, 'create']);
        add_action('wp_ajax_mhc_workers_update', [$this, 'update']);
        add_action('wp_ajax_mhc_workers_delete', [$this, 'delete']);
    }

    protected static function check() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mhc_ajax')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
    }

    /** Utility: decode JSON safely */
    protected static function json_array($key) : array {
        if (!isset($_POST[$key])) return [];
        $raw = wp_unslash($_POST[$key]);
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    /** Utility: load role assignments for a set of worker IDs, grouped by worker_id */
    protected static function load_roles_for_workers($worker_ids) : array {
        if (empty($worker_ids)) return [];

        global $wpdb;
        $pfx = $wpdb->prefix;
        $wr  = "{$pfx}mhc_worker_roles";
        $r   = "{$pfx}mhc_roles";

        // Get assignments; join roles to enrich option labels if you need on UI later
        $in  = implode(',', array_map('intval', $worker_ids));
        $sql = "
            SELECT wr.id, wr.worker_id, wr.role_id, wr.general_rate, wr.start_date, wr.end_date,
                   r.code AS role_code, r.name AS role_name, r.is_active AS role_is_active
            FROM $wr AS wr
            LEFT JOIN $r AS r ON r.id = wr.role_id
            WHERE wr.worker_id IN ($in)
            ORDER BY wr.worker_id, wr.start_date DESC, wr.id DESC
        ";
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $by_worker = [];
        foreach ($rows as $row) {
            $wid = (int) $row['worker_id'];
            if (!isset($by_worker[$wid])) $by_worker[$wid] = [];
            $by_worker[$wid][] = [
                'id'           => (int)$row['id'],
                'worker_id'    => $wid,
                'role_id'      => (int)$row['role_id'],
                'general_rate' => ($row['general_rate'] === null) ? null : (float)$row['general_rate'],
                'start_date'   => $row['start_date'] ?: '',
                'end_date'     => $row['end_date'] ?: '',
                // Optional display helpers
                'role_code'    => $row['role_code'] ?? null,
                'role_name'    => $row['role_name'] ?? null,
                'role_is_active'=> isset($row['role_is_active']) ? (int)$row['role_is_active'] : null,
            ];
        }
        return $by_worker;
    }

    /** GET: list with search + pagination, including role assignments */
    public static function list() {
        self::check();
        global $wpdb;

        $pfx   = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";

        $page     = max(1, intval($_POST['page'] ?? 1));
        $per_page = min(100, max(1, intval($_POST['per_page'] ?? 10)));
        $offset   = ($page - 1) * $per_page;

        $search = isset($_POST['search']) ? trim(wp_unslash($_POST['search'])) : '';
        $where  = "WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $where .= " AND (first_name LIKE %s OR last_name LIKE %s OR CONCAT(first_name,' ',last_name) LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM $table $where", $params) );

        $rows = $wpdb->get_results(
            $wpdb->prepare("
                SELECT id, first_name, last_name, is_active, created_at
                FROM $table
                $where
                ORDER BY id DESC
                LIMIT %d OFFSET %d
            ", array_merge($params, [$per_page, $offset])),
            ARRAY_A
        );

        // Attach role assignments to each returned worker (for edit dialog & count column)
        $ids = array_map(fn($r) => (int)$r['id'], $rows);
        $roles_by_worker = self::load_roles_for_workers($ids);

        foreach ($rows as &$r) {
            $wid = (int)$r['id'];
            $r['worker_roles'] = $roles_by_worker[$wid] ?? [];
            $r['roles_count']  = count($r['worker_roles']);
        }

        wp_send_json_success([
            'items'     => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $per_page,
        ]);
    }

    /** POST: create worker + (optional) role assignments */
    public static function create() {
        self::check();
        global $wpdb;

        $pfx   = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";

        $first  = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last   = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $status = (int)($_POST['is_active'] ?? 1);

        if ($first === '' || $last === '') {
            wp_send_json_error(['message' => 'First and last name are required'], 400);
        }

        // NOTE: per your request, we DO NOT store worker-level start/end dates here.
        // Dates exist only on role assignments.

        // Begin a transaction if supported (InnoDB)
        $wpdb->query('START TRANSACTION');

        $ok = $wpdb->insert($table, [
            'first_name' => $first,
            'last_name'  => $last,
            'is_active'  => $status,
            'created_at' => current_time('mysql'),
        ], ['%s','%s','%d','%s']);

        if (!$ok) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'DB error creating worker'], 500);
        }

        $worker_id = (int)$wpdb->insert_id;

        // Handle role assignments
        $roles_payload = self::json_array('worker_roles');
        foreach ($roles_payload as $r) {
            $role_id      = isset($r['role_id']) ? (int)$r['role_id'] : 0;
            $general_rate = isset($r['general_rate']) && $r['general_rate'] !== '' ? (float)$r['general_rate'] : null;

            if ($role_id <= 0) continue;                  // skip invalid rows

            $wpdb->insert($wr, [
                'worker_id'    => $worker_id,
                'role_id'      => $role_id,
                'general_rate' => $general_rate,
                'created_at'   => current_time('mysql'),
            ], ['%d','%d','%f','%s','%s','%s']);
        }

        // Build response item with roles
        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $worker_id), ARRAY_A);
        $roles_by_worker = self::load_roles_for_workers([$worker_id]);
        $item['worker_roles'] = $roles_by_worker[$worker_id] ?? [];

        $wpdb->query('COMMIT');

        wp_send_json_success(['item' => $item]);
    }

    /** POST: update worker + replace role assignments */
    public static function update() {
        self::check();
        global $wpdb;

        $pfx   = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);

        $data = [];
        $fmt  = [];

        if (isset($_POST['first_name'])) { $data['first_name'] = sanitize_text_field(wp_unslash($_POST['first_name'])); $fmt[] = '%s'; }
        if (isset($_POST['last_name']))  { $data['last_name']  = sanitize_text_field(wp_unslash($_POST['last_name']));  $fmt[] = '%s'; }
        if (isset($_POST['is_active']))  { $data['is_active']  = (int)$_POST['is_active'] ? 1 : 0; $fmt[] = '%d'; }

        if (!$data) wp_send_json_error(['message' => 'Nothing to update'], 400);

        $wpdb->query('START TRANSACTION');

        $ok = $wpdb->update($table, $data, ['id' => $id], $fmt, ['%d']);
        if ($ok === false) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'DB error updating worker'], 500);
        }

        // Replace role assignments with payload (simplest approach)
        $roles_payload = self::json_array('worker_roles');

        // Delete current
        $wpdb->delete($wr, ['worker_id' => $id], ['%d']);

        // Re-insert
        foreach ($roles_payload as $r) {
            $role_id      = isset($r['role_id']) ? (int)$r['role_id'] : 0;
            $general_rate = isset($r['general_rate']) && $r['general_rate'] !== '' ? (float)$r['general_rate'] : null;
            $start_date   = isset($r['start_date']) ? sanitize_text_field($r['start_date']) : '';
            $end_date     = isset($r['end_date'])   ? sanitize_text_field($r['end_date'])   : '';

            if ($role_id <= 0) continue;
            if ($start_date === '') continue;
            if ($end_date !== '' && $end_date < $start_date) continue;

            $wpdb->insert($wr, [
                'worker_id'    => $id,
                'role_id'      => $role_id,
                'general_rate' => $general_rate,
                'start_date'   => $start_date,
                'end_date'     => ($end_date !== '' ? $end_date : null),
                'created_at'   => current_time('mysql'),
            ], ['%d','%d','%f','%s','%s','%s']);
        }

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
        $roles_by_worker = self::load_roles_for_workers([$id]);
        $item['worker_roles'] = $roles_by_worker[$id] ?? [];

        $wpdb->query('COMMIT');

        wp_send_json_success(['item' => $item]);
    }

    /** POST: delete worker (and its role assignments) */
    public static function delete() {
        self::check();
        global $wpdb;

        $pfx   = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";
        $wr    = "{$pfx}mhc_worker_roles";

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);

        $wpdb->query('START TRANSACTION');

        // First remove role rows to keep FK-like integrity (even if no actual FK)
        $wpdb->delete($wr, ['worker_id' => $id], ['%d']);

        $ok = $wpdb->delete($table, ['id' => $id], ['%d']);
        if (!$ok) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'Unable to delete worker'], 500);
        }

        $wpdb->query('COMMIT');

        wp_send_json_success(['id' => $id]);
    }
}
