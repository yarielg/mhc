<?php
/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Controllers;

use Mhc\Inc\Models\SpecialRate;

class SpecialRatesController{
    

    public function register(){
        add_action('wp_ajax_mhc_special_rates_list',   [$this, 'list']);
        add_action('wp_ajax_mhc_special_rates_create', [$this, 'create']);
        add_action('wp_ajax_mhc_special_rates_update', [$this, 'update']);
        add_action('wp_ajax_mhc_special_rates_delete', [$this, 'delete']);
        add_action('wp_ajax_mhc_special_rates_get',    [$this, 'getById']);
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
        $result = SpecialRate::findAll($search);
        wp_send_json_success([
            'items' => $result['items'],
            'total' => $result['total'],
        ]);
    }

    public static function create() {
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

    public static function update() {
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

    public static function delete() {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $ok = SpecialRate::delete($id);
        if (!$ok) wp_send_json_error(['message' => 'Unable to delete special rate'], 500);
        wp_send_json_success(['id' => $id]);
    }

    public static function getById() {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $item = SpecialRate::findById($id);
        if (!$item) wp_send_json_error(['message' => 'Special rate not found'], 404);
        wp_send_json_success(['item' => $item]);
    }
}
