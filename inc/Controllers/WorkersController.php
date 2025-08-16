<?php
/*
*
* @package Yariko
*
*/

namespace Mhc\Inc\Controllers;

use Mhc\Inc\Models\Worker;

class WorkersController
{


    public function register()
    {
        add_action('wp_ajax_mhc_workers_list',    [$this, 'list']);
        add_action('wp_ajax_mhc_workers_create',  [$this, 'create']);
        add_action('wp_ajax_mhc_workers_update',  [$this, 'update']);
        add_action('wp_ajax_mhc_workers_delete',  [$this, 'delete']);
        add_action('wp_ajax_mhc_workers_get',     [$this, 'getById']);

        // NEW: remote autocomplete for supervisors
        add_action('wp_ajax_mhc_workers_search',  [$this, 'search']);
        add_action('wp_ajax_mhc_workers_search_for_role',  [$this, 'searchForRole']);
        add_action('wp_ajax_mhc_worker_roles_by_worker', [$this, 'rolesForWorker']);
    }

    public static function getById()
    {
        self::check();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $item = Worker::findById($id);
        if (!$item) wp_send_json_error(['message' => 'Worker not found'], 404);
        wp_send_json_success(['item' => $item]);
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
        $page     = max(1, intval($_POST['page'] ?? 1));
        $per_page = min(100, max(1, intval($_POST['per_page'] ?? 10)));
        $search   = isset($_POST['search']) ? trim(wp_unslash($_POST['search'])) : '';
        $result   = Worker::findAll($search, $page, $per_page);
        wp_send_json_success([
            'items'     => $result['items'],
            'total'     => $result['total'],
            'page'      => $page,
            'per_page'  => $per_page,
        ]);
    }

    public static function create()
    {
        self::check();
        $data = [
            'first_name'   => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name'    => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'is_active'    => (int)($_POST['is_active'] ?? 1),
            'worker_roles' => isset($_POST['worker_roles']) ? wp_unslash($_POST['worker_roles']) : '[]',
        ];

        // NEW: supervisor_id (optional)
        if (isset($_POST['supervisor_id']) && $_POST['supervisor_id'] !== '') {
            $supId = (int)$_POST['supervisor_id'];
            if ($supId > 0 && !Worker::exists($supId)) {
                wp_send_json_error(['message' => 'Supervisor not found'], 400);
            }
            $data['supervisor_id'] = $supId > 0 ? $supId : null;
        } else {
            $data['supervisor_id'] = null;
        }

        if ($data['first_name'] === '' || $data['last_name'] === '') {
            wp_send_json_error(['message' => 'First and last name are required'], 400);
        }
        $item = Worker::create($data);
        if (!$item) wp_send_json_error(['message' => 'DB error creating worker'], 500);
        wp_send_json_success(['item' => $item]);
    }

    public static function update()
    {
        self::check();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);

        $data = [];
        if (isset($_POST['first_name'])) $data['first_name'] = sanitize_text_field(wp_unslash($_POST['first_name']));
        if (isset($_POST['last_name']))  $data['last_name']  = sanitize_text_field(wp_unslash($_POST['last_name']));
        if (isset($_POST['is_active']))  $data['is_active']  = (int)$_POST['is_active'] ? 1 : 0;
        $data['worker_roles'] = isset($_POST['worker_roles']) ? wp_unslash($_POST['worker_roles']) : '[]';

        // NEW: supervisor_id validation (cannot be self)
        if (array_key_exists('supervisor_id', $_POST)) {
            $raw = wp_unslash($_POST['supervisor_id']);
            if ($raw === '' || $raw === null) {
                $data['supervisor_id'] = null;
            } else {
                $supId = (int)$raw;
                if ($supId === $id) {
                    wp_send_json_error(['message' => 'A worker cannot supervise themselves'], 400);
                }
                if ($supId > 0 && !Worker::exists($supId)) {
                    wp_send_json_error(['message' => 'Supervisor not found'], 400);
                }
                $data['supervisor_id'] = $supId > 0 ? $supId : null;
            }
        }

        if (!$data) wp_send_json_error(['message' => 'Nothing to update'], 400);
        $item = Worker::update($id, $data);
        if (!$item) wp_send_json_error(['message' => 'DB error updating worker'], 500);
        wp_send_json_success(['item' => $item]);
    }

    public static function delete()
    {
        self::check();
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) wp_send_json_error(['message' => 'Invalid id'], 400);
        $ok = Worker::delete($id);
        if (!$ok) {
            wp_send_json_error(['message' => 'Unable to delete worker'], 500);
        }
        wp_send_json_success(['id' => $id]);
    }

    public static function rolesForWorker()
    {
        self::check();
        global $wpdb;
        $pfx = $wpdb->prefix;
        $wr = "{$pfx}mhc_worker_roles";
        $r  = "{$pfx}mhc_roles";

        $worker_id = intval($_POST['worker_id'] ?? 0);
        if ($worker_id <= 0) wp_send_json_error(['message' => 'Invalid worker_id'], 400);

        // Latest record per role for this worker
        $sql = "
      SELECT wr.role_id,
             r.name AS role_name,
             r.code AS role_code,
             wr.general_rate
      FROM $wr AS wr
      INNER JOIN (
        SELECT role_id, MAX(start_date) AS max_start
        FROM $wr
        WHERE worker_id = %d
        GROUP BY role_id
      ) AS latest
        ON latest.role_id = wr.role_id AND latest.max_start = wr.start_date
      LEFT JOIN $r AS r ON r.id = wr.role_id
      WHERE wr.worker_id = %d
      ORDER BY r.name ASC
    ";
        $roles = $wpdb->get_results($wpdb->prepare($sql, $worker_id, $worker_id), ARRAY_A);

        wp_send_json_success(['roles' => $roles]);
    }

    public static function searchForRole()
    {
        self::check();
        global $wpdb;
        $pfx = $wpdb->prefix;
        $table = "{$pfx}mhc_workers";

        $term = isset($_POST['term']) ? trim(wp_unslash($_POST['term'])) : '';
        $where = "WHERE 1=1";
        $params = [];

        if ($term !== '') {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $where .= " AND (first_name LIKE %s OR last_name LIKE %s OR CONCAT(first_name,' ',last_name) LIKE %s)";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT id, first_name, last_name, is_active FROM $table $where ORDER BY is_active DESC, last_name ASC, first_name ASC", $params),
            ARRAY_A
        );

        wp_send_json_success(['items' => $rows]);
    }

    // NEW: autocomplete for supervisors
    public static function search()
    {
        self::check();
        $q         = isset($_POST['q']) ? sanitize_text_field(wp_unslash($_POST['q'])) : '';
        $excludeId = (int)($_POST['exclude_id'] ?? 0);
        $items     = Worker::search($q, $excludeId, 10);
        wp_send_json_success(['items' => $items]);
    }
}
