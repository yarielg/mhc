<?php

/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Controllers;

use WP_Error;
use wpdb;

class Patients{

    public function register(){

        //Patients
        add_action('wp_ajax_mhc_patients_list',   [$this, 'list']);
        add_action('wp_ajax_mhc_patients_create', [$this, 'create']);
        add_action('wp_ajax_mhc_patients_update', [$this, 'update']);
        add_action('wp_ajax_mhc_patients_delete', [$this, 'delete']);

    }

    protected static function check() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mhc_ajax')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied'], 403);
        }
    }

    public static function list() {
        self::check();
        global $wpdb;

        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";

        $page     = max(1, intval($_POST['page'] ?? 1));
        $per_page = min(100, max(1, intval($_POST['per_page'] ?? 10)));
        $offset   = ($page - 1) * $per_page;

        // Optional search by name
        $search = isset($_POST['search']) ? trim(wp_unslash($_POST['search'])) : '';
        $where  = "WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $where .= " AND (first_name LIKE %s OR last_name LIKE %s OR CONCAT(first_name,' ',last_name) LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $total = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table $where", $params)
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, first_name, last_name, is_active, created_at
                            FROM $table
                            $where
                            ORDER BY id DESC
                            LIMIT %d OFFSET %d", array_merge($params, [$per_page, $offset])),
            ARRAY_A
        );

        wp_send_json_success([
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ]);
    }

    public static function create() {
        self::check();
        global $wpdb;

        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";

        $first = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
        $last  = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));
        $status = intval($_POST['is_active'] ?? 1);

        if ($first === '' || $last === '') {
            wp_send_json_error(['message' => 'First and last name are required'], 400);
        }

        $ok = $wpdb->insert($table, [
            'first_name' => $first,
            'last_name'  => $last,
            'is_active' => $status,
            'created_at' => current_time('mysql'),
        ], ['%s','%s','%s','%s','%s']);

        if (!$ok) wp_send_json_error(['message' => 'DB error creating patient'], 500);

        $id = (int) $wpdb->insert_id;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);

        wp_send_json_success(['item' => $row]);
    }

    public static function update() {
        self::check();
        global $wpdb;

        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);

        $data = [];
        $fmt  = [];

        if (isset($_POST['first_name'])) { $data['first_name'] = sanitize_text_field(wp_unslash($_POST['first_name'])); $fmt[] = '%s'; }
        if (isset($_POST['last_name']))  { $data['last_name']  = sanitize_text_field(wp_unslash($_POST['last_name']));  $fmt[] = '%s'; }
        if (isset($_POST['is_active'])) {
            $st = sanitize_text_field(wp_unslash($_POST['is_active']));
            if (!in_array(intval($st), [1,0], true)) $st = 1;
            $data['is_active'] = $st; $fmt[] = '%s';
        }

        if (!$data) wp_send_json_error(['message' => 'Nothing to update'], 400);

        $ok = $wpdb->update($table, $data, ['id' => $id], $fmt, ['%d']);
        if ($ok === false) wp_send_json_error(['message' => 'DB error updating patient'], 500);

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
        wp_send_json_success(['item' => $row]);
    }

    public static function delete() {
        self::check();
        global $wpdb;

        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_patients";

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);

        $ok = $wpdb->delete($table, ['id' => $id], ['%d']);
        if (!$ok) wp_send_json_error(['message' => 'Unable to delete patient'], 500);

        wp_send_json_success(['id' => $id]);
    }
}