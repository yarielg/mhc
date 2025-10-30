<?php

namespace Mhc\Inc\Controllers;

defined('ABSPATH') || exit;

class QuickBooksController
{
    public function register()
    {
        // Callback del OAuth2
        add_action('init', [$this, 'mhc_register_callback_route']);

        // Ensure queue tables exist
        add_action('admin_init', [\Mhc\Inc\Services\QbQueue::class, 'maybe_create_tables']);

        // Add custom cron schedule (5 minutes)
        add_filter('cron_schedules', function ($schedules) {
            if (!isset($schedules['five_minutes'])) {
                $schedules['five_minutes'] = ['interval' => 300, 'display' => __('Every Five Minutes')];
            }
            return $schedules;
        });

        // Cron handler
        add_action('mhc_qb_process_queue_cron', [\Mhc\Inc\Services\QbQueue::class, 'processQueue']);

        add_action('wp_ajax_mhc_qb_create_check', [$this, 'ajax_create_check_for_worker']);
        add_action('wp_ajax_mhc_qb_create_all_checks', [$this, 'ajax_all_checks_qb']);
        // Queue endpoints
        add_action('wp_ajax_mhc_qb_enqueue_payroll', [__CLASS__, 'ajax_enqueue_payroll']);
        add_action('wp_ajax_mhc_qb_process_queue', [__CLASS__, 'ajax_process_queue']);
        add_action('wp_ajax_mhc_qb_list_checks', [__CLASS__, 'ajax_list_checks']);

        add_action('wp_ajax_mhc_qb_sync_check_numbers', [__CLASS__, 'ajax_sync_check_numbers']);
        add_action('wp_ajax_mhc_qb_delete_check', [$this, 'ajax_delete_check_for_worker']);
    }

    /**
     * Checks AJAX access for logged-in users only.
     * Calls mhc_check_ajax_access() defined in util/helpers.php.
     *
     * @return void
     */
    private static function check()
    {
        if (!function_exists('mhc_check_ajax_access')) {
            require_once dirname(__DIR__, 2) . '/util/helpers.php';
        }
        mhc_check_ajax_access();
    }

    /**
     * AJAX: delete a locally recorded QuickBooks check (optionally attempts no remote action).
     * POST: qb_check_id, payroll_id (optional), worker_id (optional)
     */
    public function ajax_delete_check_for_worker()
    {
        self::check();

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $qb_check_id = isset($_POST['qb_check_id']) ? sanitize_text_field(wp_unslash($_POST['qb_check_id'])) : '';
        if (empty($qb_check_id)) wp_send_json_error(['message' => 'qb_check_id required'], 400);

        global $wpdb;
        $pfx = $wpdb->prefix;

        // Remove local record(s)
        $deleted = $wpdb->delete("{$pfx}mhc_qb_checks", ['qb_check_id' => $qb_check_id]);

        if ($deleted !== false) {
            wp_send_json_success(['message' => 'Check removed locally', 'deleted' => (int)$deleted]);
        }

        wp_send_json_error(['message' => 'Could not delete local check'], 500);
    }

