<?php
/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Controllers;

use Mhc\Inc\Models\Role;

class RolesController{
    

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
        $search = isset($_POST['search']) ? trim(wp_unslash($_POST['search'])) : '';
        $result = Role::findAll(['search' => $search]);
        wp_send_json_success([
            'items' => $result,
            'total' => count($result),
        ]);
    }

    public static function create() {
        self::check();
        $data = [
            'code' => sanitize_text_field(wp_unslash($_POST['code'] ?? '')),
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'billable' => intval($_POST['billable'] ?? 1),
            'notes' => sanitize_text_field(wp_unslash($_POST['notes'] ?? '')),
            'is_active' => intval($_POST['is_active'] ?? 1),
        ];
        if ($data['code'] === '' || $data['name'] === '') {
            wp_send_json_error(['message' => 'Code and name are required'], 400);
        }
        $id = Role::create($data);
        if (!$id) wp_send_json_error(['message' => 'DB error creating role'], 500);
        $item = Role::findById($id);
        wp_send_json_success(['item' => $item]);
    }

    public static function update() {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $data = [];
        if (isset($_POST['code'])) $data['code'] = sanitize_text_field(wp_unslash($_POST['code']));
        if (isset($_POST['name'])) $data['name'] = sanitize_text_field(wp_unslash($_POST['name']));
        if (isset($_POST['billable'])) $data['billable'] = intval($_POST['billable']);
        if (isset($_POST['notes'])) $data['notes'] = sanitize_text_field(wp_unslash($_POST['notes']));
        if (isset($_POST['is_active'])) $data['is_active'] = intval($_POST['is_active']);
        if (!$data) wp_send_json_error(['message' => 'Nothing to update'], 400);
        $ok = Role::update($id, $data);
        if (!$ok) wp_send_json_error(['message' => 'DB error updating role'], 500);
        $item = Role::findById($id);
        wp_send_json_success(['item' => $item]);
    }

    public static function delete() {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $ok = Role::delete($id);
        if (!$ok) wp_send_json_error(['message' => 'Unable to delete role'], 500);
        wp_send_json_success(['id' => $id]);
    }
    
    public static function getById() {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $item = Role::findById($id);
        if (!$item) wp_send_json_error(['message' => 'Role not found'], 404);
        wp_send_json_success(['item' => $item]);
    }
}
