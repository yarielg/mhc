<?php
/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Controllers;

use Mhc\Inc\Models\Worker;

class WorkersController {

    public function register() {
        add_action('wp_ajax_mhc_workers_list',   [$this, 'list']);
        add_action('wp_ajax_mhc_workers_create', [$this, 'create']);
        add_action('wp_ajax_mhc_workers_update', [$this, 'update']);
        add_action('wp_ajax_mhc_workers_delete', [$this, 'delete']);
        add_action('wp_ajax_mhc_workers_get',    [$this, 'getById']);
    }

    public static function getById() {
        self::check();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $item = Worker::findById($id);
        if (!$item) wp_send_json_error(['message' => 'Worker not found'], 404);
        wp_send_json_success(['item' => $item]);
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
        $page     = max(1, intval($_POST['page'] ?? 1));
        $per_page = min(100, max(1, intval($_POST['per_page'] ?? 10)));
        $search = isset($_POST['search']) ? trim(wp_unslash($_POST['search'])) : '';
        $result = Worker::findAll($search, $page, $per_page);
        wp_send_json_success([
            'items'     => $result['items'],
            'total'     => $result['total'],
            'page'      => $page,
            'per_page'  => $per_page,
        ]);
    }

    public static function create() {
        self::check();
        $data = [
            'first_name' => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name'  => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'is_active'  => (int)($_POST['is_active'] ?? 1),
            'worker_roles' => isset($_POST['worker_roles']) ? wp_unslash($_POST['worker_roles']) : '[]',
        ];
        if ($data['first_name'] === '' || $data['last_name'] === '') {
            wp_send_json_error(['message' => 'First and last name are required'], 400);
        }
        $item = Worker::create($data);
        if (!$item) wp_send_json_error(['message' => 'DB error creating worker'], 500);
        wp_send_json_success(['item' => $item]);
    }

    public static function update() {
        self::check();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $data = [];
        if (isset($_POST['first_name'])) $data['first_name'] = sanitize_text_field(wp_unslash($_POST['first_name']));
        if (isset($_POST['last_name']))  $data['last_name']  = sanitize_text_field(wp_unslash($_POST['last_name']));
        if (isset($_POST['is_active']))  $data['is_active']  = (int)$_POST['is_active'] ? 1 : 0;
        $data['worker_roles'] = isset($_POST['worker_roles']) ? wp_unslash($_POST['worker_roles']) : '[]';
        if (!$data) wp_send_json_error(['message' => 'Nothing to update'], 400);
        $item = Worker::update($id, $data);
        if (!$item) wp_send_json_error(['message' => 'DB error updating worker'], 500);
        wp_send_json_success(['item' => $item]);
    }

    public static function delete() {
        self::check();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $ok = Worker::delete($id);
        if (!$ok) wp_send_json_error(['message' => 'Unable to delete worker'], 500);
        wp_send_json_success(['id' => $id]);
    }
}
