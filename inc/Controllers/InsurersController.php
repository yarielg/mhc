<?php
/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Controllers;

use Mhc\Inc\Models\Insurer;

class InsurersController
{

    public function register()
    {
        add_action('wp_ajax_mhc_insurers_list',   [$this, 'list']);
        add_action('wp_ajax_mhc_insurers_create', [$this, 'create']);
        add_action('wp_ajax_mhc_insurers_update', [$this, 'update']);
        add_action('wp_ajax_mhc_insurers_delete', [$this, 'delete']);
        add_action('wp_ajax_mhc_insurers_get',    [$this, 'getById']);
    }

    private static function check()
    {
        if (!function_exists('mhc_check_ajax_access')) {
            require_once dirname(__DIR__, 2) . '/util/helpers.php';
        }
        mhc_check_ajax_access();
    }

    public static function list()
    {
        self::check();
        $search = isset($_POST['search']) ? trim(wp_unslash($_POST['search'])) : '';
        $page = isset($_POST['page']) ? intval($_POST['page']) : null;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : null;
        $args = ['search' => $search];
        if ($page && $per_page) {
            $args['limit'] = $per_page;
            $args['offset'] = ($page - 1) * $per_page;
        }
        $result = Insurer::findAll($args);
        if (is_array($result) && isset($result['items']) && isset($result['total'])) {
            wp_send_json_success([
                'items' => $result['items'],
                'total' => $result['total'],
            ]);
        } else {
            wp_send_json_success([
                'items' => $result,
                'total' => is_array($result) ? count($result) : 0,
            ]);
        }
    }

    public static function create()
    {
        self::check();
        $data = [
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'is_active' => intval($_POST['is_active'] ?? 1),
        ];
        if ($data['name'] === '') {
            wp_send_json_error(['message' => 'Name is required'], 400);
        }
        $item = Insurer::create($data);
        if (!$item) wp_send_json_error(['message' => 'DB error creating insurer'], 500);
        wp_send_json_success(['item' => $item]);
    }

    public static function update()
    {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $data = [];
        if (isset($_POST['name'])) $data['name'] = sanitize_text_field(wp_unslash($_POST['name']));
        if (isset($_POST['is_active'])) $data['is_active'] = intval($_POST['is_active']);
        if (!$data) wp_send_json_error(['message' => 'Nothing to update'], 400);
        $item = Insurer::update($id, $data);
        if (!$item) wp_send_json_error(['message' => 'DB error updating insurer'], 500);
        wp_send_json_success(['item' => $item]);
    }

    public static function delete()
    {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $ok = Insurer::delete($id);
        if (!$ok) wp_send_json_error(['message' => 'Unable to delete insurer'], 500);
        wp_send_json_success(['id' => $id]);
    }

    public static function getById()
    {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $item = Insurer::findById($id);
        if (!$item) wp_send_json_error(['message' => 'Insurer not found'], 404);
        wp_send_json_success(['item' => $item]);
    }
}
