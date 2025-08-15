<?php
/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Controllers;

use Mhc\Inc\Models\SpecialRate;

class SpecialRatesController
{
    const NONCE_ACTION = 'mhc_ajax';
    const CAPABILITY   = 'manage_options'; // ajusta si usas otra cap

    public function register()
    {
        add_action('wp_ajax_mhc_special_rates_list',   [$this, 'list']);
        add_action('wp_ajax_mhc_special_rates_create', [$this, 'create']);
        add_action('wp_ajax_mhc_special_rates_update', [$this, 'update']);
        add_action('wp_ajax_mhc_special_rates_delete', [$this, 'delete']);
        add_action('wp_ajax_mhc_special_rates_get',    [$this, 'getById']);
    }

    private static function check()
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        $nonce = $_REQUEST['_wpnonce'] ?? ($_REQUEST['nonce'] ?? '');
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
        }
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
        $result = SpecialRate::findAll($args);
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
            'code' => sanitize_text_field(wp_unslash($_POST['code'] ?? '')),
            'label' => sanitize_text_field(wp_unslash($_POST['label'] ?? '')),
            'cpt_code' => sanitize_text_field(wp_unslash($_POST['cpt_code'] ?? '')),
            'unit_rate' => floatval($_POST['unit_rate'] ?? 0),
            'is_active' => intval($_POST['is_active'] ?? 1),
        ];
        if ($data['code'] === '' || $data['label'] === '') {
            wp_send_json_error(['message' => 'Code and label are required'], 400);
        }
        $item = SpecialRate::create($data);
        if (!$item) wp_send_json_error(['message' => 'DB error creating special rate'], 500);
        wp_send_json_success(['item' => $item]);
    }

    public static function update()
    {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $data = [];
        if (isset($_POST['code'])) $data['code'] = sanitize_text_field(wp_unslash($_POST['code']));
        if (isset($_POST['label'])) $data['label'] = sanitize_text_field(wp_unslash($_POST['label']));
        if (isset($_POST['cpt_code'])) $data['cpt_code'] = sanitize_text_field(wp_unslash($_POST['cpt_code']));
        if (isset($_POST['unit_rate'])) $data['unit_rate'] = floatval($_POST['unit_rate']);
        if (isset($_POST['is_active'])) $data['is_active'] = intval($_POST['is_active']);
        if (!$data) wp_send_json_error(['message' => 'Nothing to update'], 400);
        $item = SpecialRate::update($id, $data);
        if (!$item) wp_send_json_error(['message' => 'DB error updating special rate'], 500);
        wp_send_json_success(['item' => $item]);
    }

    public static function delete()
    {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $ok = SpecialRate::delete($id);
        if (!$ok) wp_send_json_error(['message' => 'Unable to delete special rate'], 500);
        wp_send_json_success(['id' => $id]);
    }

    public static function getById()
    {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $item = SpecialRate::findById($id);
        if (!$item) wp_send_json_error(['message' => 'Special rate not found'], 404);
        wp_send_json_success(['item' => $item]);
    }
}
