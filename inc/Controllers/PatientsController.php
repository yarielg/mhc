<?php

/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Controllers;

use Mhc\Inc\Models\Patient;

class PatientsController{

    
    public function register(){

        //Patients
        add_action('wp_ajax_mhc_patients_list',   [$this, 'list']);
        add_action('wp_ajax_mhc_patients_create', [$this, 'create']);
        add_action('wp_ajax_mhc_patients_update', [$this, 'update']);
        add_action('wp_ajax_mhc_patients_delete', [$this, 'delete']);
        add_action('wp_ajax_mhc_patients_get',    [$this, 'getById']);

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
        $result = Patient::findAll($search, $page, $per_page);
        wp_send_json_success([
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $per_page,
        ]);
    }

    public static function create() {
        self::check();
        $data = [
            'first_name' => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name'  => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'is_active'  => intval($_POST['is_active'] ?? 1),
        ];
        if ($data['first_name'] === '' || $data['last_name'] === '') {
            wp_send_json_error(['message' => 'First and last name are required'], 400);
        }

        $assignments = [];
        if (isset($_POST['assignments'])) {
            $json = wp_unslash($_POST['assignments']);
            $parsed = json_decode($json, true);
            if (is_array($parsed)) $assignments = $parsed;
        }

        $item = Patient::create($data);
        if (!$item) wp_send_json_error(['message' => 'DB error creating patient'], 500);

        // Persist worker-patient-role rows
        if (!empty($assignments)) {
            $ok = Patient::assignWorkers((int)$item['id'], $assignments);
            if (!$ok) {
                wp_send_json_error(['message' => 'Error saving assignments'], 500);
            }
            // REFRESH patient object to include assignments
            $item = Patient::findById((int)$item['id']);
        }

        wp_send_json_success(['item' => $item]);
    }

    public static function update() {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $data = [];
        if (isset($_POST['first_name'])) $data['first_name'] = sanitize_text_field(wp_unslash($_POST['first_name']));
        if (isset($_POST['last_name']))  $data['last_name']  = sanitize_text_field(wp_unslash($_POST['last_name']));
        if (isset($_POST['is_active'])) {
            $st = intval($_POST['is_active']);
            if (!in_array($st, [1,0], true)) $st = 1;
            $data['is_active'] = $st;
        }
        // Recibir assignments si existen
        $assignments = [];
        if (isset($_POST['assignments'])) {
            $json = wp_unslash($_POST['assignments']);
            $parsed = json_decode($json, true);
            if (is_array($parsed)) $assignments = $parsed;
        }
        if (!empty($assignments)) {
            $data['assignments'] = $assignments;
        }
        if (!$data) wp_send_json_error(['message' => 'Nothing to update'], 400);
        $item = Patient::update($id, $data);
        if (!$item) wp_send_json_error(['message' => 'DB error updating patient'], 500);
        wp_send_json_success(['item' => $item]);
    }

    public static function delete() {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $ok = Patient::delete($id);
        if (!$ok) wp_send_json_error(['message' => 'Unable to delete patient'], 500);
        wp_send_json_success(['id' => $id]);
    }

    public static function getById() {
        self::check();
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $item = Patient::findById($id);
        if (!$item) wp_send_json_error(['message' => 'Patient not found'], 404);
        wp_send_json_success(['item' => $item]);
    }
}   