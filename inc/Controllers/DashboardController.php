<?php

namespace Mhc\Inc\Controllers;

class DashboardController
{

    /** Register AJAX routes */
    public function register()
    {
        add_action('wp_ajax_mhc_dashboard_get',   [$this, 'get']);


        // === ADD inside register() ===
        add_action('wp_ajax_mhc_dashboard_metrics', [__CLASS__, 'ajax_dashboard_metrics']);
        // If you need the charts for non-admin logged-in users, optionally expose nopriv:
        add_action('wp_ajax_nopriv_mhc_dashboard_metrics', [__CLASS__, 'ajax_dashboard_metrics']);

        // Localize a nonce for the admin script that runs Dashboard.vue
        add_action('admin_enqueue_scripts', [__CLASS__, 'localize_dashboard_nonce']);
    }

    /** Core handler: aggregated dashboard payload */
    public function get()
    {
        self::check();

        $payload = [
            'generated_at' => current_time('mysql'),
            'stats'        => $this->stats(),
            'quick'        => $this->quick(),
            'recent'       => $this->recent(),
        ];

        wp_send_json_success($payload);
    }

    /** Security */
    protected static function check()
    {
        if (!function_exists('mhc_check_ajax_access')) {
            require_once dirname(__DIR__, 2) . '/util/helpers.php';
        }
        mhc_check_ajax_access();
    }