    public function mhc_register_callback_route()
    {
        // Verificamos si estamos en la URL /qb/callback
        $path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        //check if the path contains 'qb/callback'
        if (strpos($path, 'qb/callback') === false) return;

        // Parámetros enviados por QuickBooks
        $code = isset($_GET['code']) ? sanitize_text_field($_GET['code']) : '';
        $realm_id = isset($_GET['realmId']) ? sanitize_text_field($_GET['realmId']) : '';
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';

        // Validación mínima
        if (empty($code) || empty($realm_id)) {
            wp_die(__('Invalid QuickBooks authorization response.', 'mhc'));
        }

        // Obtenemos credenciales guardadas en ajustes
        $client_id = get_option('mhc_qb_client_id');
        $client_secret = get_option('mhc_qb_client_secret');
        $redirect_uri = home_url('/qb/callback');

        // Endpoint oficial para intercambio de tokens
        $url = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ];
        $body = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirect_uri,
        ];

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => http_build_query($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            wp_die('QuickBooks connection failed: ' . $response->get_error_message());
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['access_token'])) {
            wp_die('QuickBooks did not return valid tokens. Response: ' . wp_remote_retrieve_body($response));
        }

        // Guardamos tokens y realm ID
        update_option('mhc_qb_access_token', $data['access_token']);
        update_option('mhc_qb_refresh_token', $data['refresh_token']);
        update_option('mhc_qb_realm_id', $realm_id);

        // Mensaje de confirmación
        wp_redirect(admin_url('admin.php?page=mhc_quickbooks_settings&connected=1'));
        exit;
    }

    /**
     * 🔹 Lógica central reutilizable: crea un cheque en QuickBooks
     */
    public function create_check_for_worker($worker_id, $wpr_id, $payroll_id, $total, $period_start, $period_end, $payroll_print_date = null)
    {
        global $wpdb;

        //get checking account and expense account from settings
        $checking_account_id = get_option('mhc_qb_checking_account_id');
        $expense_account_id = get_option('mhc_qb_expense_account_id');

        if (is_null($payroll_print_date) || empty($payroll_print_date)) {
            $payroll_print_date = date('Y-m-d');
        }

        $table_workers = $wpdb->prefix . 'mhc_workers';
        $worker = $wpdb->get_row($wpdb->prepare(
            "SELECT id, qb_vendor_id, company FROM $table_workers WHERE id = %d",
            $worker_id
        ));

        if (!$worker || empty($worker->qb_vendor_id)) {
            return new \WP_Error('no_vendor', "Worker {$worker_id} is not linked to a QuickBooks Vendor.");
        }

        $date = self::format_week_range($period_start, $period_end);
        $qb = new \Mhc\Inc\Services\QuickBooksService();
        // To avoid creating duplicate checks in QuickBooks when multiple concurrent
        // requests run, decide whether to perform vendor-based deduplication (if
        // the local table contains a qb_vendor_id column). We'll acquire an
        // advisory lock per payroll+vendor (preferred) or payroll+worker.
        $checks_table = $wpdb->prefix . 'mhc_qb_checks';
        $has_vendor_col = !empty($wpdb->get_results("SHOW COLUMNS FROM {$checks_table} LIKE 'qb_vendor_id'"));
        $vendor_id = isset($worker->qb_vendor_id) ? trim((string)$worker->qb_vendor_id) : '';

        // Acquire lock (timeout 5s). Use vendor-based lock when available and vendor id present.
        if ($has_vendor_col && $vendor_id !== '') {
            $lock_name = 'mhc_qb_create_check_vendor_' . md5($payroll_id . '_' . $vendor_id.''.$worker_id);
        } else {
            $lock_name = 'mhc_qb_create_check_' . intval($payroll_id) . '_' . intval($worker_id);
        }
        $got_lock = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lock_name, 5));
        if (!$got_lock) {
            \Mhc\Inc\Services\QbLogger::error('lock_failed_acquire', ['payroll_id' => $payroll_id, 'worker_id' => $worker_id, 'wpr_id' => $wpr_id, 'vendor_id' => $vendor_id, 'lock' => $lock_name]);
            return new \WP_Error('lock_failed', 'Could not acquire lock to create check. Try again shortly.');
        }

        try {
            // Re-check local existence. If vendor-based dedupe is available and vendor id is present,
            // check by payroll+vendor. Otherwise fall back to worker/wpr checks (including amount where used).
            $exists = false;
            if ($has_vendor_col && $vendor_id !== '') {
                $exists = (bool) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$checks_table}
                    WHERE payroll_id = %d AND qb_vendor_id = %s AND worker_id = %d",
                    $payroll_id, $vendor_id, $worker_id
                ));
            } else {
                //if not have vendor then return error because we cannot sent to QB without vendor
                \Mhc\Inc\Services\QbLogger::error('no_vendor', ['payroll_id' => $payroll_id, 'worker_id' => $worker_id, 'wpr_id' => $wpr_id]);
                return new \WP_Error('no_vendor', "Worker {$worker_id} is not linked to a QuickBooks Vendor.");
            }

            if ($exists) {
                \Mhc\Inc\Services\QbLogger::info('exists_local', ['payroll_id' => $payroll_id, 'worker_id' => $worker_id, 'wpr_id' => $wpr_id, 'vendor_id' => $vendor_id]);
                return new \WP_Error('exists', 'A check for this payroll/worker/vendor already exists locally.');
            }

            $body = [
                "PaymentType" => "Check",
                "AccountRef" => [
                    "value" => $checking_account_id, // Checking account ID (ajusta según tu QuickBooks)
                    "name" => "Checking Account"
                ],
                "EntityRef" => [
                    "type"  => "Vendor",
                    "value" => $worker->qb_vendor_id,
                    "name"  => $worker->company
                ],
                "TotalAmt" => round($total, 2),
                "TxnDate"  => $payroll_print_date,
                "PrintStatus" => "NeedToPrint",
                "PrivateNote" => "Payroll period {$date}",
                "Line" => [
                    [
                        "Amount" => round($total, 2),
                        "DetailType" => "AccountBasedExpenseLineDetail",
                        "AccountBasedExpenseLineDetail" => [
                            "AccountRef" => [
                                "value" => $expense_account_id, // Expense account ID (ajusta según tu QuickBooks)
                                "name" => "Contractor Payments"
                            ]
                        ],
                        "Description" => "Payroll period {$date}"
                    ]
                ]
            ];

            $response = $qb->request('POST', 'purchase?minorversion=75', $body);

            if (is_wp_error($response)) {
                // Log remote creation error
                \Mhc\Inc\Services\QbLogger::error('qb_request_failed', ['payroll_id' => $payroll_id, 'worker_id' => $worker_id, 'vendor_id' => $vendor_id, 'error' => $response->get_error_message()]);
                // release lock before returning
                $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
                return $response;
            }

            if (empty($response['Purchase']['Id'])) {
                \Mhc\Inc\Services\QbLogger::error('qb_no_id', ['payroll_id' => $payroll_id, 'worker_id' => $worker_id, 'vendor_id' => $vendor_id, 'response' => $response]);
                // release lock before returning
                $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
                return new \WP_Error('creation_failed', 'QuickBooks did not return a valid Check ID.');
            }

            $check_id = $response['Purchase']['Id'];

            // Guardar opcionalmente el último cheque en BD
            $row = [
                'payroll_id' => $payroll_id,
                'worker_id'  => $worker_id,
                'worker_patient_role_id' => $wpr_id,
                'qb_check_id' => $check_id,
                'amount' => $total,
            ];

            if (!empty($has_vendor_col) && $vendor_id !== '') {
                $row['qb_vendor_id'] = $vendor_id;
            }

            $inserted = $wpdb->insert("{$wpdb->prefix}mhc_qb_checks", $row);

            if ($inserted === false) {
                // The remote check was created but we failed to persist locally. That's
                // a bad state; return an informative error. We intentionally do not
                // attempt to delete the remote check here to avoid accidental data loss.
                \Mhc\Inc\Services\QbLogger::error('db_insert_failed', ['payroll_id' => $payroll_id, 'worker_id' => $worker_id, 'wpr_id' => $wpr_id, 'vendor_id' => $vendor_id, 'qb_check_id' => $check_id, 'amount' => $total]);
                return new \WP_Error('db_insert_failed', 'Check created in QuickBooks but failed to record locally.');
            }

            // Successful creation: log and return
            \Mhc\Inc\Services\QbLogger::logCheckCreated([
                'payroll_id' => $payroll_id,
                'worker_id' => $worker_id,
                'wpr_id' => $wpr_id,
                'vendor_id' => $vendor_id,
                'qb_check_id' => $check_id,
                'amount' => $total,
                'lock' => $lock_name,
            ]);

            return [
                'check_id' => $check_id,
                'vendor_name' => $worker->company,
                'total' => $total
            ];
        } finally {
            // Always release the lock
            $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
        }
    }

    /**
     * 🔹 AJAX para crear cheque individual
     */
    public function ajax_create_check_for_worker()
    {
        self::check();

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $worker_id    = intval($_POST['worker_id'] ?? 0);
        $wpr_id       = intval($_POST['wpr_id'] ?? 0);
        $payroll_id   = intval($_POST['payroll_id'] ?? 0);
        $total        = floatval($_POST['total'] ?? 0);
        $period_start = sanitize_text_field($_POST['period_start'] ?? '');
        $period_end   = sanitize_text_field($_POST['period_end'] ?? '');
        $payroll_print_date = sanitize_text_field($_POST['payroll_print_date'] ?? '');

        if (!$worker_id || !$total) {
            wp_send_json_error(['message' => 'Missing parameters'], 400);
        }

        $result = $this->create_check_for_worker($worker_id, $wpr_id, $payroll_id, $total, $period_start, $period_end, $payroll_print_date);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => '✅ Check created successfully',
            'check_id' => $result['check_id'],
            'vendor' => $result['vendor_name'],
            'amount' => $result['total']
        ]);
    }

    public static function ajax_all_checks_qb()
    {
        self::check(); // tu método de seguridad AJAX
        global $wpdb;

        $payrollId = intval($_GET['payroll_id'] ?? 0);
        if (!$payrollId) {
            wp_send_json_error(['message' => 'Missing payroll_id']);
        }

        // === Buscar todos los trabajadores asociados a este payroll ===
        $workers = [];
        $worker_ids = [];

        // 1️⃣ Workers con horas
        $res1 = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT w.id, CONCAT(w.first_name, ' ', w.last_name) AS worker_name, wpr.id AS worker_patient_role_id, w.qb_vendor_id AS qb_vendor_id
            FROM {$wpdb->prefix}mhc_hours_entries he
            INNER JOIN {$wpdb->prefix}mhc_worker_patient_roles wpr ON wpr.id = he.worker_patient_role_id
            INNER JOIN {$wpdb->prefix}mhc_workers w ON w.id = wpr.worker_id
            INNER JOIN {$wpdb->prefix}mhc_payroll_segments seg ON seg.id = he.segment_id
            WHERE seg.payroll_id = %d",
                $payrollId
            )
        );
        foreach ($res1 as $w) {
            $workers[] = $w;
            $worker_ids[$w->id] = true;
        }

        // 2️⃣ Workers con extras
        $res2 = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DISTINCT w.id, CONCAT(w.first_name, ' ', w.last_name) AS worker_name, w.qb_vendor_id AS qb_vendor_id
            FROM {$wpdb->prefix}mhc_extra_payments ep
            INNER JOIN {$wpdb->prefix}mhc_workers w ON w.id = ep.worker_id
            WHERE ep.payroll_id = %d",
                $payrollId
            )
        );
        foreach ($res2 as $w) {
            if (!isset($worker_ids[$w->id])) {
                $workers[] = $w;
                $worker_ids[$w->id] = true;
            }
        }

        if (!$workers) {
            wp_send_json_error(['message' => 'No workers found for this payroll']);
        }

        // === Traer info de payroll (fechas, etc.) ===
        $payroll = \Mhc\Inc\Models\Payroll::findById($payrollId);
        $start = $payroll->start_date ?? date('Y-m-d');
        $end   = $payroll->end_date ?? date('Y-m-d');
        $print_date = $payroll->payroll_print_date ?? date('Y-m-d');

        // Check whether local checks table contains a qb_vendor_id column. If so,
        // we'll use vendor-based deduplication and locking to avoid multiple
        // checks for the same QuickBooks vendor for a payroll.
        $checks_table = $wpdb->prefix . 'mhc_qb_checks';
        $has_vendor_col = !empty($wpdb->get_results("SHOW COLUMNS FROM {$checks_table} LIKE 'qb_vendor_id'"));

        // === Instanciar el servicio QuickBooks ===
        $qb = new \Mhc\Inc\Services\QuickBooksService();
        $controller = new self(); // Para usar create_check_for_worker

        $created = [];
        $errors  = [];

        foreach ($workers as $worker) {
            // Totales de este worker (igual que en el PDF)
            $hours  = \Mhc\Inc\Models\HoursEntry::listDetailedForPayroll($payrollId, ['worker_id' => $worker->id]);
            $extras = \Mhc\Inc\Models\ExtraPayment::listDetailedForPayroll($payrollId, ['worker_id' => $worker->id]);

            $ta = 0.0;
            $te = 0.0;
            foreach ($hours as $h) $ta += (float)$h->total;
            foreach ($extras as $e) $te += (float)$e->amount;

            $grand_total = round($ta + $te, 2);
            if ($grand_total <= 0) continue; // omitimos si no tiene pago
            $wpr_id = isset($worker->worker_patient_role_id) ? $worker->worker_patient_role_id : 0;
            $worker_qb_vendor = isset($worker->qb_vendor_id) ? trim((string)$worker->qb_vendor_id) : '';

            // Verificar si ya existe un cheque para este worker/payroll o por vendor (si la columna existe)
            if ($has_vendor_col && $worker_qb_vendor !== '') {
                // Prefer vendor-based dedupe to avoid multiple checks for same vendor
                $existing_check = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT * FROM {$checks_table} WHERE payroll_id = %d AND qb_vendor_id = %s",
                        $payrollId,
                        $worker_qb_vendor
                    )
                );
            } else {
                // We no longer perform worker-based deduplication when qb_vendor_id
                // deduplication is enabled — the caller will validate by vendor_id.
                $existing_check = null;
            }
            // Crear cheque
            if ($existing_check) {
                $errors[] = [
                    'worker' => $worker->worker_name,
                    'error' => 'Check already exists for this worker and payroll.'
                ];
                continue;
            } else {
                // No existe, procedemos a crear
                $result = $controller->create_check_for_worker($worker->id, $wpr_id, $payrollId, $grand_total, $start, $end, $print_date);
            }

            if (is_wp_error($result)) {
                $errors[] = [
                    'worker' => $worker->worker_name,
                    'error' => $result->get_error_message()
                ];
            } else {
                $created[] = [
                    'worker' => $worker->worker_name,
                    'vendor' => $result['vendor_name'],
                    'check_id' => $result['check_id'],
                    'amount' => $grand_total
                ];
            }
        }

        wp_send_json_success([
            'message' => sprintf('✅ %d checks created successfully', count($created)),
            'created' => $created,
            'errors'  => $errors
        ]);
    }

    /**
     * AJAX: enqueue all workers for a payroll
     * POST: payroll_id
     */
    public static function ajax_enqueue_payroll()
    {
        self::check();
        $payroll_id = isset($_POST['payroll_id']) ? (int)$_POST['payroll_id'] : 0;
        if ($payroll_id <= 0) wp_send_json_error(['message' => 'payroll_id required'], 400);

        $res = \Mhc\Inc\Services\QbQueue::enqueuePayroll($payroll_id);
        wp_send_json_success($res);
    }

    /**
     * AJAX: process queue (limited number)
     * POST: limit (optional)
     */
    public static function ajax_process_queue()
    {
        // Allow external scheduled callers to pass a process_key in POST to force processing
        $limit = isset($_POST['limit']) ? max(1, (int)$_POST['limit']) : 5;
        $process_key = isset($_POST['process_key']) ? sanitize_text_field($_POST['process_key']) : '';
        $stored_key = get_option('mhc_qb_process_key', '');

        if ($process_key && hash_equals((string)$stored_key, (string)$process_key)) {
            // trusted external caller
            $res = \Mhc\Inc\Services\QbQueue::processQueue($limit, true);
            wp_send_json_success($res);
        }

        // normal internal path: require ajax security check
        self::check();
        $res = \Mhc\Inc\Services\QbQueue::processQueue($limit, false);
        wp_send_json_success($res);
    }

    /**
     * AJAX: list checks and queue rows for a payroll
     * GET/POST: payroll_id, worker_id (optional)
     */
    public static function ajax_list_checks()
    {
        self::check();
        global $wpdb;
        $payroll_id = isset($_REQUEST['payroll_id']) ? (int)$_REQUEST['payroll_id'] : 0;
        $worker_id = isset($_REQUEST['worker_id']) ? (int)$_REQUEST['worker_id'] : 0;
        if ($payroll_id <= 0) wp_send_json_error(['message' => 'payroll_id required'], 400);

        $pfx = $wpdb->prefix;
        $where = $worker_id > 0 ? $wpdb->prepare(' AND worker_id=%d', $worker_id) : '';

        $checks = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$pfx}mhc_qb_checks WHERE payroll_id = %d {$where} ORDER BY created_at DESC", $payroll_id));
        $queue = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$pfx}mhc_qb_queue WHERE payroll_id = %d {$where} ORDER BY created_at DESC", $payroll_id));

        wp_send_json_success(['checks' => $checks, 'queue' => $queue]);
    }

    public static function format_week_range($start, $end)
    {
        if (!$start || !$end) return '—';
        $s = date_create($start);
        $e = date_create($end);
        if (!$s || !$e) return $start . ' – ' . $end;
        $sm = date_format($s, 'M');
        $em = date_format($e, 'M');
        $sy = date_format($s, 'Y');
        $ey = date_format($e, 'Y');
        $sd = date_format($s, 'j');
        $ed = date_format($e, 'j');
        $dash = '–';
        if ($s == $e) {
            return "$sm $sd, $sy";
        }
        if ($sm === $em && $sy === $ey) {
            return "$sm $sd$dash$ed, $sy";
        }
        if ($sy === $ey) {
            return "$sm $sd$dash$em $ed, $sy";
        }
        return "$sm $sd, $sy$dash$em $ed, $ey";
    }

    public static function ajax_sync_check_numbers()
    {
        self::check();
        global $wpdb;

        // Expect payroll_id so we only sync relevant local checks
        $payroll_id = isset($_REQUEST['payroll_id']) ? (int) $_REQUEST['payroll_id'] : 0;
        if ($payroll_id <= 0) {
            wp_send_json_error(['message' => 'payroll_id required'], 400);
        }

        $pfx = $wpdb->prefix;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT qb_check_id FROM {$pfx}mhc_qb_checks WHERE payroll_id = %d AND check_number is null AND qb_check_id <> ''", $payroll_id));

        if (empty($rows)) {
            wp_send_json_success(['message' => 'No local checks found for this payroll', 'total_local' => 0, 'updated' => 0]);
        }

        $ids = array_values(array_filter(array_map(function ($r) {
            return is_object($r) ? trim((string) $r->qb_check_id) : trim((string) $r);
        }, $rows)));

        $ids = array_values(array_unique($ids));
        $total_local = count($ids);

        $qb = new \Mhc\Inc\Services\QuickBooksService();
        $updated = 0;
        $not_found = [];

        // QuickBooks query can accept multiple Ids with IN, but chunk to be safe
        $chunk_size = 30;
        $chunks = array_chunk($ids, $chunk_size);

        foreach ($chunks as $chunk) {
            // Build IN list, escaping IDs
            $in = implode(',', array_map(function ($id) {
                return "'" . esc_sql($id) . "'";
            }, $chunk));

            $query = "select Id, DocNumber from Purchase where Id IN ({$in})";
            $endpoint = 'query?query=' . urlencode($query) . '&minorversion=75';

            $response = $qb->request('GET', $endpoint);
            if (is_wp_error($response)) {
                //log error
                \Mhc\Inc\Services\QbLogger::error('qb_request_failed_sync_checks', ['payroll_id' => $payroll_id, 'error' => $response->get_error_message()]);
                wp_send_json_error(['message' => $response->get_error_message()]);
            }

            $purchases = $response['QueryResponse']['Purchase'] ?? [];

            // Normalize to array of purchases
            if ($purchases && isset($purchases['Id'])) {
                // single object
                $purchases = [$purchases];
            }

            $found_ids = [];
            foreach ($purchases as $p) {
                $check_id = isset($p['Id']) ? (string) $p['Id'] : '';
                $check_num = isset($p['DocNumber']) ? (string) $p['DocNumber'] : '';
                if (!$check_id) continue;
                $found_ids[] = $check_id;

                // Only update if we have a document number
                if ($check_num !== '') {
                    $wpdb->update("{$pfx}mhc_qb_checks", ['check_number' => esc_sql($check_num)], ['qb_check_id' => esc_sql($check_id)]);
                    $updated++;
                }
            }

            // track not found ids in this chunk
            foreach ($chunk as $cid) {
                if (!in_array($cid, $found_ids, true)) $not_found[] = $cid;
            }
        }

        wp_send_json_success([
            'message' => "✅ {$updated} checks synchronized successfully.",
            'total_local' => $total_local,
            'updated' => $updated,
            'not_found' => array_values(array_unique($not_found)),
        ]);
    }

    //validate that the wpr_id for this payroll_id and the amount is not already created in the checks table
    public static function validate_check_exists($payroll_id, $worker_patient_role_id, $amount)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mhc_qb_checks';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE payroll_id = %d AND worker_patient_role_id = %d AND amount = %f",
            $payroll_id,
            $worker_patient_role_id,
            $amount
        ));

        return $existing > 0;
    }

    //remove check by payroll_id and worker_patient_role_id and amount
    public static function remove_check($payroll_id, $worker_patient_role_id, $amount)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'mhc_qb_checks';

        $deleted = $wpdb->delete(
            $table,
            [
                'payroll_id' => $payroll_id,
                'worker_patient_role_id' => $worker_patient_role_id,
                'amount' => $amount
            ]
        );

        return $deleted !== false;
    }
}
