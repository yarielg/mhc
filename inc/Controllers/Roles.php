<?php
/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Controllers;

use WP_Error;
use wpdb;

class Roles{
    

    public function register(){
        add_action('wp_ajax_mhc_roles_list',   [$this, 'list']);
        add_action('wp_ajax_mhc_roles_create', [$this, 'create']);
        add_action('wp_ajax_mhc_roles_update', [$this, 'update']);
        add_action('wp_ajax_mhc_roles_delete', [$this, 'delete']);
        add_action('wp_ajax_mhc_roles_get',    [$this, 'getById']);
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
        $table = "{$pfx}mhc_roles";

        $search = isset($_POST['search']) ? trim(wp_unslash($_POST['search'])) : '';
        $where  = "WHERE 1=1";
        $params = [];

        if ($search !== '') {
            $where .= " AND (code LIKE %s OR name LIKE %s)";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like; $params[] = $like;
        }

        $total = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM $table $where", $params)
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table $where ORDER BY id DESC", $params),
            ARRAY_A
        );

        wp_send_json_success([
            'items' => $rows,
            'total' => $total,
        ]);
    }

    public static function create() {
        self::check();
        global $wpdb;

        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_roles";

        $code = sanitize_text_field(wp_unslash($_POST['code'] ?? ''));
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $billable = intval($_POST['billable'] ?? 1);
        $notes = sanitize_text_field(wp_unslash($_POST['notes'] ?? ''));
        $is_active = intval($_POST['is_active'] ?? 1);

        if ($code === '' || $name === '') {
            wp_send_json_error(['message' => 'Code and name are required'], 400);
        }

        $ok = $wpdb->insert($table, [
            'code' => $code,
            'name' => $name,
            'billable' => $billable,
            'notes' => $notes,
            'is_active' => $is_active,
            'created_at' => current_time('mysql'),
        ], ['%s','%s','%d','%s','%d','%s']);

        if (!$ok) wp_send_json_error(['message' => 'DB error creating role'], 500);

        $id = (int) $wpdb->insert_id;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);

        wp_send_json_success(['item' => $row]);
    }

    public static function update() {
        self::check();
        global $wpdb;

        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_roles";

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);

        $data = [];
        $fmt  = [];

        if (isset($_POST['code'])) { $data['code'] = sanitize_text_field(wp_unslash($_POST['code'])); $fmt[] = '%s'; }
        if (isset($_POST['name'])) { $data['name'] = sanitize_text_field(wp_unslash($_POST['name'])); $fmt[] = '%s'; }
        if (isset($_POST['billable'])) { $data['billable'] = intval($_POST['billable']); $fmt[] = '%d'; }
        if (isset($_POST['notes'])) { $data['notes'] = sanitize_text_field(wp_unslash($_POST['notes'])); $fmt[] = '%s'; }
        if (isset($_POST['is_active'])) { $data['is_active'] = intval($_POST['is_active']); $fmt[] = '%d'; }

        if (!$data) wp_send_json_error(['message' => 'Nothing to update'], 400);

        $ok = $wpdb->update($table, $data, ['id' => $id], $fmt, ['%d']);
        if ($ok === false) wp_send_json_error(['message' => 'DB error updating role'], 500);

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
        wp_send_json_success(['item' => $row]);
    }

    public static function delete() {
        self::check();
        global $wpdb;

        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_roles";

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);

        $ok = $wpdb->delete($table, ['id' => $id], ['%d']);
        if (!$ok) wp_send_json_error(['message' => 'Unable to delete role'], 500);

        wp_send_json_success(['id' => $id]);
    }
    
    public static function getById() {
        self::check();
        global $wpdb;

        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_roles";

        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
        if (!$row) wp_send_json_error(['message' => 'Role not found'], 404);

        wp_send_json_success(['item' => $row]);
    }
}