    /** Helpers */
    protected function tableExists($table)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        return $exists === $table;
    }

    protected function getColumns($table)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A) ?: [];
        return array_map(static fn($r) => $r['Field'], $rows);
    }

    /** Stats for cards (totals + 60d window) */
    protected function stats()
    {
        global $wpdb;
        $pfx = $wpdb->prefix;

        $tables = [
            'roles'        => "{$pfx}mhc_roles",
            'workers'      => "{$pfx}mhc_workers",
            'patients'     => "{$pfx}mhc_patients",
            'assignments'  => "{$pfx}mhc_patient_workers",
            'payrolls'     => "{$pfx}mhc_payrolls",
            'segments'     => "{$pfx}mhc_payroll_segments",
            'hours'        => "{$pfx}mhc_hours_entries",
            'extras'       => "{$pfx}mhc_extra_payments",
        ];

        $out = [
            'roles'        => ['total' => 0, 'active' => 0],
            'workers'      => ['total' => 0, 'active' => 0],
            'patients'     => ['total' => 0, 'active' => 0],
            'assignments'  => 0,
            'payrolls_60d' => ['count' => 0, 'total_paid' => 0.0],
        ];

        // Roles
        if ($this->tableExists($tables['roles'])) {
            $out['roles']['total']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['roles']}");
            $out['roles']['active'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['roles']} WHERE is_active=1");
        }

        // Workers
        if ($this->tableExists($tables['workers'])) {
            $out['workers']['total']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['workers']}");
            $out['workers']['active'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['workers']} WHERE is_active=1");
        }

        // Patients
        if ($this->tableExists($tables['patients'])) {
            $out['patients']['total']  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['patients']}");
            $hasActive = $wpdb->get_row("SHOW COLUMNS FROM {$tables['patients']} LIKE 'is_active'");
            if ($hasActive) {
                $out['patients']['active'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['patients']} WHERE is_active=1");
            }
        }

        // Assignments
        if ($this->tableExists($tables['assignments'])) {
            $out['assignments'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tables['assignments']}");
        }

        // Payroll últimos 60 días
        if ($this->tableExists($tables['payrolls'])) {
            $out['payrolls_60d']['count'] = (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$tables['payrolls']}
            WHERE start_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
        ");

            // Totales de horas + extras en esos payrolls
            $sumHours = 0.0;
            if ($this->tableExists($tables['hours']) && $this->tableExists($tables['segments'])) {
                $sumHours = (float) $wpdb->get_var("
                SELECT COALESCE(SUM(h.total),0)
                FROM {$tables['hours']} h
                JOIN {$tables['segments']} s ON s.id = h.segment_id
                JOIN {$tables['payrolls']} p ON p.id = s.payroll_id
                WHERE p.start_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
            ");
            }

            $sumExtras = 0.0;
            if ($this->tableExists($tables['extras'])) {
                $sumExtras = (float) $wpdb->get_var("
                SELECT COALESCE(SUM(e.amount),0)
                FROM {$tables['extras']} e
                JOIN {$tables['payrolls']} p ON p.id = e.payroll_id
                WHERE p.start_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
            ");
            }

            $out['payrolls_60d']['total_paid'] = $sumHours + $sumExtras;
        }

        return $out;
    }




    /** Quick metrics (cards) */
    protected function quick()
    {
        global $wpdb;
        $pfx = $wpdb->prefix;

        $payrolls = "{$pfx}mhc_payrolls";
        $hours    = "{$pfx}mhc_hours_entries";
        $extras   = "{$pfx}mhc_extra_payments";

        $out = ['hours_14d' => 0, 'neg_count' => 0];

        $haveHours  = $this->tableExists($hours);
        $haveExtras = $this->tableExists($extras);

        if ($haveHours) {
            if ($this->tableExists($payrolls)) {
                // Hours in the most recent 14 days window of *calendar time* (fallback to created_at if needed)
                // Using created_at to avoid depending on a work_date column.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $out['hours_14d'] = (float) $wpdb->get_var("
                    SELECT COALESCE(SUM(h.hours),0)
                    FROM {$hours} h
                    WHERE h.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                ");
            } else {
                // No payrolls table, same query anyway
                $out['hours_14d'] = (float) $wpdb->get_var("
                    SELECT COALESCE(SUM(h.hours),0)
                    FROM {$hours} h
                    WHERE h.created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                ");
            }
        }

        if ($haveExtras) {
            // Negative count in last 14 days
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $out['neg_count'] = (int) $wpdb->get_var("
                SELECT COUNT(*) FROM {$extras}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND amount < 0
            ");
        }

        return $out;
    }

    /** Recent payrolls list (latest 5 with counts/totals) */
    protected function recent()
    {
        global $wpdb;
        $pfx = $wpdb->prefix;

        $payrolls = "{$pfx}mhc_payrolls";
        $segments = "{$pfx}mhc_payroll_segments";
        $hours    = "{$pfx}mhc_hours_entries";
        $extras   = "{$pfx}mhc_extra_payments";
        $out      = ['payrolls' => []];

        if (!$this->tableExists($payrolls)) return $out;

        // Get last 5 payrolls
        $rows = $wpdb->get_results("
        SELECT id, start_date, end_date, status
        FROM {$payrolls}
        ORDER BY start_date DESC
        LIMIT 5
    ", ARRAY_A) ?: [];

        if (!$rows) return $out;

        $haveHours  = $this->tableExists($hours) && $this->tableExists($segments);
        $haveExtras = $this->tableExists($extras);

        foreach ($rows as $r) {
            $pid = (int) $r['id'];
            $payload = [
                'id'         => $pid,
                'start_date' => $r['start_date'],
                'end_date'   => $r['end_date'],
                'status'     => $r['status'],
                'items'      => 0,
                'total'      => 0.0,
            ];

            $cnt = 0;
            $sum = 0.0;

            if ($haveHours) {
                // Conteo de horas por payroll vía segments
                $cnt += (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$hours} h
                JOIN {$segments} s ON s.id = h.segment_id
                WHERE s.payroll_id = %d
            ", $pid));

                // Suma de horas por payroll vía segments
                $sum += (float) $wpdb->get_var($wpdb->prepare("
                SELECT COALESCE(SUM(h.total),0)
                FROM {$hours} h
                JOIN {$segments} s ON s.id = h.segment_id
                WHERE s.payroll_id = %d
            ", $pid));
            }

            if ($haveExtras) {
                $cnt += (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$extras} WHERE payroll_id = %d
            ", $pid));

                $sum += (float) $wpdb->get_var($wpdb->prepare("
                SELECT COALESCE(SUM(amount),0) FROM {$extras} WHERE payroll_id = %d
            ", $pid));
            }

            $payload['items'] = $cnt;
            $payload['total'] = $sum;

            $out['payrolls'][] = $payload;
        }

        return $out;
    }


    public static function localize_dashboard_nonce(): void
    {
        $handle = 'mhc-admin'; // change if your admin app uses a different script handle
        if (wp_script_is($handle, 'enqueued') || wp_script_is($handle, 'registered')) {
            wp_localize_script($handle, 'MHC_AJAX_NONCE', wp_create_nonce(self::NONCE_ACTION));
        } else {
            // Fallback: make it available anyway
            $nonce = wp_create_nonce(self::NONCE_ACTION);
            wp_add_inline_script('jquery-core', 'window.MHC_AJAX_NONCE = "' . $nonce . '";', 'before');
        }
    }

    public static function ajax_dashboard_metrics(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        self::check();

        global $wpdb;
        $pfx = $wpdb->prefix;

        $t_payrolls       = $pfx . 'mhc_payrolls';
        $t_segments       = $pfx . 'mhc_payroll_segments';
        $t_hours_entries  = $pfx . 'mhc_hours_entries';
        $t_extra_payments = $pfx . 'mhc_extra_payments';
        $t_wpr            = $pfx . 'mhc_worker_patient_roles';
        $t_workers        = $pfx . 'mhc_workers';
        $t_roles          = $pfx . 'mhc_roles';
        $t_special_rates  = $pfx . 'mhc_special_rates';

        // 1) Last 10 payroll totals
        $sql_payroll_totals = "
        SELECT p.id, p.start_date, p.end_date,
               COALESCE(SUM(he.total), 0) AS hours_total,
               COALESCE((SELECT SUM(ep.amount) 
                         FROM {$t_extra_payments} ep 
                         WHERE ep.payroll_id = p.id), 0) AS extras_total
        FROM {$t_payrolls} p
        LEFT JOIN {$t_segments} s ON s.payroll_id = p.id
        LEFT JOIN {$t_hours_entries} he ON he.segment_id = s.id
        GROUP BY p.id, p.start_date, p.end_date
        ORDER BY p.id DESC
        LIMIT 10
    ";
        $rows_totals = $wpdb->get_results($sql_payroll_totals, ARRAY_A) ?: [];

        // 2) Stacked totals by role for those payrolls
        $sql_role_totals = "
        SELECT p.id AS payroll_id, r.code AS role_code, COALESCE(SUM(he.total), 0) AS role_total
        FROM {$t_hours_entries} he
        JOIN {$t_segments} s ON s.id = he.segment_id
        JOIN {$t_payrolls} p ON p.id = s.payroll_id
        JOIN {$t_wpr} wpr ON wpr.id = he.worker_patient_role_id
        JOIN {$t_roles} r ON r.id = wpr.role_id
        JOIN (SELECT id FROM {$t_payrolls} ORDER BY id DESC LIMIT 10) last_p ON last_p.id = p.id
        GROUP BY p.id, r.code
    ";
        $rows_role_totals = $wpdb->get_results($sql_role_totals, ARRAY_A) ?: [];

        // 3) Top 5 workers (latest payroll)
        $latest_payroll_id = (int) $wpdb->get_var("SELECT id FROM {$t_payrolls} ORDER BY id DESC LIMIT 1");
        $rows_top_workers = [];
        if ($latest_payroll_id) {
            $sql_top_workers = $wpdb->prepare("
            SELECT w.id AS worker_id,
                   CONCAT(w.first_name, ' ', w.last_name) AS worker_name,
                   COALESCE(SUM(he.hours), 0) AS hours
            FROM {$t_hours_entries} he
            JOIN {$t_segments} s ON s.id = he.segment_id
            JOIN {$t_payrolls} p ON p.id = s.payroll_id
            JOIN {$t_wpr} wpr ON wpr.id = he.worker_patient_role_id
            JOIN {$t_workers} w ON w.id = wpr.worker_id
            WHERE p.id = %d
            GROUP BY w.id, w.first_name, w.last_name
            ORDER BY hours DESC
            LIMIT 5
        ", $latest_payroll_id);
            $rows_top_workers = $wpdb->get_results($sql_top_workers, ARRAY_A) ?: [];
        }

        // 4) Pending adjustments (+ / −) per payroll (last 10)
        $sql_pending = "
        SELECT p.id AS payroll_id,
               COALESCE(SUM(CASE WHEN ep.amount >= 0 THEN ep.amount ELSE 0 END), 0) AS pos_adjust,
               COALESCE(SUM(CASE WHEN ep.amount <  0 THEN ep.amount ELSE 0 END), 0) AS neg_adjust
        FROM {$t_payrolls} p
        LEFT JOIN {$t_extra_payments} ep ON ep.payroll_id = p.id
        GROUP BY p.id
        ORDER BY p.id DESC
        LIMIT 10
    ";
        $rows_pending = $wpdb->get_results($sql_pending, ARRAY_A) ?: [];

        // 5) Assessments (initial/reassessment) for last 10 payrolls
        $rows_assess = [];
        $exists_sr = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
            $t_special_rates
        ));
        if ($exists_sr) {
            $sql_assess = "
            SELECT ep.payroll_id, sr.code AS rate_code, COUNT(*) AS cnt, COALESCE(SUM(ep.amount), 0) AS total
            FROM {$t_extra_payments} ep
            JOIN {$t_special_rates} sr ON sr.id = ep.special_rate_id
            JOIN (SELECT id FROM {$t_payrolls} ORDER BY id DESC LIMIT 10) last_p ON last_p.id = ep.payroll_id
            WHERE sr.code IN ('initial_assessment','reassessment')
            GROUP BY ep.payroll_id, sr.code
        ";
            $rows_assess = $wpdb->get_results($sql_assess, ARRAY_A) ?: [];
        }

        wp_send_json_success([
            'payroll_totals'    => $rows_totals,
            'role_totals'       => $rows_role_totals,
            'top_workers'       => $rows_top_workers,
            'pending_adjust'    => $rows_pending,
            'assessments'       => $rows_assess,
            'latest_payroll_id' => $latest_payroll_id,
        ]);
    }
    // Nonce action for dashboard AJAX
    public const NONCE_ACTION = 'mhc_dashboard_ajax';
}
